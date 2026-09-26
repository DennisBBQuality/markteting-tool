<?php

namespace App\Jobs;

use App\Services\Trunkrs\TrunkrsCheckStatus;
use App\Services\Trunkrs\TrunkrsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTrunkrsReportsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public ?string $checkId = null) {}

    public function handle(TrunkrsSync $sync): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        if (! $this->checkId) {
            $sync->run();

            return;
        }
        $status = app(TrunkrsCheckStatus::class);
        $status->started($this->checkId);
        try {
            $result = $sync->run();
        } catch (\Throwable) {
            // Never place provider exceptions (which can contain credentials) in cloud logs.
            $result = 'internal';
        }
        $status->finished($this->checkId, $result);
    }
}
