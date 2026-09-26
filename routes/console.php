<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Optional hosting fallback. GitHub Actions also starts the same idempotent read-only check.
Schedule::command('trunkrs:sync')->cron('15,25,35,45,55 6-9 * * *')->timezone('Europe/Amsterdam')->withoutOverlapping(10);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
