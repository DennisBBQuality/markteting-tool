<?php

namespace App\Services\Notifications;

use App\Models\PitboardMailSetting;
use App\Models\PitboardNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MicrosoftMailSender
{
    public const SCOPES = 'https://graph.microsoft.com/Mail.Send.Shared https://graph.microsoft.com/User.Read offline_access';

    public function storageReady(): bool
    {
        return Schema::hasColumns('pitboard_mail_settings', ['payload', 'refresh_token', 'pending']);
    }

    public function setting(): ?PitboardMailSetting
    {
        return $this->storageReady() ? PitboardMailSetting::find(1) : null;
    }

    public function runtimeAllowed(): bool
    {
        return app()->environment(['production', 'testing'])
            && str_starts_with((string) config('pitboard_notifications.url'), 'https://');
    }

    public function ready(?PitboardMailSetting $setting = null): bool
    {
        $setting ??= $this->setting();

        return $this->runtimeAllowed() && $setting?->refresh_token
            && ($setting->payload['enabled'] ?? false) && ! empty($setting->payload['verified_at']);
    }

    public function locked(callable $action): mixed
    {
        $lock = Cache::lock('pitboard-microsoft-mail', 180);
        if (! $lock->get()) {
            throw new MicrosoftMailException('busy');
        }
        try {
            return $action();
        } finally {
            $lock->release();
        }
    }

    public function start(PitboardMailSetting $setting): array
    {
        return $this->post($setting, 'devicecode', ['client_id' => $setting->payload['client_id'], 'scope' => self::SCOPES]);
    }

    public function finish(PitboardMailSetting $setting, string $deviceCode): string
    {
        $data = $this->post($setting, 'token', ['client_id' => $setting->payload['client_id'],
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $deviceCode]);
        if (isset($data['error'])) {
            return $data['error'];
        }
        $this->validateTokens($data);
        $this->verifyUser($setting, $data['access_token']);
        $setting->update(['refresh_token' => $data['refresh_token']]);

        return 'connected';
    }

    private function accessToken(PitboardMailSetting $setting): string
    {
        if (! $setting->refresh_token) {
            throw new MicrosoftMailException('authorization');
        }
        $data = $this->post($setting, 'token', ['client_id' => $setting->payload['client_id'],
            'grant_type' => 'refresh_token', 'refresh_token' => $setting->refresh_token, 'scope' => self::SCOPES]);
        $this->validateTokens($data);
        // Save rotation before further requests. Never expose provider bodies or tokens in exceptions.
        $setting->update(['refresh_token' => $data['refresh_token']]);
        $this->verifyUser($setting, $data['access_token']);

        return $data['access_token'];
    }

    private function validateTokens(array $data): void
    {
        $scopes = array_map(fn ($s) => str_replace('https://graph.microsoft.com/', '', $s), explode(' ', $data['scope'] ?? ''));
        if (! in_array('Mail.Send.Shared', $scopes, true) || ! in_array('User.Read', $scopes, true)
            || array_diff($scopes, ['Mail.Send.Shared', 'User.Read', 'offline_access', 'openid', 'profile', 'email'])) {
            throw new MicrosoftMailException('scope');
        }
        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new MicrosoftMailException('authorization');
        }
    }

    private function verifyUser(PitboardMailSetting $setting, string $token): void
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,mail,userPrincipalName']);
        } catch (ConnectionException) {
            throw new MicrosoftMailException('network');
        }
        $fields = $setting->payload;
        // Never connect the sender address itself as a normal paid/personal account.
        if (! $response->successful() || strcasecmp((string) $response->json('id'), $fields['delegate_user_id']) !== 0
            || strcasecmp((string) $response->json('mail'), $fields['sender_address']) === 0
            || strcasecmp((string) $response->json('userPrincipalName'), $fields['sender_address']) === 0) {
            throw new MicrosoftMailException('scope');
        }
    }

    private function post(PitboardMailSetting $setting, string $endpoint, array $body): array
    {
        if (! $this->runtimeAllowed()) {
            throw new MicrosoftMailException('runtime');
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://login.microsoftonline.com/'.$setting->payload['tenant_id'].'/oauth2/v2.0/'.$endpoint, $body);
        } catch (ConnectionException) {
            throw new MicrosoftMailException('network');
        }
        if (($body['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:device_code'
            && in_array($response->json('error'), ['authorization_pending', 'slow_down'], true)) {
            return ['error' => $response->json('error')];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new MicrosoftMailException('authorization');
        }

        return $response->json();
    }

    /** Caller holds the sender lock. No automatic retry: 202 is acceptance, not delivery. */
    public function send(PitboardMailSetting $setting, string $recipient, string $subject, string $html): void
    {
        $token = $this->accessToken($setting);
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)->withoutRedirecting()
                ->post('https://graph.microsoft.com/v1.0/me/sendMail', ['message' => [
                    'subject' => $subject, 'body' => ['contentType' => 'HTML', 'content' => $html],
                    'from' => ['emailAddress' => ['name' => 'BBQuality Pitboard', 'address' => $setting->payload['sender_address']]],
                    'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
                ], 'saveToSentItems' => true]);
        } catch (ConnectionException) {
            throw new MicrosoftMailException('network');
        }
        if ($response->status() === 403) {
            throw new MicrosoftMailException('send_as');
        }
        if ($response->status() !== 202) {
            throw new MicrosoftMailException('network');
        }
    }

    public function sendAssignment(PitboardNotification $notification, string $recipient): void
    {
        $this->locked(function () use ($notification, $recipient) {
            $setting = $this->setting();
            if (! $this->ready($setting)) {
                throw new MicrosoftMailException('authorization');
            }
            $this->send($setting, $recipient,
                'BBQuality Pitboard · '.($notification->kind === 'task' ? 'Nieuwe taak voor jou' : 'Toegevoegd aan een project'),
                view('emails.assignment', ['notification' => $notification,
                    'link' => rtrim(config('pitboard_notifications.url'), '/').'/?melding='.$notification->id])->render());
        });
    }
}
