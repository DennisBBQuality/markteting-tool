<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsConnection;
use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\TrunkrsConfiguration;
use App\Services\Trunkrs\TrunkrsDashboard;
use App\Services\Trunkrs\TrunkrsException;
use App\Services\Trunkrs\TrunkrsMicrosoftAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TrunkrsSettingController extends Controller
{
    private function ready(): bool
    {
        return Schema::hasTable('trunkrs_settings') && Schema::hasTable('trunkrs_connections') && Schema::hasTable('trunkrs_reports');
    }

    private function owner(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.$request->session()->get('userId'));
    }

    public function show(Request $request)
    {
        if (! $this->ready()) {
            return response()->json(['ready' => false, 'message' => 'De database-uitrol voor Trunkrs is nog niet afgerond.'])->header('Cache-Control', 'no-store');
        }
        $setting = TrunkrsSetting::find(1);
        $configuration = app(TrunkrsConfiguration::class);
        $fields = [];
        foreach (TrunkrsConfiguration::FIELDS as $field) {
            $fields[$field] = $configuration->get($field) ?? '';
        }
        $pending = $setting?->pending;

        return response()->json([
            'ready' => true, 'fields' => $fields, 'revision' => $setting?->payload['revision'] ?? '',
            'status' => app(TrunkrsDashboard::class)->summary(),
            'scheduler_seen_at' => $setting?->scheduler_seen_at?->toIso8601String(),
            'scheduler_recent' => $setting?->scheduler_seen_at?->gt(now()->subMinutes(25)) ?? false,
            'pending' => $pending && $pending['owner'] === $this->owner($request) && $pending['expires_at'] > time()
                ? $this->publicPending($pending) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request)
    {
        $rules = ['revision' => 'present|nullable|string|max:36'];
        foreach (['tenant_id', 'client_id', 'reader_user_id'] as $field) {
            $rules[$field] = ['required', 'string', 'regex:/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD'];
        }
        $rules['mailbox'] = 'required|email|max:254';
        $rules['folder_id'] = ['required', 'string', 'min:11', 'max:2048', 'regex:/^[A-Za-z0-9_+=\/-]+$/D'];
        $data = $request->validate($rules);

        return $this->locked(function () use ($request, $data) {
            $setting = TrunkrsSetting::find(1);
            abort_if(($data['revision'] ?? '') !== ($setting?->payload['revision'] ?? ''), 409, 'De instellingen zijn veranderd. Herlaad het scherm.');
            $payload = array_intersect_key($data, array_flip(TrunkrsConfiguration::FIELDS));
            $payload += ['mailbox_mode' => 'own', 'enabled' => false, 'revision' => (string) Str::uuid()];
            DB::transaction(function () use ($payload, $request) {
                TrunkrsSetting::updateOrCreate(['id' => 1], [
                    'payload' => $payload, 'pending' => null, 'updated_by' => $request->session()->get('userId'),
                ]);
                TrunkrsConnection::where('id', 1)->update(['refresh_token' => null, 'configuration_hash' => null, 'last_error' => null, 'retry_at' => null]);
            });
            $this->audit($request, 'settings_saved');

            return response()->json(['message' => 'Opgeslagen. Verbind opnieuw met Microsoft; bestaande rapporten blijven bewaard.']);
        });
    }

    public function start(Request $request)
    {
        $request->validate(['consent' => 'accepted', 'revision' => 'required|string|max:36']);

        return $this->locked(function () use ($request) {
            $setting = TrunkrsSetting::findOrFail(1);
            abort_if($request->input('revision') !== $setting->payload['revision'], 409, 'De instellingen zijn veranderd. Herlaad het scherm.');
            $pending = $setting->pending;
            if ($pending && $pending['expires_at'] > time()) {
                abort_unless($pending['owner'] === $this->owner($request), 409, 'Een andere beheerderssessie is bezig met verbinden. Annuleer die poging of wacht tot de code verloopt.');

                return response()->json($this->publicPending($pending));
            }
            $auth = app(TrunkrsMicrosoftAuth::class);
            abort_unless($auth->usesOwnMailbox(), 422, 'Sla eerst de eigen-mailboxinstellingen op.');
            $device = $auth->startDeviceLogin();
            if (! is_string($device['device_code'] ?? null) || ! is_string($device['user_code'] ?? null)
                || ! preg_match('/^[A-Z0-9-]{4,32}$/D', $device['user_code']) || empty($device['expires_in'])) {
                throw new TrunkrsException('authorization');
            }
            $interval = max(5, min(60, (int) ($device['interval'] ?? 5)));
            $pending = [
                'device_code' => $device['device_code'], 'user_code' => $device['user_code'],
                'owner' => $this->owner($request), 'fingerprint' => $auth->fingerprint(),
                'expires_at' => time() + max(1, min(900, (int) $device['expires_in'])),
                'interval' => $interval, 'next_at' => time() + $interval,
            ];
            $setting->update(['pending' => $pending]);
            $this->audit($request, 'login_started');

            return response()->json($this->publicPending($pending));
        });
    }

    public function poll(Request $request)
    {
        return $this->locked(function () use ($request) {
            $setting = TrunkrsSetting::findOrFail(1);
            $pending = $setting->pending;
            abort_unless($pending && $pending['owner'] === $this->owner($request), 409, 'Start de Microsoft-aanmelding opnieuw in deze beheerderssessie.');
            if ($pending['expires_at'] <= time()) {
                $setting->update(['pending' => null]);
                abort(422, 'De aanmeldcode is verlopen. Start opnieuw.');
            }
            $auth = app(TrunkrsMicrosoftAuth::class);
            abort_unless(hash_equals($pending['fingerprint'], $auth->fingerprint()), 409, 'De instellingen zijn veranderd. Start opnieuw.');
            if ($pending['next_at'] > time()) {
                return response()->json($this->publicPending($pending));
            }
            try {
                $result = $auth->finishDeviceLogin($pending['device_code'], verifyFolder: true);
            } catch (TrunkrsException $e) {
                $setting->update(['pending' => null]);
                throw $e;
            }
            if ($result === 'connected') {
                $payload = $setting->payload;
                $payload['enabled'] = true;
                $payload['revision'] = (string) Str::uuid();
                $setting->update(['payload' => $payload, 'pending' => null]);
                $this->audit($request, 'connected');

                return response()->json(['state' => 'connected', 'message' => 'Microsoft verbonden en rapportmap gecontroleerd. Start nu de eerste rapportcontrole.']);
            }
            if ($result === 'slow_down') {
                $pending['interval'] += 5;
            }
            $pending['next_at'] = time() + $pending['interval'];
            $setting->update(['pending' => $pending]);

            return response()->json($this->publicPending($pending));
        });
    }

    public function stop(Request $request)
    {
        return $this->locked(function () use ($request) {
            $setting = TrunkrsSetting::findOrFail(1);
            $payload = $setting->payload;
            $payload['enabled'] = false;
            $payload['revision'] = (string) Str::uuid();
            DB::transaction(function () use ($setting, $payload) {
                $setting->update(['payload' => $payload, 'pending' => null]);
                TrunkrsConnection::where('id', 1)->update(['refresh_token' => null, 'configuration_hash' => null]);
            });
            $this->audit($request, 'disconnected');

            return response()->json(['message' => 'Koppeling gestopt en lokale toegangstokens verwijderd. Rapporten blijven bewaard. Microsoft-toestemming kun je afzonderlijk in Entra intrekken.']);
        });
    }

    public function sync(Request $request)
    {
        abort_unless($this->ready(), 503, 'De database-uitrol voor Trunkrs is nog niet afgerond.');
        $status = app(TrunkrsDashboard::class)->summary();
        abort_unless($status['configured'] && $status['enabled'], 422, 'Verbind eerst met Microsoft.');
        SyncTrunkrsReportsJob::dispatch()->onConnection(app()->environment('local') ? 'database' : 'deferred')->onQueue('trunkrs');
        $this->audit($request, 'check_requested');

        return response()->json(['message' => 'Rapportcontrole gestart. Vernieuw straks de status; deze aanvraag is nog geen bevestigde import.'], 202);
    }

    private function publicPending(array $pending): array
    {
        return ['state' => 'pending', 'user_code' => $pending['user_code'],
            'verification_uri' => 'https://microsoft.com/devicelogin',
            'expires_at' => $pending['expires_at'], 'retry_after' => max(1, $pending['next_at'] - time())];
    }

    private function locked(callable $action)
    {
        abort_unless($this->ready(), 503, 'De database-uitrol voor Trunkrs is nog niet afgerond.');
        $lock = Cache::lock('trunkrs-sync', 600);
        abort_unless($lock->get(), 409, 'Een Trunkrs-controle is bezig. Probeer straks opnieuw.');
        try {
            return $action()->header('Cache-Control', 'no-store');
        } catch (TrunkrsException $e) {
            return response()->json(['error' => TrunkrsException::description($e->reason)], 422)->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    private function audit(Request $request, string $result): void
    {
        // Never log mailbox identifiers, codes, tokens or provider responses.
        Log::info('trunkrs.setup', ['actor' => $request->session()->get('userId'), 'result' => $result]);
    }
}
