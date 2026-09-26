<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\GithubScheduleIdentity;
use App\Services\Trunkrs\TrunkrsCheckStatus;
use App\Services\Trunkrs\TrunkrsConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrunkrsScheduledSyncController extends Controller
{
    public function __invoke(Request $request, GithubScheduleIdentity $identity)
    {
        $this->authorizeCloud($request, $identity);
        abort_unless(Schema::hasTable('trunkrs_settings') && Schema::hasTable('trunkrs_connections'), 503);
        abort_unless(app(TrunkrsConfiguration::class)->get('enabled'), 409);

        $id = app(TrunkrsCheckStatus::class)->create();
        SyncTrunkrsReportsJob::dispatch($id)->onConnection(app()->environment('local') ? 'database' : 'deferred')->onQueue('trunkrs');
        TrunkrsSetting::where('id', 1)->update(['scheduler_seen_at' => now()]);

        return response()->json(['message' => 'Trunkrs-controle ingepland.', 'check_id' => $id], 202)->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, GithubScheduleIdentity $identity, string $checkId)
    {
        $this->authorizeCloud($request, $identity);
        $status = app(TrunkrsCheckStatus::class)->get($checkId);
        abort_unless($status, 404);

        return response()->json($status)->header('Cache-Control', 'no-store');
    }

    private function authorizeCloud(Request $request, GithubScheduleIdentity $identity): void
    {
        $header = $request->header('Authorization', '');
        $token = preg_match('/^Bearer ([A-Za-z0-9._-]+)$/D', $header, $matches) ? $matches[1] : null;
        abort_unless($identity->accepts($token), 401);
    }
}
