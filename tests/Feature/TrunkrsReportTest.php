<?php

namespace Tests\Feature;

use App\Models\TrunkrsConnection;
use App\Models\TrunkrsReport;
use App\Services\Trunkrs\TrunkrsDashboard;
use App\Services\Trunkrs\TrunkrsException;
use App\Services\Trunkrs\TrunkrsImporter;
use App\Services\Trunkrs\TrunkrsMicrosoftAuth;
use App\Services\Trunkrs\TrunkrsReportParser;
use App\Services\Trunkrs\TrunkrsSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class TrunkrsReportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $date = '2026-09-09', string $status = 'EXCEPTION_SHIPMENT_CANCELLED_BY_SENDER'): string
    {
        return '"","Date Date","Merchant Name","Trunkrs Nr","Barcode","Status","Reason Code"'."\n"
            .'"1","'.$date.'","TEST MERCHANT","TEST-0001","TEST-BARCODE","'.$status.'",""'."\n";
    }

    private function message(string $id = 'synthetic-message', string $received = '2026-09-10T04:02:03Z'): array
    {
        return ['id' => $id, 'receivedDateTime' => $received, 'subject' => config('trunkrs.subject'),
            'from' => ['emailAddress' => ['address' => config('trunkrs.sender')]], 'hasAttachments' => true];
    }

    private function setupReader(): void
    {
        config()->set([
            'trunkrs.enabled' => true, 'trunkrs.tenant_id' => '11111111-1111-1111-1111-111111111111',
            'trunkrs.client_id' => '22222222-2222-2222-2222-222222222222',
            'trunkrs.reader_user_id' => '33333333-3333-3333-3333-333333333333',
            'trunkrs.mailbox' => 'reports-owner@example.test', 'trunkrs.folder_id' => 'test-reports-folder-only',
        ]);
        TrunkrsConnection::create(['id' => 1, 'refresh_token' => 'test-refresh',
            'configuration_hash' => app(TrunkrsMicrosoftAuth::class)->fingerprint()]);
    }

    private function fakeGraph(array $messages, mixed $attachment = null, array $extra = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($extra + [
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'test-access', 'refresh_token' => 'test-rotated', 'scope' => 'Mail.Read.Shared User.Read',
            ]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['id' => config('trunkrs.reader_user_id'), 'userPrincipalName' => 'reader@example.test']),
            'graph.microsoft.com/*/attachments/test-file/$value' => $attachment ?? Http::response($this->csv()),
            'graph.microsoft.com/*/attachments*' => Http::response(['value' => [
                ['id' => 'test-file', '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'report.csv', 'size' => 400, 'isInline' => false],
            ]]),
            'graph.microsoft.com/*/messages*' => Http::response(['value' => $messages]),
        ]);
    }

    public function test_report_routes_require_an_active_user_and_do_not_expose_mail_or_credentials(): void
    {
        $this->getJson('/api/trunkrs/summary')->assertUnauthorized();
        $this->getJson('/api/trunkrs/reports')->assertUnauthorized();
        $this->setupReader();
        app(TrunkrsImporter::class)->import($this->message(), $this->csv(), 'report.csv');
        $this->actingAsUser(['rol' => 'lid']);
        $response = $this->getJson('/api/trunkrs/summary')->assertOk()
            ->assertJsonPath('title', 'Niet bezorgd Trunkrs')->assertJsonPath('report.shipment_count', 1)
            ->assertJsonPath('shipments.0.status', 'EXCEPTION_SHIPMENT_CANCELLED_BY_SENDER');
        $this->assertStringNotContainsString('test-refresh', $response->getContent());
        $this->assertStringNotContainsString('reports-owner@example.test', $response->getContent());
        $this->assertStringNotContainsString('TEST-BARCODE', DB::table('trunkrs_reports')->value('shipments'));
        $this->assertStringNotContainsString('test-refresh', DB::table('trunkrs_connections')->value('refresh_token'));
        $id = TrunkrsReport::first()->id;
        $this->getJson('/api/trunkrs/reports/'.$id)->assertOk()->assertJsonPath('shipments.0.trunkrs_number', 'TEST-0001');
        $this->getJson('/api/trunkrs/reports/'.$id.'?page=-1')->assertUnprocessable();
        $this->getJson('/api/trunkrs/reports')->assertOk()->assertJsonCount(1, 'reports');
    }

    public function test_unconfigured_and_missing_reports_never_claim_zero_or_start_external_requests(): void
    {
        Http::fake();
        $this->actingAsUser();
        $this->getJson('/api/trunkrs/summary')->assertOk()->assertJsonPath('report', null)->assertJsonPath('configured', false);
        $this->artisan('trunkrs:sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_all_statuses_are_kept_and_repeated_messages_and_duplicate_attachments_are_idempotent(): void
    {
        $importer = app(TrunkrsImporter::class);
        $csv = $this->csv().str_replace(['TEST-0001', 'TEST-BARCODE'], ['TEST-0002', 'TEST-OTHER'], explode("\n", $this->csv(status: 'UNKNOWN_CARRIER_CODE'))[1])."\n";
        $this->assertTrue($importer->import($this->message(), $csv, 'report.csv'));
        $this->assertFalse($importer->import($this->message(), $csv, 'report.csv'));
        $this->assertFalse($importer->import($this->message('duplicate-mail'), $csv, 'copy.csv'));
        $this->assertDatabaseCount('trunkrs_reports', 1);
        $this->assertSame(2, TrunkrsReport::first()->shipment_count);
        $this->assertSame('UNKNOWN_CARRIER_CODE', TrunkrsReport::first()->shipments[1]['status']);
    }

    public function test_valid_empty_reports_have_unknown_date_and_are_not_suppressed_on_following_days(): void
    {
        $csv = explode("\n", $this->csv())[0]."\n";
        $importer = app(TrunkrsImporter::class);
        $importer->import($this->message(), $csv, 'report.csv');
        $importer->import($this->message('following-day', '2026-09-11T04:00:00Z'), $csv, 'report.csv');
        $this->assertDatabaseCount('trunkrs_reports', 2);
        $this->assertNull(TrunkrsReport::first()->report_date);
        $summary = app(TrunkrsDashboard::class)->summary();
        $this->assertSame(0, $summary['report']['shipment_count']);
        $this->assertStringContainsString('geen bezorgdatum', implode(' ', $summary['warnings']));
    }

    public function test_malformed_reports_fail_closed_without_replacing_previous_data(): void
    {
        $parser = app(TrunkrsReportParser::class);
        $invalid = [
            '', '<html>server failure</html>', str_replace('Status', 'Changed header', $this->csv()),
            $this->csv('2026-02-30'), str_repeat('x', TrunkrsReportParser::MAX_BYTES + 1),
            $this->csv().explode("\n", $this->csv('2026-09-08'))[1],
            $this->csv().explode("\n", $this->csv())[1],
            str_replace('TEST-0001', '', $this->csv()),
        ];
        foreach ($invalid as $csv) {
            try {
                $parser->parse($csv, 'report.csv');
                $this->fail('Invalid report accepted');
            } catch (TrunkrsException $e) {
                $this->assertSame('invalid_report', $e->reason);
            }
        }
        $this->assertDatabaseCount('trunkrs_reports', 0);
    }

    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'trunkrs-test-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    public function test_zip_is_read_without_extracting_files_and_rejects_traversal_and_multiple_csvs(): void
    {
        $parser = app(TrunkrsReportParser::class);
        $this->assertSame(1, $parser->parse($this->zip(['dashboard/report.csv' => $this->csv()]), 'report.zip')['shipment_count']);
        foreach ([['../report.csv' => $this->csv()], ['a.csv' => $this->csv(), 'b.csv' => $this->csv()], ['bomb.csv' => str_repeat('x', 2_000_001)]] as $files) {
            try {
                $parser->parse($this->zip($files), 'report.zip');
                $this->fail('Unsafe zip accepted');
            } catch (TrunkrsException $e) {
                $this->assertSame('invalid_report', $e->reason);
            }
        }
    }

    public function test_scheduled_import_uses_only_selected_folder_and_read_operations_with_rotating_tokens(): void
    {
        $this->setupReader();
        $this->fakeGraph([$this->message(), array_replace($this->message('unrelated'), ['subject' => 'Not our report'])]);
        $this->artisan('trunkrs:sync')->assertSuccessful();
        $this->assertDatabaseCount('trunkrs_reports', 1);
        $this->assertSame('test-rotated', TrunkrsConnection::find(1)->refresh_token);
        $this->assertNotNull(TrunkrsConnection::find(1)->last_checked_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/mailFolders/test-reports-folder-only/messages?')
            && ! str_contains($r->url(), 'body'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages/unrelated/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.microsoft.com') && $r->method() !== 'GET');
        $this->artisan('trunkrs:sync')->assertSuccessful();
        $this->assertDatabaseCount('trunkrs_reports', 1);
    }

    public function test_429_preserves_previous_report_and_waits_before_retrying(): void
    {
        $this->setupReader();
        app(TrunkrsImporter::class)->import($this->message('earlier'), $this->csv(), 'report.csv');
        $this->fakeGraph([$this->message()], Http::response([], 429, ['Retry-After' => '1800']));
        $this->assertSame('rate_limit', app(TrunkrsSync::class)->run());
        $this->assertDatabaseCount('trunkrs_reports', 1);
        $this->assertTrue(TrunkrsConnection::find(1)->retry_at->gte(now()->addSeconds(1795)));
        Http::fake();
        $this->assertSame('waiting', app(TrunkrsSync::class)->run());
        Http::assertNothingSent();
    }

    public function test_bad_report_does_not_block_other_reports_or_clear_failure_status(): void
    {
        $this->setupReader();
        $this->fakeGraph([$this->message('bad'), $this->message('good')], null, [
            'graph.microsoft.com/*/messages/bad/attachments/test-file/$value' => Http::response('<html>bad</html>'),
        ]);
        $this->assertSame('invalid_report', app(TrunkrsSync::class)->run());
        $this->assertDatabaseCount('trunkrs_reports', 1);
        $this->assertNull(TrunkrsConnection::find(1)->last_checked_at);
        $this->assertSame('invalid_report', TrunkrsConnection::find(1)->last_error);
    }

    public function test_wrong_identity_or_broader_mail_permission_is_rejected(): void
    {
        $this->setupReader();
        $this->fakeGraph([], null, ['graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'wrong-reader'])]);
        $this->assertSame('scope', app(TrunkrsSync::class)->run());
        TrunkrsConnection::find(1)->update(['retry_at' => null]);
        $this->fakeGraph([], null, ['login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
            'access_token' => 'test', 'refresh_token' => 'test', 'scope' => 'Mail.Read.Shared Mail.ReadWrite User.Read',
        ])]);
        $this->assertSame('scope', app(TrunkrsSync::class)->run());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/mailFolders/'));
    }

    public function test_config_change_and_local_environment_never_reuse_personal_mailbox_access(): void
    {
        $this->setupReader();
        Http::fake();
        config()->set('trunkrs.folder_id', 'different-folder');
        $this->assertSame('authorization', app(TrunkrsSync::class)->run());
        Http::assertNothingSent();
        TrunkrsConnection::find(1)->update(['retry_at' => null]);
        app()->instance('env', 'local');
        $this->assertSame('configuration', app(TrunkrsSync::class)->run());
        Http::assertNothingSent();
    }

    public function test_lock_prevents_overlapping_syncs(): void
    {
        $this->setupReader();
        Http::fake();
        $lock = Cache::lock('trunkrs-sync', 600);
        $lock->get();
        try {
            $this->assertSame('busy', app(TrunkrsSync::class)->run());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_later_import_of_an_older_email_does_not_replace_the_newest_received_email(): void
    {
        $this->setupReader();
        $this->travelTo(now()->setDate(2026, 9, 11)->setTime(8, 0));
        app(TrunkrsImporter::class)->import($this->message(), $this->csv(), 'report.csv');
        app(TrunkrsImporter::class)->import($this->message('delayed', '2026-09-09T04:00:00Z'), $this->csv('2026-09-08'), 'old.csv');
        $summary = app(TrunkrsDashboard::class)->summary();
        $this->assertSame('2026-09-09', $summary['report']['report_date']);
        $this->assertStringContainsString('nieuwer rapport ontbreekt', implode(' ', $summary['warnings']));
        $this->assertStringNotContainsString('serverplanning', implode(' ', $summary['warnings']));
    }

    public function test_foreign_pagination_link_never_receives_a_token(): void
    {
        $this->setupReader();
        $this->fakeGraph([], null, ['graph.microsoft.com/*/messages*' => Http::response([
            'value' => [], '@odata.nextLink' => 'https://outside.example.test/steal',
        ])]);
        $this->assertSame('provider', app(TrunkrsSync::class)->run());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'outside.example.test'));
    }

    public function test_valid_pagination_imports_both_pages_before_marking_the_scan_complete(): void
    {
        $this->setupReader();
        $next = 'https://graph.microsoft.com/v1.0/users/reports-owner%40example.test/mailFolders/test-reports-folder-only/messages?$skiptoken=test';
        $this->fakeGraph([], null, [
            'graph.microsoft.com/*/messages?*' => Http::sequence()
                ->push(['value' => [$this->message('page1')], '@odata.nextLink' => $next])
                ->push(['value' => [$this->message('page2')]]),
            'graph.microsoft.com/*/messages/page2/attachments/test-file/$value' => Http::response($this->csv('2026-09-08')),
        ]);
        $this->assertSame('ok', app(TrunkrsSync::class)->run());
        $this->assertDatabaseCount('trunkrs_reports', 2);
        Http::assertSent(fn ($r) => $r->url() === $next);
    }

    public function test_mailbox_wide_pagination_is_rejected_even_on_microsoft_domain(): void
    {
        $this->setupReader();
        $this->fakeGraph([], null, ['graph.microsoft.com/*/messages*' => Http::response([
            'value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=x',
        ])]);
        $this->assertSame('provider', app(TrunkrsSync::class)->run());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/me/messages'));
    }

    public function test_login_stores_only_valid_scoped_token_and_never_accepts_the_mailbox_owner(): void
    {
        $this->setupReader();
        $this->fakeGraph([]);
        $this->assertSame('connected', app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('test-device-code'));
        $this->assertSame('test-rotated', TrunkrsConnection::find(1)->refresh_token);
        $this->fakeGraph([], null, ['graph.microsoft.com/v1.0/me*' => Http::response([
            'id' => config('trunkrs.reader_user_id'), 'userPrincipalName' => config('trunkrs.mailbox'),
        ])]);
        $this->expectException(TrunkrsException::class);
        app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('test-device-code');
    }

    public function test_incomplete_authentication_never_overwrites_the_connection(): void
    {
        $this->setupReader();
        $this->fakeGraph([], null, ['login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'authorization_pending'], 400)]);
        $this->assertSame('authorization_pending', app(TrunkrsMicrosoftAuth::class)->finishDeviceLogin('test-device-code'));
        $this->assertSame('test-refresh', TrunkrsConnection::find(1)->refresh_token);
    }

    public function test_fresh_report_is_not_marked_stale_before_next_morning_deadline(): void
    {
        $this->setupReader();
        app(TrunkrsImporter::class)->import($this->message(), $this->csv(), 'report.csv');
        $this->travelTo(CarbonImmutable::parse('2026-09-11T04:15:00Z'));
        TrunkrsConnection::find(1)->update(['last_started_at' => now(), 'last_checked_at' => now()]);
        $this->assertSame([], app(TrunkrsDashboard::class)->summary()['warnings']);
        $this->travelTo(CarbonImmutable::parse('2026-09-11T04:35:00Z'));
        $this->assertStringContainsString('nieuwer rapport ontbreekt', implode(' ', app(TrunkrsDashboard::class)->summary()['warnings']));
    }

    public function test_recent_delivery_days_are_selectable_without_exposing_older_history_in_the_dropdown(): void
    {
        $this->setupReader();
        $this->travelTo(CarbonImmutable::parse('2026-09-23T06:00:00Z'));
        $importer = app(TrunkrsImporter::class);
        $importer->import($this->message('old', '2026-09-11T04:00:00Z'), $this->csv('2026-09-10'), 'old.csv');
        $this->travelTo(CarbonImmutable::parse('2026-09-23T06:01:00Z'));
        $importer->import($this->message('yesterday', '2026-09-23T04:00:00Z'), $this->csv('2026-09-22'), 'yesterday.csv');
        $yesterdayId = TrunkrsReport::whereDate('report_date', '2026-09-22')->value('id');
        $this->travelTo(CarbonImmutable::parse('2026-09-23T06:02:00Z'));
        $importer->import($this->message('delayed', '2026-09-22T04:01:00Z'), $this->csv('2026-09-20'), 'delayed.csv');
        $this->actingAsUser(['rol' => 'lid']);

        $default = $this->getJson('/api/trunkrs/summary')->assertOk();
        $default->assertJsonPath('report.report_date', '2026-09-22')->assertJsonCount(2, 'available_reports');
        $this->assertEqualsCanonicalizing(['2026-09-20', '2026-09-22'], array_column($default->json('available_reports'), 'report_date'));
        $this->getJson('/api/trunkrs/summary?report_id='.$yesterdayId)
            ->assertOk()->assertJsonPath('report.report_date', '2026-09-22');
        $delayedId = TrunkrsReport::whereDate('report_date', '2026-09-20')->value('id');
        $this->getJson('/api/trunkrs/summary?report_id='.$delayedId)
            ->assertOk()->assertJsonPath('report.report_date', '2026-09-20');
        $oldId = TrunkrsReport::whereDate('report_date', '2026-09-10')->value('id');
        $this->getJson('/api/trunkrs/summary?report_id='.$oldId)
            ->assertOk()->assertJsonPath('report.report_date', '2026-09-22');
        $this->getJson('/api/trunkrs/summary?report_id=not-a-uuid')->assertUnprocessable();
    }

    public function test_the_report_migration_never_targets_existing_planning_tables(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_11_160000_create_trunkrs_reports_tables.php'));
        foreach (['projects', 'tasks', 'calendar_items', 'users', 'notes', 'cs_tickets'] as $table) {
            $this->assertStringNotContainsString("'".$table."'", $migration);
        }
        $this->assertStringNotContainsString('DB::', $migration);
    }
}
