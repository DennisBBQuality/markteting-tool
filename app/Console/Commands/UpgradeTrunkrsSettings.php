<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeTrunkrsSettings extends Command
{
    public const MIGRATION = '2026_09_22_150000_create_trunkrs_settings_table';

    protected $signature = 'pitboard:upgrade-trunkrs-settings';

    protected $description = 'Apply only the additive Trunkrs settings migration during the existing deployment.';

    public function handle(): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
            return self::SUCCESS;
        }
        if (! Schema::hasTable('trunkrs_connections') || ! Schema::hasTable('trunkrs_reports')) {
            $this->error('Trunkrs prerequisite tables are missing; no changes performed.');

            return self::FAILURE;
        }

        return Cache::store('database')->lock('pitboard-trunkrs-settings-upgrade', 120)->block(10, function () {
            $recorded = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
            $exists = Schema::hasTable('trunkrs_settings');
            if ($recorded !== $exists) {
                $this->error('Trunkrs settings schema and migration history disagree; no automatic repair performed.');

                return self::FAILURE;
            }
            if (! $exists && $this->call('migrate', ['--path' => ['database/migrations/'.self::MIGRATION.'.php'], '--force' => true]) !== 0) {
                return self::FAILURE;
            }
            if (! Schema::hasColumns('trunkrs_settings', ['payload', 'pending', 'scheduler_seen_at'])) {
                return self::FAILURE;
            }
            $this->info('Trunkrs settings storage ready. Existing application data was not rewritten.');

            return self::SUCCESS;
        });
    }
}
