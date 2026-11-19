<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

// Optional hosting fallback. GitHub Actions also starts the same idempotent read-only check.
Schedule::command('trunkrs:sync')->cron('15,25,35,45,55 6-9 * * 2-6')->timezone('Europe/Amsterdam')->withoutOverlapping(10);

// Requires the existing host to run Laravel's scheduler every minute; activation checks its heartbeat.
Schedule::command('content:publish-due')->everyMinute()->withoutOverlapping(10);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pitboard:upgrade-owner-storage', function () {
    if (! Schema::hasTable('users')) {
        return 0;
    }

    return Cache::store('database')->lock('pitboard-owner-storage-upgrade', 120)->block(10, function () {
        return $this->call('migrate', ['--force' => true, '--path' => ['database/migrations/2026_11_19_120000_create_pitboard_owner_security.php', 'database/migrations/2026_11_19_140000_create_pitboard_member_security.php']]);
    });
});
