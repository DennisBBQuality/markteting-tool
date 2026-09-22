<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class TrunkrsDashboard
{
    public function summary(): array
    {
        $ready = Schema::hasTable('trunkrs_reports') && Schema::hasTable('trunkrs_connections');
        $state = $ready ? TrunkrsConnection::find(1) : null;
        $auth = app(TrunkrsMicrosoftAuth::class);
        $configured = $ready && $auth->configured() && $state?->getRawOriginal('refresh_token')
            && $state->configuration_hash === $auth->fingerprint();
        // An older delayed report must not replace a newer dated report.
        $report = $ready ? TrunkrsReport::whereNotNull('report_date')->orderByDesc('report_date')->orderByDesc('received_at')->first() : null;
        $emptyReport = $ready ? TrunkrsReport::whereNull('report_date')->orderByDesc('received_at')->first() : null;
        if ($emptyReport && (! $report || $emptyReport->received_at > $report->received_at)) {
            $report = $emptyReport;
        }
        $now = CarbonImmutable::now(config('trunkrs.timezone'));
        $expectedDate = $now->subDays($now->format('H:i') < config('trunkrs.expected_by') ? 2 : 1)->toDateString();
        $warnings = [];
        if (! $configured) {
            $warnings[] = 'De online Microsoft-koppeling is nog niet ingesteld.';
        } elseif (! $auth->configuration->get('enabled')) {
            $warnings[] = 'Automatisch inlezen staat uit.';
        } elseif (! $state?->last_started_at || $state->last_started_at->lt(now()->subMinutes(25))) {
            $warnings[] = 'De servercontrole is niet recent uitgevoerd. Laat de beheerder de serverplanning controleren.';
        }
        if ($state?->last_error) {
            $warnings[] = TrunkrsException::description($state->last_error);
        }
        if ($report && ! $report->report_date) {
            $warnings[] = 'Dit lege rapport bevat geen bezorgdatum. Er staan 0 regels in, maar de datum moet worden gecontroleerd.';
        } elseif ($report && $report->report_date->toDateString() < $expectedDate) {
            $warnings[] = 'Een nieuwer rapport ontbreekt. Hieronder blijft het laatste geldige overzicht staan.';
        } elseif (! $report && $configured && $auth->configuration->get('enabled')) {
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
