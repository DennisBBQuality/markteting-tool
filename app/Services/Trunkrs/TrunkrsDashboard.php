<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class TrunkrsDashboard
{
    public function summary(?string $selectedReportId = null): array
    {
        $ready = Schema::hasTable('trunkrs_reports') && Schema::hasTable('trunkrs_connections');
        $state = $ready ? TrunkrsConnection::find(1) : null;
        $auth = app(TrunkrsMicrosoftAuth::class);
        $configured = $ready && $auth->configured() && $state?->getRawOriginal('refresh_token')
            && $state->configuration_hash === $auth->fingerprint();
        $now = CarbonImmutable::now(config('trunkrs.timezone'));
        // The opening view follows the newest imported email, not the highest date inside a CSV.
        $latest = $ready ? TrunkrsReport::orderByDesc('created_at')->orderByDesc('received_at')->first() : null;
        $available = $ready ? TrunkrsReport::query()
            ->where(function ($query) use ($now) {
                $query->whereBetween('report_date', [$now->subDays(7)->toDateString(), $now->toDateString()])
                    ->orWhere(function ($query) use ($now) {
                        $query->whereNull('report_date')->where('received_at', '>=', $now->subDays(7)->startOfDay()->utc());
                    });
            })
            ->orderByDesc('received_at')->orderByDesc('created_at')
            ->get(['id', 'report_date', 'received_at', 'created_at', 'shipment_count']) : collect();
        // One choice per delivery date; keep the latest email visible even if it is older.
        $available = $available->unique(fn ($item) => $item->report_date?->toDateString() ?? 'unknown');
        if ($latest && ! $available->contains('id', $latest->id)) {
            $available = $available->reject(fn ($item) => $item->report_date?->toDateString() === $latest->report_date?->toDateString());
            $available->prepend($latest);
        }
        $report = $selectedReportId && $available->contains('id', $selectedReportId)
            ? TrunkrsReport::find($selectedReportId) : $latest;
        $expectedDate = $now->subDays($now->format('H:i') < config('trunkrs.expected_by') ? 2 : 1)->toDateString();
        $warnings = [];
        if (! $configured) {
            $warnings[] = 'De online Microsoft-koppeling is nog niet ingesteld.';
        } elseif (! $auth->configuration->get('enabled')) {
            $warnings[] = 'Automatisch inlezen staat uit.';
        }
        if ($state?->last_error) {
            $warnings[] = TrunkrsException::description($state->last_error);
        }
        if ($latest && ! $latest->report_date) {
            $warnings[] = 'Dit lege rapport bevat geen bezorgdatum. Er staan 0 regels in, maar de datum moet worden gecontroleerd.';
        } elseif ($latest && $latest->report_date->toDateString() < $expectedDate) {
            $warnings[] = 'Een nieuwer rapport ontbreekt. Hieronder blijft het laatste geldige overzicht staan.';
        } elseif (! $latest && $configured && $auth->configuration->get('enabled')) {
            $warnings[] = 'Nog geen geldig rapport ontvangen. Dit betekent niet dat er 0 niet-bezorgde zendingen zijn.';
        }

        return [
            'title' => 'Niet bezorgd Trunkrs',
            'configured' => (bool) $configured,
            'enabled' => (bool) $auth->configuration->get('enabled'),
            'warnings' => $warnings,
            'last_checked_at' => $state?->last_checked_at?->toIso8601String(),
            'last_started_at' => $state?->last_started_at?->toIso8601String(),
            'retry_at' => $state?->retry_at?->toIso8601String(),
            'report' => $report ? $this->report($report) : null,
            'available_reports' => $available->map(fn ($item) => $this->report($item))->values()->all(),
            'shipments' => $report ? array_slice($report->shipments, 0, 5) : [],
        ];
    }

    public function report(TrunkrsReport $report): array
    {
        return [
            'id' => $report->id, 'report_date' => $report->report_date?->toDateString(),
            'received_at' => $report->received_at->toIso8601String(),
            'imported_at' => $report->created_at->toIso8601String(), 'shipment_count' => $report->shipment_count,
        ];
    }
}
