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

        return Cache::store('database')->lock('pitboard-trunkrs-settings-upgrade', 120)->block(10, function () {
            $migrations = [
                '2026_09_11_160000_create_trunkrs_reports_tables' => ['trunkrs_connections', 'trunkrs_reports'],
                self::MIGRATION => ['trunkrs_settings'],
            ];
            // Check ALL prerequisites before any writes. Never repair an ambiguous schema.
            foreach ($migrations as $migration => $tables) {
                $recorded = DB::table('migrations')->where('migration', $migration)->exists();
                foreach ($tables as $table) {
                    if ($recorded !== Schema::hasTable($table)) {
                        $this->error('Trunkrs schema and migration history disagree; no automatic repair performed.');

                        return self::FAILURE;
                    }
                }
            }
            foreach ($migrations as $migration => $tables) {
                if (! Schema::hasTable($tables[0]) && $this->call('migrate', ['--path' => ['database/migrations/'.$migration.'.php'], '--force' => true]) !== 0) {
                    return self::FAILURE;
                }
            }
            if (! Schema::hasColumns('trunkrs_settings', ['payload', 'pending', 'scheduler_seen_at'])) {
                return self::FAILURE;
            }
            $this->info('Trunkrs settings storage ready. Existing application data was not rewritten.');

            return self::SUCCESS;
        });
    }
}
