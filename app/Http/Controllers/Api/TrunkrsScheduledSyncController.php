<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\GithubScheduleIdentity;
use App\Services\Trunkrs\TrunkrsConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrunkrsScheduledSyncController extends Controller
{
    public function __invoke(Request $request, GithubScheduleIdentity $identity)
    {
        $header = $request->header('Authorization', '');
        $token = preg_match('/^Bearer ([A-Za-z0-9._-]+)$/D', $header, $matches) ? $matches[1] : null;
        abort_unless($identity->accepts($token), 401);
        abort_unless(Schema::hasTable('trunkrs_settings') && Schema::hasTable('trunkrs_connections'), 503);
        abort_unless(app(TrunkrsConfiguration::class)->get('enabled'), 409);

        SyncTrunkrsReportsJob::dispatch()->onConnection(app()->environment('local') ? 'database' : 'deferred')->onQueue('trunkrs');
        TrunkrsSetting::where('id', 1)->update(['scheduler_seen_at' => now()]);

        return response()->json(['message' => 'Trunkrs-controle ingepland.'], 202)->header('Cache-Control', 'no-store');
    }
}
