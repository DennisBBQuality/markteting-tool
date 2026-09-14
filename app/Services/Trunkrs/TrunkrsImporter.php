<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TrunkrsImporter
{
    public function __construct(private TrunkrsReportParser $parser) {}

    public function accepts(array $message): bool
    {
        return strcasecmp($message['from']['emailAddress']['address'] ?? '', config('trunkrs.sender')) === 0
            && ($message['subject'] ?? '') === config('trunkrs.subject');
    }

    public function messageHash(string $id): string
    {
        return hash('sha256', config('trunkrs.mailbox').'|'.config('trunkrs.folder_id').'|'.$id);
    }

    public function import(array $message, string $bytes, string $filename): bool
    {
        if (! $this->accepts($message) || empty($message['id'])
            || ! is_string($message['receivedDateTime'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $message['receivedDateTime'])) {
            throw new TrunkrsException('invalid_report');
        }
        try {
            $received = CarbonImmutable::parse($message['receivedDateTime'])->utc();
        } catch (\Throwable) {
            throw new TrunkrsException('invalid_report');
        }
        $parsed = $this->parser->parse($bytes, $filename);
        if ($parsed['report_date'] && $parsed['report_date'] > $received->setTimezone(config('trunkrs.timezone'))->toDateString()) {
            throw new TrunkrsException('invalid_report');
        }
        $canonical = $parsed;
        usort($canonical['shipments'], fn ($a, $b) => strcmp($a['trunkrs_number'], $b['trunkrs_number']));
        if ($parsed['report_date'] === null) {
            // Zero-row reports on different arrival days are distinct, but their shipment date is unknown.
            $canonical['received_day'] = $received->setTimezone(config('trunkrs.timezone'))->toDateString();
        }
        $contentHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($message, $received, $parsed, $contentHash) {
            if (TrunkrsReport::where('content_hash', $contentHash)->exists()) {
                return false;
            }

            return TrunkrsReport::firstOrCreate(['message_hash' => $this->messageHash($message['id'])], [
                'content_hash' => $contentHash, 'report_date' => $parsed['report_date'], 'received_at' => $received,
                'shipment_count' => $parsed['shipment_count'], 'shipments' => $parsed['shipments'],
                'parser_version' => TrunkrsReportParser::VERSION,
            ])->wasRecentlyCreated;
        });
    }
}
