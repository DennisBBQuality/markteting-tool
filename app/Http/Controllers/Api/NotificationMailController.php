<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PitboardMailSetting;
use App\Models\User;
use App\Services\Notifications\MicrosoftMailException;
use App\Services\Notifications\MicrosoftMailSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class NotificationMailController extends Controller
{
    public function __construct(private MicrosoftMailSender $sender) {}

    public function initialize(Request $request)
    {
        $request->validate(['confirm' => 'accepted']);
        try {
            $exit = Artisan::call('pitboard:upgrade-notification-mail-storage');
        } catch (\Throwable) {
            $exit = 1;
        }
        abort_if($exit !== 0 || ! $this->sender->storageReady(), 503, 'De aparte verzendopslag kon niet veilig worden voorbereid. Er zijn geen bestaande gegevens vervangen.');

        return response()->json(['ok' => true]);
    }

    private function owner(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.$request->session()->get('userId'));
    }

    public function show(Request $request)
    {
        $setting = $this->sender->setting();
        $payload = $setting?->payload ?? [];
        $pending = $setting?->pending;

        return response()->json([
            'ready' => $this->sender->storageReady(), 'runtime_allowed' => $this->sender->runtimeAllowed(),
            'fields' => array_intersect_key($payload, array_flip(['tenant_id', 'client_id', 'delegate_user_id', 'sender_address'])),
            'revision' => $payload['revision'] ?? '', 'connected' => (bool) $setting?->refresh_token,
            'enabled' => $this->sender->ready($setting), 'sender_name' => 'BBQuality Pitboard',
            'test_state' => $payload['test_state'] ?? null, 'test_id' => $payload['test_id'] ?? null,
            'test_at' => $payload['test_at'] ?? null, 'test_recipient' => $payload['test_recipient'] ?? null,
            'can_confirm_test' => ($payload['test_actor'] ?? null) === $request->session()->get('userId')
                && ($payload['test_state'] ?? '') === 'accepted' && ($payload['test_at'] ?? 0) > time() - 86400,
            'pending' => $pending && $pending['owner'] === $this->owner($request) && $pending['expires_at'] > time()
                ? $this->publicPending($pending) : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function revision(Request $request): ?PitboardMailSetting
    {
        $request->validate(['revision' => 'present|nullable|string|max:36']);
        $setting = $this->sender->setting();
        abort_if(($request->input('revision') ?? '') !== ($setting?->payload['revision'] ?? ''), 409, 'De verzendinstellingen zijn gewijzigd. Herlaad dit scherm.');

        return $setting;
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => 'required|uuid', 'client_id' => 'required|uuid', 'delegate_user_id' => 'required|uuid',
            'sender_address' => 'required|email|max:254',
        ]);

        return $this->locked(function () use ($request, $data) {
            $this->revision($request);
            // No token reuse from Trunkrs or from an earlier sender configuration.
            PitboardMailSetting::updateOrCreate(['id' => 1], [
                'payload' => [...$data, 'sender_address' => strtolower($data['sender_address']), 'enabled' => false, 'revision' => (string) Str::uuid()],
                'refresh_token' => null, 'pending' => null,
            ]);

            return response()->json(['ok' => true]);
        });
    }

    public function start(Request $request)
    {
        $request->validate(['consent' => 'accepted']);

        return $this->locked(function () use ($request) {
            $setting = $this->revision($request);
            abort_unless($setting, 422, 'Sla eerst de verzendinstellingen op.');
            $pending = $setting->pending;
            if ($pending && $pending['expires_at'] > time()) {
                abort_unless($pending['owner'] === $this->owner($request), 409, 'Een andere beheerderssessie is al bezig met verbinden.');

                return response()->json($this->publicPending($pending));
            }
            $device = $this->sender->start($setting);
            if (! is_string($device['device_code'] ?? null) || ! preg_match('/^[A-Z0-9-]{4,32}$/D', $device['user_code'] ?? '') || empty($device['expires_in'])) {
                throw new MicrosoftMailException('authorization');
            }
            $interval = max(5, min(60, (int) ($device['interval'] ?? 5)));
            $pending = ['device_code' => $device['device_code'], 'user_code' => $device['user_code'], 'owner' => $this->owner($request),
                'expires_at' => time() + max(1, min(900, (int) $device['expires_in'])), 'interval' => $interval, 'next_at' => time() + $interval];
            $payload = $setting->payload;
            $payload['enabled'] = false;
            unset($payload['verified_at'], $payload['test_state'], $payload['test_id'], $payload['test_at'], $payload['test_actor'], $payload['test_recipient']);
            $setting->update(['payload' => $payload, 'refresh_token' => null, 'pending' => $pending]);

            return response()->json($this->publicPending($pending));
        });
    }

    public function poll(Request $request)
    {
        return $this->locked(function () use ($request) {
            $setting = $this->revision($request);
            $pending = $setting?->pending;
            abort_unless($pending && $pending['owner'] === $this->owner($request), 409, 'Start de aanmelding opnieuw in deze beheerderssessie.');
            if ($pending['expires_at'] <= time()) {
                $setting->update(['pending' => null]);
                abort(422, 'De aanmeldcode is verlopen. Start opnieuw.');
            }
            if ($pending['next_at'] > time()) {
                return response()->json($this->publicPending($pending));
            }
            try {
                $state = $this->sender->finish($setting, $pending['device_code']);
            } catch (MicrosoftMailException $e) {
                $setting->update(['pending' => null]);
                throw $e;
            }
            if ($state === 'connected') {
                $payload = $setting->payload;
                $payload['revision'] = (string) Str::uuid();
                $setting->update(['payload' => $payload, 'pending' => null]);

                return response()->json(['state' => 'connected']);
            }
            if ($state === 'slow_down') {
                $pending['interval'] += 5;
            }
            $pending['next_at'] = time() + $pending['interval'];
            $setting->update(['pending' => $pending]);

            return response()->json($this->publicPending($pending));
        });
    }

    public function test(Request $request)
    {
        $request->validate(['confirm' => 'accepted']);

        return $this->locked(function () use ($request) {
            $setting = $this->revision($request);
            abort_unless($setting?->refresh_token, 422, 'Verbind eerst met Microsoft.');
            $recipient = User::findOrFail($request->session()->get('userId'))->email;
            abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 422, 'Je Pitboard-account heeft geen geldig e-mailadres.');
            $payload = $setting->payload;
            // Persist a consumed revision before network I/O: a lost response cannot cause a duplicate.
            $payload = [...$payload, 'enabled' => false, 'verified_at' => null, 'revision' => (string) Str::uuid(),
                'test_id' => (string) Str::uuid(), 'test_state' => 'uncertain', 'test_at' => time(),
                'test_actor' => $request->session()->get('userId'), 'test_recipient' => $recipient];
            $setting->update(['payload' => $payload]);
            $html = '<h1>BBQuality Pitboard</h1><p>Dit is een proefbericht voor taak- en projectmeldingen.</p>'
                .'<p>Controleer of de afzender BBQuality Pitboard is, zonder &ldquo;namens&rdquo; een persoon. Controlecode: '.e($payload['test_id']).'</p>';
            $this->sender->send($setting, $recipient, 'BBQuality Pitboard · Proefbericht', $html);
            $payload['test_state'] = 'accepted';
            $setting->update(['payload' => $payload]);

            return response()->json(['ok' => true, 'message' => 'Microsoft heeft de proefmail aangenomen. Controleer zelf de ontvangst en afzender voordat je meldingen activeert.']);
        });
    }

    public function enable(Request $request)
    {
        $request->validate(['received_correct_sender' => 'accepted', 'test_id' => 'required|uuid']);

        return $this->locked(function () use ($request) {
            $setting = $this->revision($request);
            $p = $setting?->payload ?? [];
            abort_unless($this->sender->runtimeAllowed() && $setting?->refresh_token && ($p['test_state'] ?? '') === 'accepted'
                && ($p['test_id'] ?? '') === $request->input('test_id') && ($p['test_actor'] ?? '') === $request->session()->get('userId')
                && ($p['test_at'] ?? 0) > time() - 86400, 422, 'Stuur en controleer eerst zelf een nieuw proefbericht.');
            $setting->update(['payload' => [...$p, 'enabled' => true, 'verified_at' => now()->toIso8601String(), 'revision' => (string) Str::uuid()]]);

            return response()->json(['ok' => true]);
        });
    }

    public function stop(Request $request)
    {
        return $this->locked(function () use ($request) {
            $setting = $this->revision($request);
            if ($setting) {
                $setting->update(['payload' => [...$setting->payload, 'enabled' => false, 'verified_at' => null, 'test_state' => null, 'revision' => (string) Str::uuid()],
                    'refresh_token' => null, 'pending' => null]);
            }

            return response()->json(['ok' => true]);
        });
    }

    private function publicPending(array $p): array
    {
        return ['state' => 'pending', 'user_code' => $p['user_code'], 'verification_uri' => 'https://microsoft.com/devicelogin',
            'expires_at' => $p['expires_at'], 'retry_after' => max(1, $p['next_at'] - time())];
    }

    private function locked(callable $action)
    {
        abort_unless($this->sender->storageReady(), 503, 'Bereid eerst de aparte verzendopslag voor.');
        try {
            return $this->sender->locked($action)->header('Cache-Control', 'no-store');
        } catch (MicrosoftMailException $e) {
            return response()->json(['error' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
    }
}
