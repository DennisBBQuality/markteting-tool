<?php

namespace App\Services\Trunkrs;

use App\Models\TrunkrsConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TrunkrsMicrosoftAuth
{
    public function __construct(public readonly TrunkrsConfiguration $configuration) {}

    public const SCOPES = 'https://graph.microsoft.com/Mail.Read.Shared https://graph.microsoft.com/User.Read offline_access';

    public function usesOwnMailbox(): bool
    {
        return $this->configuration->get('mailbox_mode') === 'own';
    }

    public function scopes(): string
    {
        return $this->usesOwnMailbox()
            ? 'https://graph.microsoft.com/Mail.Read https://graph.microsoft.com/User.Read offline_access'
            : self::SCOPES;
    }

    public function configured(): bool
    {
        if (! in_array($this->configuration->get('mailbox_mode'), ['shared', 'own'], true)) {
            return false;
        }
        foreach (['tenant_id', 'client_id', 'reader_user_id'] as $key) {
            if (! preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', (string) $this->configuration->get($key))) {
                return false;
            }
        }

        return filter_var($this->configuration->get('mailbox'), FILTER_VALIDATE_EMAIL)
            && is_string($this->configuration->get('folder_id')) && strlen($this->configuration->get('folder_id')) > 10;
    }

    public function fingerprint(): string
    {
        $configuration = array_map(fn ($key) => $this->configuration->get($key), [
            'tenant_id', 'client_id', 'reader_user_id', 'mailbox', 'folder_id',
        ]);
        // Preserve existing shared-reader connections, but never reuse them in own mode.
        if ($this->usesOwnMailbox()) {
            $configuration[] = 'own';
        }

        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
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

        return $this->post('devicecode', ['client_id' => $this->configuration->get('client_id'), 'scope' => $this->scopes()]);
    }

    public function finishDeviceLogin(string $deviceCode, bool $verifyFolder = false): string
    {
        $data = $this->post('token', [
            'client_id' => $this->configuration->get('client_id'),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
        ], allowPending: true);
        if (isset($data['error'])) {
            return $data['error'];
        }
        $this->validateTokens($data);
        $this->verifyReader($data['access_token']);
        if ($verifyFolder) {
            $this->verifyReportFolder($data['access_token']);
        }
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
            'client_id' => $this->configuration->get('client_id'), 'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token, 'scope' => $this->scopes(),
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
        $mailScope = $this->usesOwnMailbox() ? 'Mail.Read' : 'Mail.Read.Shared';
        $allowed = [$mailScope, 'User.Read', 'offline_access', 'openid', 'profile', 'email'];
        if (! in_array($mailScope, $scopes, true) || ! in_array('User.Read', $scopes, true) || array_diff($scopes, $allowed)) {
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
                ->withoutRedirecting()->get('https://graph.microsoft.com/v1.0/me', ['$select' => 'id,userPrincipalName,mail']);
        } catch (ConnectionException) {
            throw new TrunkrsException('network');
        }
        $isOwner = strcasecmp((string) $response->json('userPrincipalName'), (string) $this->configuration->get('mailbox')) === 0
            || strcasecmp((string) $response->json('mail'), (string) $this->configuration->get('mailbox')) === 0;
        if (! $response->successful()
            || strcasecmp((string) $response->json('id'), (string) $this->configuration->get('reader_user_id')) !== 0
            || $isOwner !== $this->usesOwnMailbox()) {
            throw new TrunkrsException('scope');
        }
    }

    private function verifyReportFolder(string $token): void
    {
        // Only folder metadata: never list other folders or read their messages.
        $read = function (string $id) use ($token): array {
            try {
                $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                    ->withoutRedirecting()->get('https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($id), [
                        '$select' => 'id,displayName,parentFolderId',
                    ]);
            } catch (ConnectionException) {
                throw new TrunkrsException('network');
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw new TrunkrsException('folder');
            }

            return $response->json();
        };
        $folder = $read($this->configuration->get('folder_id'));
        if (($folder['displayName'] ?? '') !== 'Trunkrs not deliverd' || empty($folder['parentFolderId'])) {
            throw new TrunkrsException('folder');
        }
        $parent = $read($folder['parentFolderId']);
        $inbox = $read('inbox');
        if (($parent['displayName'] ?? '') !== 'Klantenservice' || empty($inbox['id'])
            || ($parent['parentFolderId'] ?? '') !== $inbox['id']) {
            throw new TrunkrsException('folder');
        }
    }

    private function post(string $endpoint, array $data, bool $allowPending = false): array
    {
        $this->assertRuntime();
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://login.microsoftonline.com/'.$this->configuration->get('tenant_id').'/oauth2/v2.0/'.$endpoint, $data);
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
