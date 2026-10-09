<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeWordPressMediaStorage extends Command
{
    public const MIGRATION = '2026_10_09_180000_create_wordpress_media_bridge_tables';

    protected $signature = 'pitboard:upgrade-wordpress-media-storage';

    protected $description = 'Apply only the additive, disabled-by-default WordPress media bridge tables.';

    public function handle(): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('product_image_assets')) {
            $this->info('WordPress media upgrade skipped: application not installed.');

            return self::SUCCESS;
        }

        return Cache::store('database')->lock('pitboard-wordpress-media-storage-upgrade', 120)->block(10, function () {
            $recorded = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
            foreach (['wordpress_connections', 'wordpress_media_transfers'] as $table) {
                if ($recorded !== Schema::hasTable($table)) {
                    $this->error('WordPress media schema/history mismatch; no automatic repair performed.');

                    return self::FAILURE;
                }
            }
            if (! $recorded && $this->call('migrate', ['--path' => ['database/migrations/'.self::MIGRATION.'.php'], '--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            $ready = Schema::hasColumns('wordpress_connections', ['password', 'enabled', 'destination', 'revision'])
                && Schema::hasColumns('wordpress_media_transfers', ['claim', 'approved_fingerprint', 'uploaded_fingerprint']);
            $this->info($ready ? 'WordPress media storage ready. No connection activated.' : 'WordPress media storage verification failed.');

            return $ready ? self::SUCCESS : self::FAILURE;
        });
    }
}
