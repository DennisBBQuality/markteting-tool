<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrunkrsSync
{
    public function __construct(private TrunkrsGraphClient $graph, private TrunkrsImporter $importer, private TrunkrsConfiguration $configuration) {}

    public function run(): string
    {
        if (! $this->configuration->get('enabled')) {
            return 'disabled';
        }
        $lock = Cache::lock('trunkrs-sync', 600);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            // Resolve a fresh configuration after acquiring the same lock used by setup.
            $this->configuration = app(TrunkrsConfiguration::class);
            if (! $this->configuration->get('enabled')) {
                return 'disabled';
            }
            $this->graph = app(TrunkrsGraphClient::class);
            $this->importer = app(TrunkrsImporter::class);
            $state = TrunkrsConnection::firstOrCreate(['id' => 1]);
            $state->update(['last_started_at' => now()]);
            if ($state->retry_at?->isFuture()) {
                return 'waiting';
            }
            $count = 0;
            $failure = null;
            try {
                $this->graph->connect();
                foreach ($this->graph->messages() as $message) {
                    if (! $this->importer->accepts($message)) {
                        continue;
                    }
                    if (empty($message['id'])) {
                        throw new TrunkrsException('invalid_report');
                    }
                    if (TrunkrsReport::where('message_hash', $this->importer->messageHash($message['id']))->exists()) {
                        continue;
                    }
                    try {
                        $attachment = $this->graph->attachment($message);
                        $count += (int) $this->importer->import($message, $attachment['bytes'], $attachment['name']);
                    } catch (TrunkrsException $e) {
                        if (! in_array($e->reason, ['invalid_report', 'missing_attachment'], true)) {
                            throw $e;
                        }
                        // A malformed old message must not block valid newer reports.
                        $failure = $e;
                    }
                }
                if ($failure) {
                    throw $failure;
                }
                $state->update(['last_checked_at' => now(), 'last_error' => null, 'retry_at' => null]);
                Log::info('trunkrs.sync', ['version' => TrunkrsReportParser::VERSION, 'result' => 'ok', 'imported' => $count]);

                return 'ok';
            } catch (\Throwable $e) {
                $reason = $e instanceof TrunkrsException ? $e->reason : 'internal';
                $delay = $e instanceof TrunkrsException ? $e->retryAfter : 600;
                $state->update(['last_error' => $reason, 'retry_at' => now()->addSeconds($delay)]);
                Log::warning('trunkrs.sync', ['version' => TrunkrsReportParser::VERSION, 'result' => $reason]);

                return $reason;
            }
        } finally {
            $lock->release();
        }
    }
}
