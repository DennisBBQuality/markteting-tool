<?php

namespace Tests\Feature;

use App\Console\Commands\UpgradeNotificationMailStorage;
use App\Jobs\SendAssignmentNotification;
use App\Models\PitboardMailSetting;
use App\Models\PitboardNotification;
use App\Models\TrunkrsConnection;
use App\Models\User;
use App\Services\AssignmentNotifications;
use App\Services\Notifications\MicrosoftMailException;
use App\Services\Notifications\MicrosoftMailSender;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Http::preventStrayRequests();
        Mail::fake();
        config(['pitboard_notifications.url' => 'https://pitboard.example.test']);
        $this->fields = ['tenant_id' => (string) Str::uuid(), 'client_id' => (string) Str::uuid(),
            'delegate_user_id' => (string) Str::uuid(), 'sender_address' => 'pitboard@example.test'];
    }

    private function setupSender(bool $connected = true): PitboardMailSetting
    {
        return PitboardMailSetting::create(['id' => 1, 'payload' => [...$this->fields, 'revision' => (string) Str::uuid(), 'enabled' => false],
            'refresh_token' => $connected ? 'TEST-refresh-secret' : null]);
    }

    private function revision(): string
    {
        return PitboardMailSetting::findOrFail(1)->payload['revision'];
    }

    private function fakeMicrosoft(int $sendStatus = 202, string $scopes = 'Mail.Send.Shared User.Read offline_access', ?string $userId = null): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/devicecode' => Http::response(['device_code' => 'TEST-device-secret', 'user_code' => 'TEST-CODE', 'expires_in' => 900, 'interval' => 5]),
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'TEST-access-secret', 'refresh_token' => 'TEST-rotated-secret', 'scope' => $scopes]),
            'https://graph.microsoft.com/v1.0/me?*' => Http::response(['id' => $userId ?? $this->fields['delegate_user_id'], 'mail' => 'delegate@example.test']),
            'https://graph.microsoft.com/v1.0/me/sendMail' => Http::response([], $sendStatus),
        ]);
    }

    public function test_only_admins_can_configure_sender_and_secrets_never_leave_api_or_plain_database(): void
    {
        $this->getJson('/api/notification-mail')->assertUnauthorized();
        $this->actingAsUser(['rol' => 'lid']);
        foreach (['initialize', 'start', 'poll', 'test', 'enable', 'stop'] as $action) {
            $this->postJson('/api/notification-mail/'.$action)->assertForbidden();
        }
        $this->getJson('/api/notification-mail')->assertForbidden();
        $this->putJson('/api/notification-mail', $this->fields)->assertForbidden();
        $this->actingAsUser(['rol' => 'admin']);
        $this->putJson('/api/notification-mail', [...$this->fields, 'revision' => ''])->assertOk();
        $this->assertFalse(PitboardMailSetting::find(1)->payload['enabled']);
        $this->putJson('/api/notification-mail', [...$this->fields, 'revision' => ''])->assertConflict();
        $setting = PitboardMailSetting::find(1);
        $setting->update(['refresh_token' => 'TEST-secret']);
        $this->assertStringNotContainsString('TEST-secret', DB::table('pitboard_mail_settings')->value('refresh_token'));
        $this->assertStringNotContainsString('sender_address', DB::table('pitboard_mail_settings')->value('payload'));
        $response = $this->getJson('/api/notification-mail')->assertOk()->assertJsonPath('connected', true)->assertJsonPath('enabled', false);
        $this->assertStringNotContainsString('TEST-secret', $response->getContent());
        $this->assertArrayNotHasKey('refresh_token', $setting->toArray());
        Http::assertNothingSent();
    }

    public function test_device_code_is_private_to_session_honors_interval_and_never_enables_mail_automatically(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $this->setupSender(false);
        $this->fakeMicrosoft();
        $rev = $this->revision();
        $this->postJson('/api/notification-mail/start', ['revision' => $rev])->assertUnprocessable();
        $response = $this->postJson('/api/notification-mail/start', ['revision' => $rev, 'consent' => true])->assertOk()->assertJsonPath('user_code', 'TEST-CODE');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->assertStringNotContainsString('TEST-device-secret', $response->getContent());
        $this->postJson('/api/notification-mail/poll', ['revision' => $rev])->assertOk()->assertJsonPath('state', 'pending');
        Http::assertSentCount(1);
        $setting = PitboardMailSetting::find(1);
        $setting->update(['pending' => [...$setting->pending, 'next_at' => time() - 1]]);
        $this->postJson('/api/notification-mail/poll', ['revision' => $rev])->assertOk()->assertJsonPath('state', 'connected');
        $this->assertFalse(app(MicrosoftMailSender::class)->ready());
        $this->assertSame('TEST-rotated-secret', PitboardMailSetting::find(1)->refresh_token);
        $this->assertNull(PitboardMailSetting::find(1)->pending);
        $this->postJson('/api/notification-mail/poll', ['revision' => $rev])->assertConflict();
    }

    public function test_other_admin_cannot_poll_or_observe_code_and_expired_code_is_cleared(): void
    {
        $admin = $this->actingAsUser(['rol' => 'admin']);
        $this->setupSender(false);
        $this->fakeMicrosoft();
        $rev = $this->revision();
        $this->postJson('/api/notification-mail/start', ['revision' => $rev, 'consent' => true])->assertOk();
        $ownerSession = session()->getId();
        $this->withCredentials = false;
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson('/api/notification-mail')->assertJsonPath('pending', null);
        $this->postJson('/api/notification-mail/poll', ['revision' => $rev])->assertConflict();
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), $ownerSession);
        $setting = PitboardMailSetting::find(1);
        $p = $setting->pending;
        $p['expires_at'] = time() - 1;
        $setting->update(['pending' => $p]);
        $this->postJson('/api/notification-mail/poll', ['revision' => $rev])->assertUnprocessable();
        $this->assertNull($setting->fresh()->pending);
        Http::assertSentCount(1);
    }

    public function test_test_mail_uses_shared_sender_and_only_current_admin_then_requires_receipt_confirmation(): void
    {
        $admin = $this->actingAsUser(['rol' => 'admin']);
        $this->setupSender();
        $this->fakeMicrosoft();
        $rev = $this->revision();
        $this->postJson('/api/notification-mail/enable', ['revision' => $rev, 'test_id' => (string) Str::uuid(), 'received_correct_sender' => true])->assertUnprocessable();
        $this->postJson('/api/notification-mail/test', ['revision' => $rev, 'confirm' => true, 'recipient' => 'ignored@example.test'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/me/sendMail')
            && $r['message']['toRecipients'][0]['emailAddress']['address'] === $admin->email
            && $r['message']['from']['emailAddress'] === ['name' => 'BBQuality Pitboard', 'address' => 'pitboard@example.test']
            && ! isset($r['message']['sender']));
        $this->assertFalse(app(AssignmentNotifications::class)->emailReady());
        $this->postJson('/api/notification-mail/test', ['revision' => $rev, 'confirm' => true])->assertConflict();
        Http::assertSentCount(3);
        $p = PitboardMailSetting::find(1)->payload;
        $this->postJson('/api/notification-mail/enable', ['revision' => $p['revision'], 'test_id' => $p['test_id']])->assertUnprocessable();
        $this->postJson('/api/notification-mail/enable', ['revision' => $p['revision'], 'test_id' => $p['test_id'], 'received_correct_sender' => true])->assertOk();
        $this->assertTrue(app(AssignmentNotifications::class)->emailReady());
        Mail::assertNothingSent();
    }

    public function test_failed_test_cannot_activate_and_duplicate_request_cannot_send_again(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $this->setupSender();
        $this->fakeMicrosoft(403);
        $rev = $this->revision();
        $this->postJson('/api/notification-mail/test', ['revision' => $rev, 'confirm' => true])->assertUnprocessable()->assertSee('Verzenden als');
        $this->postJson('/api/notification-mail/test', ['revision' => $rev, 'confirm' => true])->assertConflict();
        $p = PitboardMailSetting::find(1)->payload;
        $this->assertSame('uncertain', $p['test_state']);
        $this->postJson('/api/notification-mail/enable', ['revision' => $p['revision'], 'test_id' => $p['test_id'], 'received_correct_sender' => true])->assertUnprocessable();
        Http::assertSentCount(3);
    }

    public function test_unexpected_broad_scope_is_rejected_without_sending(): void
    {
        $setting = $this->setupSender(false);
        $this->fakeMicrosoft(scopes: 'Mail.Send.Shared User.Read Mail.ReadWrite offline_access');
        try {
            app(MicrosoftMailSender::class)->finish($setting, 'TEST-code');
            $this->fail('Broad scope should fail.');
        } catch (MicrosoftMailException) {
            $this->assertNull($setting->fresh()->refresh_token);
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMail'));
    }

    public function test_wrong_identity_is_rejected_without_storing_token(): void
    {
        $setting = $this->setupSender(false);
        $this->fakeMicrosoft(userId: (string) Str::uuid());
        try {
            app(MicrosoftMailSender::class)->finish($setting, 'TEST-code');
            $this->fail('Wrong user should fail.');
        } catch (MicrosoftMailException) {
            $this->assertNull($setting->fresh()->refresh_token);
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMail'));
    }

    public function test_slow_down_is_persisted_and_polling_does_not_issue_early_requests(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $setting = $this->setupSender(false);
        $this->withCookie(config('session.cookie'), session()->getId());
        $setting->update(['pending' => ['device_code' => 'TEST-secret', 'user_code' => 'TEST-CODE',
            'owner' => hash('sha256', session()->getId().'|'.session()->get('userId')), 'expires_at' => time() + 900,
            'interval' => 5, 'next_at' => time() - 1]]);
        Http::fake(['https://login.microsoftonline.com/*' => Http::response(['error' => 'slow_down'], 400)]);
        $this->postJson('/api/notification-mail/poll', ['revision' => $this->revision()])->assertOk()->assertJsonPath('state', 'pending');
        $this->assertSame(10, $setting->fresh()->pending['interval']);
        $this->postJson('/api/notification-mail/poll', ['revision' => $this->revision()])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_activation_requires_same_admin_recent_test_and_csrf_still_applies(): void
    {
        $admin = $this->actingAsUser(['rol' => 'admin']);
        $setting = $this->setupSender();
        $testId = (string) Str::uuid();
        $p = [...$setting->payload, 'test_id' => $testId, 'test_state' => 'accepted', 'test_at' => time() - 86401, 'test_actor' => $admin->id];
        $setting->update(['payload' => $p]);
        $this->postJson('/api/notification-mail/enable', ['revision' => $this->revision(), 'test_id' => $testId, 'received_correct_sender' => true])->assertUnprocessable();
        $p['test_at'] = time();
        $p['test_actor'] = 'TEST-other-admin';
        $setting->update(['payload' => $p]);
        $this->postJson('/api/notification-mail/enable', ['revision' => $this->revision(), 'test_id' => $testId, 'received_correct_sender' => true])->assertUnprocessable();
        $this->app->instance('env', 'production');
        $this->putJson('/api/notification-mail', [...$this->fields, 'revision' => $this->revision()])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_local_runtime_never_connects_or_sends_and_stop_preserves_trunkrs(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $this->setupSender();
        TrunkrsConnection::create(['id' => 1, 'refresh_token' => 'TEST-trunkrs-token']);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->app->instance('env', 'local');
        $this->postJson('/api/notification-mail/start', ['revision' => $this->revision(), 'consent' => true, '_token' => session()->token()])->assertUnprocessable();
        $this->postJson('/api/notification-mail/test', ['revision' => $this->revision(), 'confirm' => true, '_token' => session()->token()])->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson('/api/notification-mail/stop', ['revision' => $this->revision(), '_token' => session()->token()])->assertOk();
        $this->assertNull(PitboardMailSetting::find(1)->refresh_token);
        $this->assertSame('TEST-trunkrs-token', TrunkrsConnection::find(1)->refresh_token);
        config(['pitboard_notifications.email_enabled' => true, 'pitboard_notifications.from_address' => 'smtp@example.test']);
        $this->app->instance('env', 'testing');
        $this->assertFalse(app(AssignmentNotifications::class)->emailReady());
    }

    public function test_new_assignment_sends_once_with_graph_and_existing_link_template(): void
    {
        Bus::fake([SendAssignmentNotification::class]);
        $this->actingAsUser();
        $setting = $this->setupSender();
        $setting->update(['payload' => [...$setting->payload, 'enabled' => true, 'verified_at' => now()->toIso8601String()]]);
        $recipient = User::factory()->create();
        $this->fakeMicrosoft();
        $this->postJson('/api/tasks', ['titel' => 'TEST <script>voorbeeld</script>', 'toegewezen_aan' => [$recipient->id]])->assertOk();
        $notification = PitboardNotification::firstOrFail();
        $job = new SendAssignmentNotification($notification->id);
        $job->handle(app(AssignmentNotifications::class));
        $job->handle(app(AssignmentNotifications::class));
        $this->assertSame('accepted', $notification->fresh()->email_status);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMail')
            && str_contains($r['message']['body']['content'], '/?melding='.$notification->id)
            && ! str_contains($r['message']['body']['content'], '<script>'));
        Mail::assertNothingSent();
    }

    public function test_upgrade_is_additive_idempotent_and_refuses_schema_mismatch(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $users = DB::table('users')->get()->toJson();
        Schema::drop('pitboard_mail_settings');
        DB::table('migrations')->where('migration', UpgradeNotificationMailStorage::MIGRATION)->delete();
        $this->getJson('/api/notification-mail')->assertOk()->assertJsonPath('ready', false);
        $this->postJson('/api/notification-mail/initialize')->assertUnprocessable();
        $this->postJson('/api/notification-mail/initialize', ['confirm' => true])->assertOk();
        $this->postJson('/api/notification-mail/initialize', ['confirm' => true])->assertOk();
        $this->assertSame($users, DB::table('users')->get()->toJson());
        $this->assertTrue(Schema::hasTable('trunkrs_reports'));
        DB::table('migrations')->where('migration', UpgradeNotificationMailStorage::MIGRATION)->delete();
        $this->artisan('pitboard:upgrade-notification-mail-storage')->assertFailed();
        $this->assertTrue(Schema::hasTable('pitboard_mail_settings'));
    }

    public function test_every_role_receives_task_and_project_mail_at_the_current_login_email_without_duplicates(): void
    {
        Bus::fake([SendAssignmentNotification::class]);
        $this->actingAsUser();
        $setting = $this->setupSender();
        $setting->update(['payload' => [...$setting->payload, 'enabled' => true, 'verified_at' => now()->toIso8601String()]]);
        $this->fakeMicrosoft();
        $users = collect(['admin', 'manager', 'lid'])->map(fn ($role) => User::factory()->create(['rol' => $role, 'email' => 'test-'.$role.'@example.test']));
        $this->postJson('/api/tasks', ['titel' => 'TEST team', 'toegewezen_aan' => $users->pluck('id')->all()])->assertOk();
        $this->postJson('/api/projects', ['naam' => 'TEST team', 'medewerkers' => $users->pluck('id')->all()])->assertOk();
        $users->last()->update(['email' => 'test-member-updated@example.test']);
        $this->assertDatabaseCount('pitboard_notifications', 6);
        foreach (PitboardNotification::all() as $notification) {
            $job = new SendAssignmentNotification($notification->id);
            $job->handle(app(AssignmentNotifications::class));
            $job->handle(app(AssignmentNotifications::class));
            $this->assertSame('accepted', $notification->fresh()->email_status);
        }
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMail'));
        $this->assertCount(6, $sent);
        foreach ($users as $user) {
            $this->assertCount(2, $sent->filter(fn ($pair) => $pair[0]['message']['toRecipients'][0]['emailAddress']['address'] === $user->email));
        }
        Mail::assertNothingSent();
    }

    public function test_assignment_sender_waits_for_the_shared_lock_before_network_io(): void
    {
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(75)->andReturn(true);
        $lock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('pitboard-microsoft-mail', 180)->andReturn($lock);
        $ran = false;
        app(MicrosoftMailSender::class)->locked(function () use (&$ran) {
            $ran = true;
        }, 75);
        $this->assertTrue($ran);
        Http::assertNothingSent();
    }
}
