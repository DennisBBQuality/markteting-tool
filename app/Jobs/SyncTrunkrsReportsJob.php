<?php

namespace App\Jobs;

use App\Services\Trunkrs\TrunkrsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTrunkrsReportsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(TrunkrsSync $sync): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        $sync->run();
    }
}
