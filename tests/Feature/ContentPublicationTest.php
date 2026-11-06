<?php

namespace Tests\Feature;

use App\Models\ContentConnection;
use App\Models\ContentDelivery;
use App\Models\ContentMedia;
use App\Models\ContentPost;
use App\Models\User;
use App\Services\Content\ContentPublisher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['app.url' => 'https://pitboard.example.test']);
        URL::forceRootUrl('https://pitboard.example.test');
        URL::forceScheme('https');
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function connection(string $provider = 'meta'): ContentConnection
    {
        return ContentConnection::create(['provider' => $provider, 'client_id' => 'FAKE_CLIENT', 'client_secret' => 'FAKE_SECRET', 'access_token' => 'FAKE_TOKEN', 'refresh_token' => 'FAKE_REFRESH', 'expires_at' => now()->addHour(), 'api_version' => 'v25.0',
            'accounts' => [['id' => $provider === 'meta' ? '12345' : 'UC_TEST', 'name' => 'Fictief kanaal', 'access_token' => 'FAKE_PAGE_TOKEN', 'instagram_business_account' => ['id' => '54321', 'username' => 'fictief']]],
            'selected_account' => $provider === 'meta' ? '12345' : 'UC_TEST', 'enabled' => true, 'verified_at' => now(), 'scheduler_seen_at' => now()]);
    }

    private function delivery(string $platform = 'facebook', string $status = 'scheduled'): ContentDelivery
    {
        $user = User::factory()->create(['rol' => 'manager']);
        $p = ContentPost::create(['title' => 'Fictieve testpublicatie', 'owner_id' => $user->id, 'revision' => 1, 'review_status' => 'approved', 'approved_revision' => 1, 'approved_by' => $user->id, 'approved_at' => now()]);
        $provider = $platform === 'youtube' ? 'youtube' : 'meta';
        $c = ContentConnection::where('provider', $provider)->first() ?? $this->connection($provider);
        $d = $p->deliveries()->create(['platform' => $platform, 'text' => 'Alleen testdata', 'media_ids' => [], 'options' => ['format' => 'text'], 'scheduled_at' => now()->subSecond(), 'status' => $status, 'external_id' => (string) Str::uuid(), 'connection_id' => $c->id,
            'account_snapshot' => ['id' => $platform === 'instagram' ? '54321' : $c->selected_account, 'parent_id' => $c->selected_account, 'name' => 'Fictief kanaal']]);
        if ($platform !== 'facebook') {
            if ($platform === 'instagram') {
                $f = UploadedFile::fake()->image('test.jpg', 1080, 1080);
                $bytes = file_get_contents($f->getRealPath());
                $mime = 'image/jpeg';
            } else {
                $bytes = 'synthetic-video-fixture';
                $mime = 'video/mp4';
            }
            $path = 'content-media/'.Str::uuid();
            Storage::disk('local')->put($path, $bytes);
            $m = ContentMedia::create(['name' => 'test', 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $user->id]);
            $d->update(['media_ids' => [$m->id], 'options' => ['format' => $platform === 'instagram' ? 'photo' : 'video', 'title' => 'Fictief recept', 'privacy' => 'private', 'made_for_kids' => false, 'synthetic' => false]]);
        }

        return $d;
    }

    private function scheduleBody(ContentDelivery $d): array
    {
        return ['revision' => 1, 'delivery_ids' => [$d->id], 'account_ids' => [$d->platform => $d->account_snapshot['id']], 'confirmed' => true];
    }

    public function test_scheduling_requires_role_exact_approval_recent_worker_and_confirmed_target(): void
    {
        $this->production();
        $d = $this->delivery('facebook', 'draft');
        $d->update(['scheduled_at' => now()->addHour()]);
        $this->actingAsUser(['rol' => 'lid']);
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertForbidden();
        $this->actingAsUser(['rol' => 'manager']);
        $d->post->update(['approved_revision' => null]);
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertConflict();
        $d->post->update(['approved_revision' => 1]);
        ContentConnection::query()->update(['scheduler_seen_at' => now()->subMinutes(5)]);
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertUnprocessable();
        ContentConnection::query()->update(['scheduler_seen_at' => now()]);
        $body = $this->scheduleBody($d);
        $body['account_ids']['facebook'] = 'ANOTHER';
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $body)->assertConflict();
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertOk()->assertJsonPath('deliveries.0.status', 'scheduled');
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertConflict();
        Http::assertNothingSent();
    }

    public function test_local_environment_never_dispatches_even_if_database_connection_is_enabled(): void
    {
        $d = $this->delivery();
        $this->actingAsUser(['rol' => 'manager']);
        app(ContentPublisher::class)->run($d->id);
        $this->artisan('content:publish-due')->assertSuccessful();
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertUnprocessable();
        $this->assertSame('scheduled', $d->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancellation_stops_waiting_job_and_does_not_reset_started_job(): void
    {
        $this->production();
        $d = $this->delivery();
        $this->actingAsUser(['rol' => 'manager']);
        $this->postJson('/api/content/posts/'.$d->post_id.'/cancel', ['revision' => 1])->assertOk()->assertJsonPath('deliveries.0.status', 'cancelled');
        app(ContentPublisher::class)->run($d->id);
        Http::assertNothingSent();
        $d->update(['status' => 'processing']);
        $this->postJson('/api/content/posts/'.$d->post_id.'/cancel', ['revision' => 1])->assertConflict();
        $this->assertSame('processing', $d->fresh()->status);
    }

    public function test_facebook_requires_confirmation_of_actual_publication_and_never_reposts(): void
    {
        $this->production();
        $d = $this->delivery();
        Http::fake(['*12345/feed' => Http::response(['id' => '12345_987']), '*12345_987*' => Http::response(['id' => '12345_987', 'is_published' => true, 'permalink_url' => 'https://www.facebook.com/12345/posts/987'])]);
        $publisher = app(ContentPublisher::class);
        $publisher->run($d->id);
        $this->assertSame('processing', $d->fresh()->status);
        $publisher->run($d->id);
        $this->travel(1)->minutes();
        $publisher->run($d->id);
        $publisher->run($d->id);
        $this->assertSame('published', $d->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_facebook_timeout_is_unknown_and_is_never_retried_or_logged_with_tokens(): void
    {
        $this->production();
        $d = $this->delivery();
        $writes = 0;
        Http::fake(function () use (&$writes) {
            $writes++;
            throw new ConnectionException('FAKE_TOKEN request timeout');
        });
        app(ContentPublisher::class)->run($d->id);
        $this->travel(11)->minutes();
        app(ContentPublisher::class)->run($d->id);
        $this->assertSame(1, $writes);
        $this->assertSame('unknown', $d->fresh()->status);
        $this->assertStringNotContainsString('FAKE_TOKEN', $d->fresh()->error);
    }

    public function test_stale_claim_is_unknown_and_missed_schedule_does_not_publish_late(): void
    {
        $this->production();
        $d = $this->delivery('facebook', 'submitting');
        $d->update(['submitted_at' => now()->subMinutes(11)]);
        app(ContentPublisher::class)->run($d->id);
        $this->assertSame('unknown', $d->fresh()->status);
        $late = $this->delivery();
        $late->update(['scheduled_at' => now()->subHour()]);
        app(ContentPublisher::class)->run($late->id);
        $this->assertSame('failed', $late->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_instagram_refresh_is_read_only_then_worker_publishes_finished_container_once(): void
    {
        $this->production();
        $d = $this->delivery('instagram');
        Http::fake(['*54321/media' => Http::response(['id' => '777']), '*777?*' => Http::response(['status_code' => 'FINISHED']), '*54321/media_publish' => Http::response(['id' => '888']), '*888?*' => Http::response(['id' => '888', 'permalink' => 'https://www.instagram.com/p/FAKE/'])]);
        $p = app(ContentPublisher::class);
        $p->run($d->id);
        $this->assertSame('777', $d->fresh()->provider_job_id);
        $this->travel(1)->minutes();
        $p->run($d->id, false);
        $this->assertNull($d->fresh()->provider_request_id);
        Http::assertSentCount(2);
        $this->travel(1)->minutes();
        $p->run($d->id);
        $this->assertSame('published', $d->fresh()->status);
        Http::assertSentCount(5);
        $p->run($d->id);
        Http::assertSentCount(5);
    }

    public function test_instagram_unknown_publish_does_not_retry_even_if_container_is_finished(): void
    {
        $this->production();
        $d = $this->delivery('instagram', 'unknown');
        $d->update(['provider_job_id' => '777', 'submitted_at' => now()]);
        Http::fake(['*777?*' => Http::response(['status_code' => 'FINISHED'])]);
        app(ContentPublisher::class)->run($d->id);
        $this->assertSame('unknown', $d->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }

    public function test_youtube_upload_reconciles_visibility_and_processing_before_reporting_success(): void
    {
        $this->production();
        $d = $this->delivery('youtube');
        $uri = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=FAKE';
        Http::fake([
            '*youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC_TEST']]]),
            '*uploadType=resumable*' => Http::response('', 200, ['Location' => $uri]),
            $uri => Http::response(['id' => 'FAKE_VIDEO'], 201),
            '*youtube/v3/videos?part=*' => Http::response(['items' => [['id' => 'FAKE_VIDEO', 'snippet' => ['channelId' => 'UC_TEST'], 'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private'], 'processingDetails' => ['processingStatus' => 'succeeded']]]]),
        ]);
        $p = app(ContentPublisher::class);
        $p->run($d->id);
        $this->assertSame('processing', $d->fresh()->status);
        $this->assertNotSame($uri, DB::table('content_deliveries')->where('id', $d->id)->value('upload_session_url'));
        $this->assertArrayNotHasKey('upload_session_url', $d->fresh()->toArray());
        $this->travel(1)->minutes();
        $p->run($d->id);
        $this->assertSame('published', $d->fresh()->status);
        $this->assertSame('https://www.youtube.com/watch?v=FAKE_VIDEO', $d->fresh()->published_url);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['status']['privacyStatus'] === 'private' && $r['status']['selfDeclaredMadeForKids'] === false);
    }

    public function test_youtube_rejects_untrusted_upload_location_without_leaking_token(): void
    {
        $this->production();
        $d = $this->delivery('youtube');
        Http::fake(['*youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC_TEST']]]), '*uploadType=resumable*' => Http::response('', 200, ['Location' => 'https://attacker.example/upload'])]);
        app(ContentPublisher::class)->run($d->id);
        $this->assertSame('unknown', $d->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_media_links_are_signed_expiring_and_bound_to_selected_delivery(): void
    {
        $d = $this->delivery('instagram', 'processing');
        $id = $d->media_ids[0];
        $url = URL::temporarySignedRoute('content.media.share', now()->addMinute(), ['delivery' => $d->id, 'media' => $id]);
        $this->get($url)->assertOk();
        $this->get(str_replace('signature=', 'signature=bad', $url))->assertForbidden();
        $other = $this->delivery('instagram', 'draft');
        $wrong = URL::temporarySignedRoute('content.media.share', now()->addMinute(), ['delivery' => $d->id, 'media' => $other->media_ids[0]]);
        $this->get($wrong)->assertNotFound();
        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_tiktok_and_unsupported_formats_fail_without_any_side_effect(): void
    {
        $this->production();
        $d = $this->delivery('facebook', 'draft');
        $d->update(['platform' => 'tiktok', 'scheduled_at' => now()->addHour()]);
        $this->actingAsUser(['rol' => 'manager']);
        $body = $this->scheduleBody($d);
        $body['account_ids'] = ['facebook' => '12345'];
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $body)->assertUnprocessable();
        $d->update(['platform' => 'facebook', 'options' => ['format' => 'video']]);
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $this->scheduleBody($d))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_admin_only_and_cannot_be_changed_with_pending_jobs(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $c = $this->connection();
        $response = $this->getJson('/api/content/connections')->assertOk();
        foreach (['FAKE_SECRET', 'FAKE_TOKEN', 'FAKE_PAGE_TOKEN', 'FAKE_REFRESH'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, json_encode(DB::table('content_connections')->first()));
        }
        $this->delivery();
        $this->putJson('/api/content/connections/meta', ['client_id' => 'CHANGED', 'api_version' => 'v25.0'])->assertConflict();
        $this->postJson('/api/content/connections/meta/start')->assertOk();
        $this->actingAsUser(['rol' => 'manager']);
        $this->getJson('/api/content/connections')->assertForbidden();
    }

    public function test_oauth_state_is_one_use_user_bound_and_checks_expiry_before_network(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $this->connection('youtube');
        $url = $this->postJson('/api/content/connections/youtube/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $callback = '/api/content/connections/youtube/callback?code=FAKE_CODE&state=';
        $this->get($callback.'bad')->assertForbidden();
        $this->get($callback.$params['state'])->assertForbidden();
        $url = $this->postJson('/api/content/connections/youtube/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        // Simulate a different signed-in administrator while preserving the OAuth session.
        $this->actingAsUser(['rol' => 'admin']);
        $this->get($callback.$params['state'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_youtube_authorization_exchanges_code_and_discovers_account_without_exposing_credentials(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $c = $this->connection('youtube');
        $url = $this->postJson('/api/content/connections/youtube/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'NEW_FAKE_ACCESS', 'refresh_token' => 'NEW_FAKE_REFRESH', 'expires_in' => 3600]), '*youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC_TEST', 'snippet' => ['title' => 'Fictief kanaal']]]])]);
        $this->get('/api/content/connections/youtube/callback?code=FAKE&state='.$params['state'])->assertRedirect('/?content_connection=ready');
        $this->assertFalse($c->fresh()->enabled);
        $this->assertSame('NEW_FAKE_REFRESH', $c->fresh()->refresh_token);
        $this->getJson('/api/content/connections')->assertOk()->assertDontSee('NEW_FAKE_ACCESS')->assertDontSee('NEW_FAKE_REFRESH');
        Http::assertSentCount(2);
    }

    public function test_reauthorization_cannot_redirect_pending_deliveries_to_a_new_account(): void
    {
        $this->production();
        $this->actingAsUser(['rol' => 'admin']);
        $d = $this->delivery();
        $c = ContentConnection::find($d->connection_id);
        $accounts = $c->accounts;
        $accounts[] = ['id' => '99999', 'name' => 'Ander fictief account'];
        $c->update(['accounts' => $accounts, 'selected_account' => null, 'enabled' => false]);
        $this->putJson('/api/content/connections/meta/activate', ['account_id' => '99999', 'enabled' => true, 'confirmed' => true])->assertConflict();
        $this->putJson('/api/content/connections/meta/activate', ['account_id' => '12345', 'enabled' => true, 'confirmed' => true])->assertOk();
        $this->assertTrue($c->fresh()->enabled);
        Http::assertNothingSent();
    }

    public function test_changed_media_is_rejected_before_any_upload(): void
    {
        $this->production();
        $d = $this->delivery('instagram');
        $m = ContentMedia::find($d->media_ids[0]);
        Storage::disk('local')->put($m->path, 'changed');
        app(ContentPublisher::class)->run($d->id);
        $this->assertSame('failed', $d->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_multi_channel_schedule_rolls_back_every_channel_when_one_is_invalid(): void
    {
        $this->production();
        $this->actingAsUser(['rol' => 'manager']);
        $d = $this->delivery('facebook', 'draft');
        $d->update(['scheduled_at' => now()->addHour()]);
        $second = $d->replicate(['id', 'external_id']);
        $second->id = (string) Str::uuid();
        $second->external_id = (string) Str::uuid();
        $second->platform = 'instagram';
        $second->save();
        $body = $this->scheduleBody($d);
        $body['delivery_ids'][] = $second->id;
        $body['account_ids']['instagram'] = '54321';
        $this->postJson('/api/content/posts/'.$d->post_id.'/schedule', $body)->assertUnprocessable();
        $this->assertSame('draft', $d->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_youtube_unknown_upload_queries_existing_session_and_never_uploads_bytes_again(): void
    {
        $this->production();
        $d = $this->delivery('youtube', 'unknown');
        $uri = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=FAKE';
        $d->update(['upload_session_url' => $uri]);
        Http::fake([$uri => Http::response(['id' => 'FAKE_VIDEO'], 201), '*youtube/v3/videos?part=*' => Http::response(['items' => [['id' => 'FAKE_VIDEO', 'snippet' => ['channelId' => 'UC_TEST'], 'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'private'], 'processingDetails' => ['processingStatus' => 'succeeded']]]])]);
        app(ContentPublisher::class)->run($d->id, false);
        $this->assertSame('published', $d->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->body() === '' && str_starts_with($r->header('Content-Range')[0], 'bytes */'));
    }

    public function test_youtube_visibility_mismatch_is_not_reported_as_published(): void
    {
        $this->production();
        $d = $this->delivery('youtube', 'processing');
        $d->update(['provider_request_id' => 'FAKE_VIDEO']);
        Http::fake(['*youtube/v3/videos?part=*' => Http::response(['items' => [['id' => 'FAKE_VIDEO', 'snippet' => ['channelId' => 'UC_TEST'], 'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'public'], 'processingDetails' => ['processingStatus' => 'succeeded']]]])]);
        app(ContentPublisher::class)->run($d->id,false);
        $this->assertSame('unknown',$d->fresh()->status);
        $this->assertNull($d->fresh()->published_url);
        Http::assertSentCount(1);
    }
}
