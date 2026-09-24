<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeNotificationMailStorage extends Command
{
    public const MIGRATION = '2026_09_24_140000_create_pitboard_mail_settings_table';

    protected $signature = 'pitboard:upgrade-notification-mail-storage';

    protected $description = 'Add only the isolated encrypted notification sender settings.';

    public function handle(): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
            return self::SUCCESS;
        }

        return Cache::store('database')->lock('pitboard-mail-storage-upgrade', 120)->block(10, function () {
            $recorded = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
            if ($recorded !== Schema::hasTable('pitboard_mail_settings')) {
                $this->error('Mail schema and migration history disagree; no automatic repair performed.');

                return self::FAILURE;
            }
            if (! $recorded && $this->call('migrate', ['--path' => ['database/migrations/'.self::MIGRATION.'.php'], '--force' => true]) !== 0) {
                return self::FAILURE;
            }

            return Schema::hasColumns('pitboard_mail_settings', ['payload', 'refresh_token', 'pending']) ? self::SUCCESS : self::FAILURE;
        });
    }
}
