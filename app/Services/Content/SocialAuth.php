<?php

namespace App\Services\Content;

use App\Models\ContentConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SocialAuth
{
    public function http(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(10)->timeout(45)->withoutRedirecting();

        return $token ? $request->withToken($token) : $request;
    }

    public function json($response): array
    {
        if (! $response->successful() || ! is_array($response->json()) || $response->json('error')) {
            // Never surface an OAuth response/exception: it can contain credentials.
            throw new RuntimeException('De platformaanvraag is niet bevestigd (HTTP '.$response->status().'). Controleer de verbinding en rechten.');
        }

        return $response->json();
    }

    public function graph(ContentConnection $connection): string
    {
        if (! preg_match('/^v\d+\.0$/', $connection->api_version ?? '')) {
            throw new RuntimeException('Vul de Graph API-versie uit de Meta-app in.');
        }

        return 'https://graph.facebook.com/'.$connection->api_version;
    }

    private function setupRequest(string $step, callable $request): array
    {
        try {
            $response = $request();
            $data = $response->json();
        } catch (\Throwable) {
            throw new ConnectionSetupException($step);
        }
        if (! $response->successful() || ! is_array($data) || ! empty($data['error'])) {
            $code = is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
            throw new ConnectionSetupException($step, $response->status(), is_int($code) ? $code : null);
        }

        return $data;
    }

    public function token(ContentConnection $connection): string
    {
        if ($connection->provider === 'youtube' && $connection->expires_at?->lt(now()->addMinutes(2))) {
            if (! $connection->refresh_token) {
                throw new RuntimeException('Meld het YouTube-account opnieuw aan.');
            }
            $data = $this->json($this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $connection->client_id, 'client_secret' => $connection->client_secret, 'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ]));
            if (empty($data['access_token'])) {
                throw new RuntimeException('Geen nieuw toegangsbewijs ontvangen.');
            }
            $connection->update(['access_token' => $data['access_token'], 'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600))]);
        }
        if (! $connection->access_token) {
            throw new RuntimeException('Meld het account eerst aan.');
        }

        return $connection->access_token;
    }

    public function exchange(ContentConnection $c, string $code, string $redirect): void
    {
        if ($c->provider === 'youtube') {
            $data = $this->setupRequest('youtube_exchange', fn () => $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $c->client_id, 'client_secret' => $c->client_secret, 'code' => $code, 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
            ]));
            if (empty($data['access_token']) || empty($data['refresh_token'])) {
                throw new ConnectionSetupException('youtube_exchange');
            }
            $c->fill(['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'], 'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600))]);
        } else {
            $data = $this->setupRequest('meta_exchange', fn () => $this->http()->get($this->graph($c).'/oauth/access_token', [
                'client_id' => $c->client_id, 'client_secret' => $c->client_secret, 'code' => $code, 'redirect_uri' => $redirect,
            ]));
            if (empty($data['access_token'])) {
                throw new ConnectionSetupException('meta_exchange');
            }
            $long = $this->setupRequest('meta_extend', fn () => $this->http()->get($this->graph($c).'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token', 'client_id' => $c->client_id, 'client_secret' => $c->client_secret, 'fb_exchange_token' => $data['access_token'],
            ]));
            if (empty($long['access_token'])) {
                throw new ConnectionSetupException('meta_extend');
            }
            $c->fill(['access_token' => $long['access_token'], 'expires_at' => now()->addSeconds((int) ($long['expires_in'] ?? 0))]);
        }
        $c->enabled = false;
        $c->verified_at = null;
        $c->save();
        $this->discover($c);
    }

    public function discover(ContentConnection $c): void
    {
        $token = $this->token($c);
        if ($c->provider === 'youtube') {
            $data = $this->setupRequest('youtube_accounts', fn () => $this->http($token)->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id,snippet', 'mine' => 'true']));
            $accounts = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['snippet']['title'] ?? $a['id']], $data['items'] ?? []);
        } else {
            $permissions = $this->setupRequest('meta_permissions', fn () => $this->http($token)->get($this->graph($c).'/me/permissions'));
            $granted = array_column(array_filter($permissions['data'] ?? [], fn ($p) => ($p['status'] ?? '') === 'granted'), 'permission');
            if (array_diff(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'], $granted)) {
                throw new ConnectionSetupException('meta_permissions');
            }
            $data = $this->setupRequest('meta_accounts', fn () => $this->http($token)->get($this->graph($c).'/me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100]));
            $accounts = array_values(array_filter($data['data'] ?? [], fn ($a) => ! empty($a['id']) && ! empty($a['access_token'])));
            if (! empty($data['paging']['next'])) {
                throw new ConnectionSetupException('too_many_accounts');
            }
        }
        if (! $accounts) {
            throw new ConnectionSetupException('no_accounts');
        }
        $selected = $c->selected_account;
        if ($selected && ! collect($accounts)->contains('id', $selected)) {
            $c->selected_account = null;
            $c->enabled = false;
        }
        $c->accounts = $accounts;
        $c->verified_at = now();
        $c->save();
    }

    public function account(ContentConnection $c): array
    {
        $account = collect($c->accounts ?? [])->firstWhere('id', $c->selected_account);
        if (! $account) {
            throw new RuntimeException('Kies eerst het juiste publicatieaccount.');
        }

        return $account;
    }
}
