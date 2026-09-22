<?php

namespace Tests\Feature;

use App\Console\Commands\UpgradeTrunkrsSettings;
use App\Jobs\SyncTrunkrsReportsJob;
use App\Models\TrunkrsConnection;
use App\Models\TrunkrsSetting;
use App\Services\Trunkrs\TrunkrsMicrosoftAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrunkrsSettingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/settings/trunkrs';

    private function fields(): array
    {
        return ['revision' => '', 'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'client_id' => '22222222-2222-2222-2222-222222222222',
            'reader_user_id' => '33333333-3333-3333-3333-333333333333',
            'mailbox' => 'owner@example.test', 'folder_id' => 'test-report-folder'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('trunkrs.enabled', false);
    }

    private function prepare(): string
    {
        $this->withCredentials();
        $this->actingAsUser(['rol' => 'admin']);
        $this->putJson(self::URL, $this->fields())->assertOk();

        return $this->getJson(self::URL)->assertOk()->json('revision');
    }

    private function microsoft(array $identity = [], string $folder = 'Trunkrs not deliverd', string $scope = 'Mail.Read User.Read'): void
    {
        Http::fake([
            'login.microsoftonline.com/*/devicecode' => Http::response([
                'device_code' => 'secret-device', 'user_code' => 'TEST-CODE', 'interval' => 5, 'expires_in' => 900,
                'verification_uri' => 'https://malicious.example.test/',
            ]),
            'login.microsoftonline.com/*/token' => Http::response([
                'access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'scope' => $scope,
            ]),
            'graph.microsoft.com/v1.0/me?*' => Http::response($identity + [
                'id' => $this->fields()['reader_user_id'], 'mail' => 'owner@example.test',
            ]),
            'graph.microsoft.com/v1.0/me/mailFolders/test-report-folder?*' => Http::response([
                'id' => 'test-report-folder', 'displayName' => $folder, 'parentFolderId' => 'service-folder',
            ]),
            'graph.microsoft.com/v1.0/me/mailFolders/service-folder?*' => Http::response([
                'id' => 'service-folder', 'displayName' => 'Klantenservice', 'parentFolderId' => 'inbox-folder',
            ]),
            'graph.microsoft.com/v1.0/me/mailFolders/inbox?*' => Http::response(['id' => 'inbox-folder']),
        ]);
    }

    private function start(string $revision): void
    {
        $response = $this->postJson(self::URL.'/connect', ['consent' => true, 'revision' => $revision])
            ->assertOk()->assertJsonPath('verification_uri', 'https://microsoft.com/devicelogin');
        $this->assertStringNotContainsString('secret-device', $response->getContent());
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function allowPoll(): void
    {
        $setting = TrunkrsSetting::findOrFail(1);
        $pending = $setting->pending;
        $pending['next_at'] = time() - 1;
        $setting->update(['pending' => $pending]);
    }

    public function test_every_endpoint_requires_an_active_admin_and_csrf(): void
    {
        $routes = [['GET', ''], ['PUT', ''], ['POST', '/connect'], ['POST', '/poll'], ['POST', '/stop'], ['POST', '/sync'], ['POST', '/initialize']];
        foreach ($routes as [$method, $path]) {
            $this->json($method, self::URL.$path)->assertUnauthorized();
        }
        foreach (['manager', 'lid'] as $role) {
            $this->actingAsUser(['rol' => $role]);
            foreach ($routes as [$method, $path]) {
                $this->json($method, self::URL.$path)->assertForbidden();
            }
        }
        $user = $this->actingAsUser(['rol' => 'admin']);
        $user->update(['actief' => false]);
        $this->postJson(self::URL.'/connect')->assertUnauthorized();
        $this->actingAsUser(['rol' => 'admin']);
        $this->app->instance('env', 'production');
        $this->putJson(self::URL, $this->fields())->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_settings_are_encrypted_and_stale_or_invalid_updates_are_rejected(): void
    {
        $revision = $this->prepare();
        $raw = DB::table('trunkrs_settings')->value('payload');
        $this->assertStringNotContainsString('owner@example.test', $raw);
        $this->assertFalse(app(TrunkrsMicrosoftAuth::class)->configuration->get('enabled'));
        $this->putJson(self::URL, $this->fields())->assertConflict();
        $this->putJson(self::URL, ['revision' => $revision, 'tenant_id' => 'https://evil.example.test'] + $this->fields())->assertUnprocessable();
        $this->postJson(self::URL.'/connect', ['revision' => $revision, 'consent' => false])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_login_is_session_bound_and_reuses_the_existing_pending_code(): void
    {
        $revision = $this->prepare();
        $this->microsoft();
        $this->start($revision);
        $this->start($revision);
        Http::assertSentCount(1);
        $raw = DB::table('trunkrs_settings')->value('pending');
        $this->assertStringNotContainsString('secret-device', $raw);
        $this->getJson(self::URL)->assertOk()->assertDontSee('secret-device');
        $this->postJson(self::URL.'/poll')->assertOk()->assertJsonPath('state', 'pending');
        Http::assertSentCount(1);
        $this->withCredentials = false;
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson(self::URL)->assertJsonPath('pending', null);
        $this->postJson(self::URL.'/poll')->assertConflict();
        $this->postJson(self::URL.'/connect', ['revision' => $revision, 'consent' => true])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_successful_login_verifies_identity_and_folder_before_enabling_and_supports_stopping(): void
    {
        $revision = $this->prepare();
        $this->microsoft();
        $this->start($revision);
        $this->allowPoll();
        $this->postJson(self::URL.'/poll')->assertOk()->assertJsonPath('state', 'connected');
        $this->assertStringNotContainsString('secret-refresh', DB::table('trunkrs_connections')->value('refresh_token'));
        $this->getJson(self::URL)->assertOk()->assertJsonPath('status.configured', true)
            ->assertJsonPath('status.enabled', true)->assertJsonPath('scheduler_recent', false)->assertDontSee('secret-');
        Queue::fake();
        $this->postJson(self::URL.'/sync')->assertAccepted();
        Queue::assertPushed(SyncTrunkrsReportsJob::class);
        $this->postJson(self::URL.'/stop')->assertOk();
        $this->assertNull(TrunkrsConnection::find(1)->refresh_token);
        $this->assertNull(TrunkrsSetting::find(1)->pending);
        $this->getJson(self::URL)->assertJsonPath('status.enabled', false);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages'));
    }

    public function test_wrong_folder_does_not_store_tokens_or_enable_reading(): void
    {
        $revision = $this->prepare();
        $this->microsoft(folder: 'Private');
        $this->start($revision);
        $this->allowPoll();
        $this->postJson(self::URL.'/poll')->assertUnprocessable();
        $this->assertDatabaseCount('trunkrs_connections', 0);
        $this->assertNull(TrunkrsSetting::find(1)->pending);
        $this->getJson(self::URL)->assertJsonPath('status.enabled', false);
    }

    public function test_microsoft_slow_down_is_persisted_and_expiry_does_not_depend_on_browser_polling(): void
    {
        $revision = $this->prepare();
        Http::fake([
            'login.microsoftonline.com/*/devicecode' => Http::response([
                'device_code' => 'secret-device', 'user_code' => 'TEST-CODE', 'interval' => 5, 'expires_in' => 900,
            ]),
            'login.microsoftonline.com/*/token' => Http::response(['error' => 'slow_down'], 400),
        ]);
        $this->start($revision);
        $this->allowPoll();
        $this->postJson(self::URL.'/poll')->assertOk()->assertJsonPath('state', 'pending');
        $this->assertSame(10, TrunkrsSetting::find(1)->pending['interval']);
        $this->postJson(self::URL.'/poll')->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('trunkrs_connections', 0);
    }

    public function test_background_sync_uses_stored_configuration_without_editing_environment(): void
    {
        $revision = $this->prepare();
        $this->microsoft();
        $this->start($revision);
        $this->allowPoll();
        $this->postJson(self::URL.'/poll')->assertOk();
        Http::fake(['graph.microsoft.com/v1.0/me/mailFolders/test-report-folder/messages?*' => Http::response(['value' => []])]);
        $this->artisan('trunkrs:sync')->assertSuccessful();
        $this->getJson(self::URL)->assertJsonPath('scheduler_recent', true);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/me/mailFolders/test-report-folder/messages?'));
        $this->assertFalse(config('trunkrs.enabled'));
        $this->assertNotNull(TrunkrsConnection::find(1)->last_checked_at);
    }

    public function test_wrong_account_does_not_read_folder_metadata(): void
    {
        $revision = $this->prepare();
        $this->microsoft(identity: ['id' => '44444444-4444-4444-4444-444444444444']);
        $this->start($revision);
        $this->allowPoll();
        $this->postJson(self::URL.'/poll')->assertUnprocessable();
        $this->assertDatabaseCount('trunkrs_connections', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/mailFolders/'));
    }

    public function test_expired_login_and_local_runtime_are_blocked(): void
    {
        $revision = $this->prepare();
        $this->microsoft();
        $this->start($revision);
        $setting = TrunkrsSetting::find(1);
        $pending = $setting->pending;
        $pending['expires_at'] = time() - 1;
        $setting->update(['pending' => $pending]);
        $this->postJson(self::URL.'/poll')->assertUnprocessable();
        $this->app->instance('env', 'local');
        $this->postJson(self::URL.'/connect', ['revision' => $revision, 'consent' => true, '_token' => session()->token()])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_setup_respects_sync_lock_and_missing_migration_is_explicit(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        $lock = Cache::lock('trunkrs-sync', 600);
        $lock->get();
        try {
            $this->putJson(self::URL, $this->fields())->assertConflict();
        } finally {
            $lock->release();
        }
        Schema::drop('trunkrs_settings');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('ready', false);
        $this->putJson(self::URL, $this->fields())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_additive_release_hook_is_idempotent_and_preserves_existing_data(): void
    {
        $this->actingAsUser();
        Schema::drop('trunkrs_settings');
        DB::table('migrations')->where('migration', UpgradeTrunkrsSettings::MIGRATION)->delete();
        $tables = ['users', 'projects', 'tasks', 'calendar_items', 'notes', 'trunkrs_connections', 'trunkrs_reports'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $before = $snapshot();
        $this->artisan('pitboard:upgrade-trunkrs-settings')->assertSuccessful();
        $this->artisan('pitboard:upgrade-trunkrs-settings')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseCount('trunkrs_settings', 0);
        $this->assertSame(1, DB::table('migrations')->where('migration', UpgradeTrunkrsSettings::MIGRATION)->count());
        DB::table('migrations')->where('migration', UpgradeTrunkrsSettings::MIGRATION)->delete();
        $this->artisan('pitboard:upgrade-trunkrs-settings')->assertFailed();
    }

    public function test_admin_can_initialize_only_missing_trunkrs_tables_without_hosting_access(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        foreach (['trunkrs_settings', 'trunkrs_reports', 'trunkrs_connections'] as $table) {
            Schema::drop($table);
        }
        DB::table('migrations')->whereIn('migration', [UpgradeTrunkrsSettings::MIGRATION, '2026_09_11_160000_create_trunkrs_reports_tables'])->delete();
        $before = DB::table('users')->get()->toJson();
        $this->postJson(self::URL.'/initialize')->assertUnprocessable();
        $this->postJson(self::URL.'/initialize', ['confirm' => true, 'command' => 'migrate:fresh'])->assertOk();
        $this->assertSame($before, DB::table('users')->get()->toJson());
        $this->assertDatabaseCount('trunkrs_reports', 0);
        $this->postJson(self::URL.'/initialize', ['confirm' => true])->assertOk();
        $this->getJson(self::URL)->assertJsonPath('ready', true);
    }
}
