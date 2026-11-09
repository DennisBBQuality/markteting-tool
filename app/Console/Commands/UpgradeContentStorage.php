<?php

namespace App\Console\Commands;

use App\Models\ContentConnection;
use App\Services\Content\ContentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeContentStorage extends Command
{
    protected $signature = 'pitboard:upgrade-content-storage';

    protected $description = 'Prepare only the additive content-platform storage; preserve existing application data.';

    public function handle(ContentStorage $storage): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
            return self::SUCCESS;
        }

        return Cache::store('database')->lock('pitboard-content-storage-upgrade', 120)->block(10, function () use ($storage) {
            $recorded = DB::table('migrations')->where('migration', ContentStorage::MIGRATION)->exists();
            // MySQL DDL can be partially applied. Never guess at a repair or replace an existing table.
            foreach (array_keys(ContentStorage::COLUMNS) as $table) {
                if ($recorded !== Schema::hasTable($table)) {
                    $this->error('Content schema and migration history disagree; no automatic repair performed.');

                    return self::FAILURE;
                }
            }
            foreach (['users', 'projects', 'tasks'] as $dependency) {
                if (! Schema::hasTable($dependency)) {
                    return self::FAILURE;
                }
            }
            if (! $recorded && $this->call('migrate', ['--path' => ['database/migrations/'.ContentStorage::MIGRATION.'.php'], '--force' => true]) !== 0) {
                return self::FAILURE;
            }
            if (! $storage->ready()) {
                return self::FAILURE;
            }
            // Empty, disabled slots make a real scheduler heartbeat visible before entering app credentials.
            foreach (['meta', 'youtube'] as $provider) {
                ContentConnection::firstOrCreate(['provider' => $provider]);
            }
            $this->info('Content storage ready. Existing data and publication settings preserved.');

            return self::SUCCESS;
        });
    }
}
