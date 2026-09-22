<?php

namespace App\Console\Commands;

use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\TrunkrsException;
use App\Services\Trunkrs\TrunkrsSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncTrunkrsReports extends Command
{
    protected $signature = 'trunkrs:sync';

    protected $description = 'Lees uitsluitend de ingestelde Trunkrs-rapportmap; verstuur of wijzig geen e-mail.';

    public function handle(TrunkrsSync $sync): int
    {
        if (Schema::hasTable('trunkrs_settings')) {
            TrunkrsSetting::where('id', 1)->update(['scheduler_seen_at' => now()]);
        }
        $result = $sync->run();
        $this->line(match ($result) {
            'ok' => 'Trunkrs-rapportmap gecontroleerd.',
            'disabled' => 'Automatisch inlezen is nog niet ingeschakeld.',
            'busy' => 'Een andere Trunkrs-controle is al bezig.',
            'waiting' => 'De volgende poging wacht op de ingestelde hersteltijd.',
            default => TrunkrsException::description($result),
        });

        return in_array($result, ['ok', 'disabled', 'busy', 'waiting'], true) ? self::SUCCESS : self::FAILURE;
    }
}
