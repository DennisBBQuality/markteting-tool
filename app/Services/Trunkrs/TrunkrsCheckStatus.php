<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Short-lived, non-personal evidence for one specific cloud request. */
class TrunkrsCheckStatus
{
    public function create(): string
    {
        $id = (string) Str::uuid();
        $day = CarbonImmutable::now(config('trunkrs.timezone'))->startOfDay();
        $this->put($id, [
            'check_id' => $id, 'state' => 'queued', 'result' => null,
            'requested_at' => now()->toIso8601String(),
            'mail_date' => $day->toDateString(),
            'expected_delivery_date' => $day->subDay()->toDateString(),
        ]);

        return $id;
    }

    public function get(string $id): ?array
    {
        // Shared database cache, not a web-process-local or browser session cache.
        return Cache::store('database')->get('trunkrs-check:'.$id);
    }

    public function started(string $id): void
    {
        $this->update($id, ['state' => 'running', 'started_at' => now()->toIso8601String()]);
    }

    public function finished(string $id, string $result): void
    {
        $check = $this->get($id);
        if (! $check) {
            return;
        }
        $connection = TrunkrsConnection::find(1);
        // Match the dashboard's latest received report, not just any old successful import.
        $report = TrunkrsReport::orderByDesc('received_at')->orderByDesc('created_at')->first();
        $current = $report
            && $report->received_at->setTimezone(config('trunkrs.timezone'))->toDateString() === $check['mail_date']
            && $report->report_date?->toDateString() === $check['expected_delivery_date'];
        $this->update($id, [
            'state' => $result === 'ok' ? 'completed' : 'failed',
            'result' => $result,
            'finished_at' => now()->toIso8601String(),
            'mailbox_completed' => $result === 'ok',
            'last_checked_at' => $connection?->last_checked_at?->toIso8601String(),
            'retry_at' => $connection?->retry_at?->toIso8601String(),
            'report_current' => (bool) $current,
            'report_date' => $report?->report_date?->toDateString(),
            'report_received_at' => $report?->received_at?->toIso8601String(),
            'report_imported_at' => $report?->created_at?->toIso8601String(),
        ]);
    }

    private function update(string $id, array $values): void
    {
        if ($check = $this->get($id)) {
            $this->put($id, array_replace($check, $values));
        }
    }

    private function put(string $id, array $values): void
    {
        // No addresses, shipment rows, credentials or message identifiers in this response.
        Cache::store('database')->put('trunkrs-check:'.$id, $values, now()->addDay());
    }
}
