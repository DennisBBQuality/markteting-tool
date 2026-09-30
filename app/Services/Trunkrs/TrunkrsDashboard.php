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
        // Import scans may process older messages last; opening follows the newest received email.
        $latest = $ready ? TrunkrsReport::orderByDesc('received_at')->orderByDesc('created_at')->first() : null;
        $available = $ready ? TrunkrsReport::query()
            ->where(function ($query) use ($now) {
                $query->whereBetween('report_date', [$now->subDays(7)->toDateString(), $now->toDateString()])
                    ->orWhere(fn ($empty) => $empty->whereNull('report_date')
                        ->whereBetween('received_at', [$now->subDays(7)->startOfDay()->utc(), $now->endOfDay()->utc()]));
            })
            ->orderByDesc('received_at')->orderByDesc('created_at')
            ->get(['id', 'report_date', 'received_at', 'created_at', 'shipment_count']) : collect();
        // One choice per delivery date; keep the latest email visible even if it is older.
        $key = fn ($item) => $item->report_date?->toDateString()
            ?? 'mail:'.$item->received_at->setTimezone(config('trunkrs.timezone'))->toDateString();
        $available = $available->unique($key);
        if ($latest && ! $available->contains('id', $latest->id)) {
            $available = $available->reject(fn ($item) => $key($item) === $key($latest));
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
            'mail_date' => $report->received_at->setTimezone(config('trunkrs.timezone'))->toDateString(),
            'received_at' => $report->received_at->toIso8601String(),
            'imported_at' => $report->created_at->toIso8601String(), 'shipment_count' => $report->shipment_count,
        ];
    }

    /** Mail-day coverage is not delivery-day evidence: empty files contain no date. */
    public function mailDays(): array
    {
        $today = CarbonImmutable::now(config('trunkrs.timezone'))->startOfDay();
        $reports = Schema::hasTable('trunkrs_reports') ? TrunkrsReport::query()
            ->whereBetween('received_at', [$today->subDays(6)->utc(), $today->endOfDay()->utc()])
            ->orderByDesc('received_at')->orderByDesc('created_at')
            ->get(['id', 'report_date', 'received_at', 'created_at', 'shipment_count'])
            ->groupBy(fn ($report) => $report->received_at->setTimezone(config('trunkrs.timezone'))->toDateString()) : collect();

        return collect(range(0, 6))->map(function ($offset) use ($today, $reports) {
            $day = $today->subDays($offset)->toDateString();
            $report = $reports->get($day)?->first();

            return [
                'mail_date' => $day,
                'status' => ! $report ? 'not_imported' : ($report->shipment_count === 0 ? 'empty' : 'imported'),
                'report' => $report ? $this->report($report) : null,
            ];
        })->all();
    }
}
