<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeImageSeoStorage extends Command
{
    public const MIGRATION = '2026_09_18_100000_create_product_image_download_names_table';

    protected $signature = 'pitboard:upgrade-image-seo-storage';

    protected $description = 'Apply only the additive image SEO filename migration during an existing Composer install.';

    public function handle(): int
    {
        // Fresh installations use the normal installation process. Never bootstrap,
        // seed, or run unrelated historical migrations from this release hook.
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('product_image_assets')) {
            $this->line('Image SEO upgrade skipped: application schema is not installed yet.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('product_image_metadata')) {
            $this->error('Image SEO metadata prerequisite is missing; no schema changes performed.');

            return self::FAILURE;
        }

        return Cache::store('database')->lock('pitboard-image-seo-storage-upgrade', 120)
            ->block(10, fn () => $this->upgrade());
    }

    private function upgrade(): int
    {
        $recorded = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
        $exists = Schema::hasTable('product_image_download_names');
        if ($recorded !== $exists) {
            $this->error('Image SEO schema and migration history disagree; no automatic repair performed.');

            return self::FAILURE;
        }

        if (! $exists) {
            $exit = $this->call('migrate', [
                '--path' => ['database/migrations/'.self::MIGRATION.'.php'],
                '--force' => true,
            ]);
            if ($exit !== self::SUCCESS) {
                return $exit;
            }
        }

        if (! Schema::hasColumns('product_image_download_names', ['filename', 'product_image_asset_id'])
            || ! DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
            $this->error('Image SEO storage upgrade could not be verified.');

            return self::FAILURE;
        }

        $this->info('Image SEO filename storage is ready. Existing application data was not rewritten.');

        return self::SUCCESS;
    }
}
