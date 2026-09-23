<?php

namespace Tests\Feature;

use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrunkrsGithubScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/trunkrs/scheduled-sync';

    private $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::forget('trunkrs-github-oidc-jwks');
        Queue::fake();
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($this->privateKey);
        Http::fake(['token.actions.githubusercontent.com/.well-known/jwks' => Http::response(['keys' => [[
            'kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $this->encode($details['rsa']['n']), 'e' => $this->encode($details['rsa']['e']),
        ]]])]);
        TrunkrsSetting::create(['id' => 1, 'payload' => [
            'enabled' => true, 'mailbox_mode' => 'own', 'mailbox' => 'owner@example.test',
            'folder_id' => 'test-report-folder',
        ]]);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function token(array $override = []): string
    {
        $now = time();
        $claims = array_replace([
            'iss' => 'https://token.actions.githubusercontent.com',
            'aud' => 'https://planning.bbquality.nl/api/trunkrs/scheduled-sync',
            'repository' => 'DennisBBQuality/markteting-tool',
            'repository_id' => '1167471646', 'repository_owner_id' => '157789343',
            'ref' => 'refs/heads/main',
            'workflow_ref' => 'DennisBBQuality/markteting-tool/.github/workflows/trunkrs-sync.yml@refs/heads/main',
            'event_name' => 'schedule', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 300,
        ], $override);
        $message = $this->encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR)).'.'
            .$this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $message.'.'.$this->encode($signature);
    }

    public function test_only_signed_main_workflow_can_start_a_report_check(): void
    {
        $this->postJson(self::URL)->assertUnauthorized();
        Queue::assertNotPushed(SyncTrunkrsReportsJob::class);
        $this->withHeader('Authorization', 'Bearer '.$this->token())->postJson(self::URL)->assertStatus(202);
        Queue::assertPushed(SyncTrunkrsReportsJob::class);
        $this->assertNotNull(TrunkrsSetting::find(1)->scheduler_seen_at);
    }

    public function test_wrong_repository_workflow_event_and_signature_are_rejected(): void
    {
        foreach ([
            ['repository_id' => 'another-repo'],
            ['ref' => 'refs/heads/feature'],
            ['workflow_ref' => 'DennisBBQuality/markteting-tool/.github/workflows/other.yml@refs/heads/main'],
            ['event_name' => 'pull_request'],
            ['aud' => 'https://other.example.test'],
            ['exp' => time() - 60],
        ] as $override) {
            $this->withHeader('Authorization', 'Bearer '.$this->token($override))->postJson(self::URL)->assertUnauthorized();
        }
        Queue::assertNotPushed(SyncTrunkrsReportsJob::class);
        $this->assertNull(TrunkrsSetting::find(1)->scheduler_seen_at);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $valid = $this->token();
        [$header, $claims, $signature] = explode('.', $valid);
        $signature[0] = $signature[0] === 'A' ? 'B' : 'A';
        $this->withHeader('Authorization', 'Bearer '.$header.'.'.$claims.'.'.$signature)
            ->postJson(self::URL)->assertUnauthorized();
        Queue::assertNotPushed(SyncTrunkrsReportsJob::class);
    }

    public function test_disabled_connection_cannot_be_started_by_github(): void
    {
        $setting = TrunkrsSetting::findOrFail(1);
        $payload = $setting->payload;
        $payload['enabled'] = false;
        $setting->update(['payload' => $payload]);
        $this->withHeader('Authorization', 'Bearer '.$this->token(['event_name' => 'workflow_dispatch']))
            ->postJson(self::URL)->assertConflict();
        Queue::assertNotPushed(SyncTrunkrsReportsJob::class);
        $this->assertNull($setting->fresh()->scheduler_seen_at);
    }
}
