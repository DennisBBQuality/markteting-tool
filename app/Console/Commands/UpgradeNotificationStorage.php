<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpgradeNotificationStorage extends Command
{
    public const MIGRATION = '2026_09_24_120000_create_pitboard_notifications_tables';

    protected $signature = 'pitboard:upgrade-notification-storage';

    protected $description = 'Apply only the additive Pitboard notification tables during the existing release.';

    public function handle(): int
    {
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
            return self::SUCCESS;
        }

        return Cache::store('database')->lock('pitboard-notification-storage-upgrade', 120)->block(10, function () {
            $recorded = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
            foreach (['pitboard_notifications', 'pitboard_notification_preferences'] as $table) {
                if ($recorded !== Schema::hasTable($table)) {
                    $this->error('Notification schema and migration history disagree; no automatic repair performed.');

                    return self::FAILURE;
                }
            }
            if (! $recorded && $this->call('migrate', ['--path' => ['database/migrations/'.self::MIGRATION.'.php'], '--force' => true]) !== 0) {
                return self::FAILURE;
            }
            if (! Schema::hasColumns('pitboard_notifications', ['user_id', 'read_at', 'email_status'])
                || ! Schema::hasColumns('pitboard_notification_preferences', ['user_id', 'task_email', 'project_email'])) {
                return self::FAILURE;
            }
            $this->info('Notification storage ready. Existing tasks and projects were not rewritten.');

            return self::SUCCESS;
        });
    }
}
