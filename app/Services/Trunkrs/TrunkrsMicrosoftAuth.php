<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TrunkrsMicrosoftAuth
{
    public const SCOPES = 'https://graph.microsoft.com/Mail.Read.Shared https://graph.microsoft.com/User.Read offline_access';

    public function configured(): bool
    {
        foreach (['tenant_id', 'client_id', 'reader_user_id'] as $key) {
            if (! preg_match('/^[a-f0-9-]{36}$/iD', (string) config('trunkrs.'.$key))) {
                return false;
            }
        }

        return filter_var(config('trunkrs.mailbox'), FILTER_VALIDATE_EMAIL)
            && is_string(config('trunkrs.folder_id')) && strlen(config('trunkrs.folder_id')) > 10;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(array_map(fn ($key) => config('trunkrs.'.$key), [
            'tenant_id', 'client_id', 'reader_user_id', 'mailbox', 'folder_id',
        ]), JSON_THROW_ON_ERROR));
    }

    public function assertRuntime(): void
    {
        if (! $this->configured()
            || (app()->environment('local') && ! config('trunkrs.allow_local_read'))) {
            throw new TrunkrsException('configuration');
        }
    }

    public function startDeviceLogin(): array
    {
        $this->assertRuntime();

        return $this->post('devicecode', ['client_id' => config('trunkrs.client_id'), 'scope' => self::SCOPES]);
    }

    public function finishDeviceLogin(string $deviceCode): string
    {
        $data = $this->post('token', [
            'client_id' => config('trunkrs.client_id'),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
        ], allowPending: true);
        if (isset($data['error'])) {
            return $data['error'];
        }
        $this->validateTokens($data);
        $this->verifyReader($data['access_token']);
        TrunkrsConnection::updateOrCreate(['id' => 1], [
            'refresh_token' => $data['refresh_token'], 'configuration_hash' => $this->fingerprint(),
            'retry_at' => null, 'last_error' => null,
        ]);

        return 'connected';
    }

    public function accessToken(): string
    {
        $this->assertRuntime();
        $connection = TrunkrsConnection::find(1);
        if (! $connection?->refresh_token || $connection->configuration_hash !== $this->fingerprint()) {
            throw new TrunkrsException('authorization');
        }
        $data = $this->post('token', [
            'client_id' => config('trunkrs.client_id'), 'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token, 'scope' => self::SCOPES,
        ]);
        $this->validateTokens($data);
        // Store rotation immediately; never keep the replaced token in an .env or log.
        $connection->update(['refresh_token' => $data['refresh_token']]);
        $this->verifyReader($data['access_token']);

        return $data['access_token'];
    }

    private function validateTokens(array $data): void
    {
        $scopes = array_map(fn ($scope) => str_replace('https://graph.microsoft.com/', '', $scope), explode(' ', $data['scope'] ?? ''));
        $allowed = ['Mail.Read.Shared', 'User.Read', 'offline_access', 'openid', 'profile', 'email'];
        if (! in_array('Mail.Read.Shared', $scopes, true) || array_diff($scopes, $allowed)) {
            throw new TrunkrsException('scope');
        }
        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new TrunkrsException('authorization');
        }
    }

    private function verifyReader(string $token): void
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->withoutRedirecting()->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,userPrincipalName']);
        } catch (ConnectionException) {
            throw new TrunkrsException('network');
        }
        if (! $response->successful()
            || strcasecmp((string) $response->json('id'), (string) config('trunkrs.reader_user_id')) !== 0
            || strcasecmp((string) $response->json('userPrincipalName'), (string) config('trunkrs.mailbox')) === 0) {
            throw new TrunkrsException('scope');
        }
    }

    private function post(string $endpoint, array $data, bool $allowPending = false): array
    {
        $this->assertRuntime();
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://login.microsoftonline.com/'.config('trunkrs.tenant_id').'/oauth2/v2.0/'.$endpoint, $data);
        } catch (ConnectionException) {
            throw new TrunkrsException('network');
        }
        if ($allowPending && in_array($response->json('error'), ['authorization_pending', 'slow_down'], true)) {
            return ['error' => $response->json('error')];
        }
        if ($response->status() === 429) {
            throw new TrunkrsException('rate_limit', max(600, min(86400, (int) $response->header('Retry-After'))));
        }
        if ($response->serverError()) {
            throw new TrunkrsException('provider');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new TrunkrsException('authorization');
        }

        return $response->json();
    }
}
