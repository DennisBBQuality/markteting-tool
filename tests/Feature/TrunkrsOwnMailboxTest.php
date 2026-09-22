<?php

namespace Tests\Feature;

use App\Services\Trunkrs\TrunkrsException;
use App\Services\Trunkrs\TrunkrsMicrosoftAuth;
use App\Services\Trunkrs\TrunkrsSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrunkrsOwnMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'trunkrs.enabled' => true, 'trunkrs.mailbox_mode' => 'own',
            'trunkrs.tenant_id' => '11111111-1111-1111-1111-111111111111',
            'trunkrs.client_id' => '22222222-2222-2222-2222-222222222222',
            'trunkrs.reader_user_id' => '33333333-3333-3333-3333-333333333333',
            'trunkrs.mailbox' => 'owner@example.test', 'trunkrs.folder_id' => 'test-report-folder',
        ]);
        Http::preventStrayRequests();
    }

    private function fakeMicrosoft(string $scope = 'Mail.Read User.Read', array $identity = [], array $responses = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses + [
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'scope' => $scope,
            ]),
            'graph.microsoft.com/v1.0/me?*' => Http::response($identity + [
                'id' => config('trunkrs.reader_user_id'), 'userPrincipalName' => 'owner@example.test',
            ]),
        ]);
    }

    public function test_own_mailbox_login_and_refresh_use_only_delegated_read_scopes(): void
    {
        $this->fakeMicrosoft(responses: [
            'login.microsoftonline.com/*/oauth2/v2.0/devicecode' => Http::response(['device_code' => 'fake-device']),
        ]);
        $auth = app(TrunkrsMicrosoftAuth::class);
        $auth->startDeviceLogin();
        $this->assertSame('connected', $auth->finishDeviceLogin('fake-device'));
        $this->assertSame('fake-access', $auth->accessToken());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/devicecode') && $r['scope'] === $auth->scopes());
        Http::assertSent(fn ($r) => ($r['grant_type'] ?? null) === 'refresh_token' && $r['scope'] === $auth->scopes());
        $this->assertStringNotContainsString('Shared', $auth->scopes());
        $this->assertStringNotContainsString('fake-refresh', DB::table('trunkrs_connections')->value('refresh_token'));
    }

    public function test_wrong_account_or_extra_permissions_never_store_a_connection(): void
    {
        foreach ([
            ['Mail.Read User.Read', ['id' => '44444444-4444-4444-4444-444444444444']],
            ['Mail.Read User.Read', ['userPrincipalName' => 'admin@example.test']],
            ['Mail.Read.Shared User.Read', []],
            ['Mail.Read Mail.Read.Shared User.Read', []],
            ['Mail.Read Mail.ReadWrite User.Read', []],
            ['Mail.Read Mail.Send User.Read', []],
            ['Mail.Read', []],
        ] as [$scope, $identity]) {
            $this->fakeMicrosoft($scope, $identity);
            try {
                app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('fake-device');
                $this->fail('Invalid identity or scope accepted');
            } catch (TrunkrsException $e) {
                $this->assertSame('scope', $e->reason);
            }
            $this->assertDatabaseCount('trunkrs_connections', 0);
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/mailFolders/'));
        }
    }

    public function test_primary_mail_address_can_differ_from_sign_in_name(): void
    {
        $this->fakeMicrosoft(identity: ['userPrincipalName' => 'owner@tenant.example.test', 'mail' => 'OWNER@example.test']);
        $this->assertSame('connected', app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('fake-device'));
    }

    public function test_switching_modes_requires_new_authorization(): void
    {
        $auth = app(TrunkrsMicrosoftAuth::class);
        $this->fakeMicrosoft();
        $auth->finishDeviceLogin('fake-device');
        $ownHash = $auth->fingerprint();
        config()->set('trunkrs.mailbox_mode', 'shared');
        $this->assertNotSame($ownHash, $auth->fingerprint());
        Http::swap(new Factory);
        Http::fake();
        $this->assertSame('authorization', app(TrunkrsSync::class)->run());
        Http::assertNothingSent();
    }

    public function test_invalid_mode_fails_closed_and_shared_fingerprint_stays_compatible(): void
    {
        $auth = app(TrunkrsMicrosoftAuth::class);
        config()->set('trunkrs.mailbox_mode', 'shared');
        $legacy = array_map(fn ($key) => config('trunkrs.'.$key), ['tenant_id', 'client_id', 'reader_user_id', 'mailbox', 'folder_id']);
        $this->assertSame(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)), $auth->fingerprint());
        config()->set('trunkrs.mailbox_mode', 'typo');
        Http::fake();
        $this->assertSame('configuration', app(TrunkrsSync::class)->run());
        Http::assertNothingSent();
    }

    public function test_declining_whole_mailbox_consent_does_not_start_login(): void
    {
        Http::fake();
        $this->artisan('trunkrs:connect')
            ->expectsConfirmation('Is deze mailboxbrede leestoegang expliciet goedgekeurd en is de Trunkrs-rapportmap gecontroleerd?', false)
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('trunkrs_connections', 0);
    }

    public function test_own_mailbox_import_only_reads_selected_folder_and_matching_report_attachments(): void
    {
        $base = 'https://graph.microsoft.com/v1.0/me/mailFolders/test-report-folder/messages';
        $message = ['id' => 'report', 'receivedDateTime' => '2026-09-22T04:00:00Z',
            'from' => ['emailAddress' => ['address' => config('trunkrs.sender')]],
            'subject' => config('trunkrs.subject'), 'hasAttachments' => true];
        $this->fakeMicrosoft(responses: [
            $base.'?*' => Http::response(['value' => [
                $message,
                array_replace($message, ['id' => 'other-subject', 'subject' => 'Private message']),
                array_replace($message, ['id' => 'other-sender', 'from' => ['emailAddress' => ['address' => 'other@example.test']]]),
            ]]),
            $base.'/report/attachments?*' => Http::response(['value' => [[
                'id' => 'csv', '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'report.csv', 'size' => 200,
            ]]]),
            $base.'/report/attachments/csv/$value' => Http::response(
                '"","Date Date","Merchant Name","Trunkrs Nr","Barcode","Status","Reason Code"'."\n"
                .'"1","2026-09-21","TEST MERCHANT","TEST-1","TEST-CODE","TEST_STATUS",""'."\n"
            ),
        ]);
        app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('fake-device');
        $this->assertSame('ok', app(TrunkrsSync::class)->run());
        $this->assertDatabaseCount('trunkrs_reports', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.microsoft.com')
            && ($r->method() !== 'GET' || (! str_starts_with($r->url(), 'https://graph.microsoft.com/v1.0/me?')
                && ! str_starts_with($r->url(), $base))));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/other-') || str_contains($r->url(), 'body'));
    }

    public function test_own_mode_rejects_pagination_to_other_folders_or_mailbox_wide_messages(): void
    {
        foreach (['https://graph.microsoft.com/v1.0/me/messages?$skiptoken=x',
            'https://graph.microsoft.com/v1.0/me/mailFolders/other/messages?$skiptoken=x'] as $next) {
            $this->fakeMicrosoft(responses: [
                'graph.microsoft.com/v1.0/me/mailFolders/test-report-folder/messages?*' => Http::response([
                    'value' => [], '@odata.nextLink' => $next,
                ]),
            ]);
            app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('fake-device');
            $this->assertSame('provider', app(TrunkrsSync::class)->run());
            Http::assertNotSent(fn ($r) => $r->url() === $next);
        }
    }
}
