<?php

namespace Tests\Feature;

use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\TrunkrsCheckStatus;
use App\Services\Trunkrs\TrunkrsSync;
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

    public function test_accepted_check_is_not_completion_and_polling_requires_signed_identity(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())->postJson(self::URL)->assertStatus(202);
        $id = $response->json('check_id');
        Queue::assertPushed(SyncTrunkrsReportsJob::class, fn ($job) => $job->checkId === $id);
        $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('state', 'queued')->assertJsonMissingPath('mailbox_completed');
        $this->withHeader('Authorization', '')->getJson(self::URL.'/'.$id)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token(['ref' => 'refs/heads/other']))
            ->getJson(self::URL.'/'.$id)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson(self::URL.'/11111111-1111-4111-8111-111111111111')->assertNotFound();
    }

    private function report(?string $day, string $received): void
    {
        TrunkrsReport::create([
            'message_hash' => hash('sha256', $received), 'content_hash' => hash('sha256', (string) $day),
            'report_date' => $day, 'received_at' => $received, 'shipment_count' => 0,
            'shipments' => [], 'parser_version' => 'test',
        ]);
    }

    public function test_job_records_its_own_completion_and_only_safe_report_evidence(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(8, 0));
        $this->report('2026-09-25', '2026-09-26T04:10:00Z');
        TrunkrsConnection::create(['id' => 1, 'refresh_token' => 'private-token', 'last_checked_at' => now()]);
        $status = app(TrunkrsCheckStatus::class);
        $id = $status->create();
        $other = $status->create();
        $sync = $this->mock(TrunkrsSync::class, fn ($mock) => $mock->shouldReceive('run')->once()->andReturn('ok'));
        (new SyncTrunkrsReportsJob($id))->handle($sync);
        $result = $status->get($id);
        $this->assertSame('completed', $result['state']);
        $this->assertTrue($result['mailbox_completed']);
        $this->assertTrue($result['report_current']);
        $this->assertSame('2026-09-25', $result['report_date']);
        $this->assertSame('queued', $status->get($other)['state']);
        $this->assertArrayNotHasKey('shipments', $result);
        $this->assertArrayNotHasKey('shipment_count', $result);
        $this->assertStringNotContainsString('private-token', json_encode($result));
    }

    public function test_successful_scan_without_todays_report_never_claims_a_current_report(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(8, 0));
        $status = app(TrunkrsCheckStatus::class);
        $id = $status->create();
        $status->started($id);
        $status->finished($id, 'ok');
        $this->assertFalse($status->get($id)['report_current']);
        $this->report('2026-09-24', '2026-09-25T04:10:00Z');
        $status->finished($id, 'ok');
        $this->assertFalse($status->get($id)['report_current']);
        // Even today's empty mail has no proven delivery date.
        $this->report(null, '2026-09-26T04:10:00Z');
        $status->finished($id, 'ok');
        $this->assertFalse($status->get($id)['report_current']);
    }

    public function test_failures_and_overlapping_checks_never_reuse_old_success(): void
    {
        TrunkrsConnection::create(['id' => 1, 'last_checked_at' => now()->subDay(), 'retry_at' => now()->addMinutes(10)]);
        foreach (['busy', 'waiting', 'authorization', 'network', 'invalid_report'] as $reason) {
            $status = app(TrunkrsCheckStatus::class);
            $id = $status->create();
            $status->started($id);
            $status->finished($id, $reason);
            $this->assertSame('failed', $status->get($id)['state']);
            $this->assertFalse($status->get($id)['mailbox_completed']);
        }
    }

    public function test_unexpected_exception_is_recorded_without_leaking_provider_details(): void
    {
        $status = app(TrunkrsCheckStatus::class);
        $id = $status->create();
        $sync = $this->mock(TrunkrsSync::class, fn ($mock) => $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('private-secret')));
        (new SyncTrunkrsReportsJob($id))->handle($sync);
        $this->assertSame('internal', $status->get($id)['result']);
        $this->assertSame('failed', $status->get($id)['state']);
        $this->assertStringNotContainsString('private-secret', json_encode($status->get($id)));
    }
}
