<?php

namespace Tests\Feature;

use App\Models\ContentConnection;
use App\Models\Project;
use App\Models\Task;
use App\Services\Content\ContentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentStorageUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function removeContentStorage(): void
    {
        // Only the isolated in-memory test database is changed here.
        foreach (['content_activities', 'content_deliveries', 'content_connections', 'content_media', 'content_posts'] as $table) {
            Schema::drop($table);
        }
        DB::table('migrations')->where('migration', ContentStorage::MIGRATION)->delete();
    }

    public function test_upgrade_only_adds_content_storage_and_preserves_all_existing_data(): void
    {
        $u = $this->actingAsUser(['rol' => 'admin']);
        Project::create(['naam' => 'TEST bestaand', 'aangemaakt_door' => $u->id]);
        Task::create(['titel' => 'TEST bestaande taak']);
        $this->removeContentStorage();
        $tables = collect(Schema::getTableListing())->map(fn ($t) => str_replace('main.', '', $t))->reject(fn ($t) => in_array($t, ['migrations', 'sqlite_sequence', 'cache_locks']));
        $snapshot = fn () => $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $before = $snapshot();
        $this->artisan('pitboard:upgrade-content-storage')->assertSuccessful();
        $this->artisan('pitboard:upgrade-content-storage')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertTrue(app(ContentStorage::class)->ready());
        $this->assertSame(1, DB::table('migrations')->where('migration', ContentStorage::MIGRATION)->count());
        $this->assertDatabaseCount('content_posts', 0);
        $this->assertDatabaseCount('content_connections', 2);
        foreach (ContentConnection::all() as $c) {
            $this->assertFalse($c->enabled);
            $this->assertNull($c->client_secret);
            $this->assertNull($c->scheduler_seen_at);
        }
    }

    public function test_admin_sees_readiness_without_sql_details_and_can_initialize(): void
    {
        $this->removeContentStorage();
        $this->getJson('/api/content/connections')->assertUnauthorized();
        $this->actingAsUser(['rol' => 'lid']);
        $this->postJson('/api/content/connections/initialize', ['confirmed' => true])->assertForbidden();
        $this->getJson('/api/content/posts')->assertStatus(503)->assertDontSee('SQLSTATE');
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson('/api/content/connections')->assertOk()->assertJsonPath('storage_ready', false);
        $this->postJson('/api/content/connections/initialize', ['confirmed' => false])->assertUnprocessable();
        $this->postJson('/api/content/connections/initialize', ['confirmed' => true])->assertOk()->assertJsonPath('storage_ready', true);
        $this->getJson('/api/content/posts')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_partial_or_mismatched_schema_is_never_automatically_repaired(): void
    {
        DB::table('migrations')->where('migration', ContentStorage::MIGRATION)->delete();
        $this->artisan('pitboard:upgrade-content-storage')->assertFailed();
        $this->assertTrue(Schema::hasTable('content_connections'));
        $this->assertDatabaseCount('content_connections', 0);
        Schema::drop('content_activities');
        $this->artisan('pitboard:upgrade-content-storage')->assertFailed();
        $this->assertFalse(Schema::hasTable('content_activities'));
    }

    public function test_existing_credentials_and_heartbeat_are_not_reset_by_repeat_upgrade(): void
    {
        $c = ContentConnection::create(['provider' => 'meta', 'client_secret' => 'FAKE_TEST_SECRET', 'enabled' => true, 'scheduler_seen_at' => now()->subHour()]);
        $before = $c->fresh()->getRawOriginal();
        $this->artisan('pitboard:upgrade-content-storage')->assertSuccessful();
        $this->assertSame($before, $c->fresh()->getRawOriginal());
    }

    public function test_only_real_worker_updates_heartbeat_and_missing_storage_fails_safely(): void
    {
        Http::preventStrayRequests();
        $this->artisan('pitboard:upgrade-content-storage')->assertSuccessful();
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson('/api/content/connections')->assertJsonPath('connections.0.scheduler_seen_at', null);
        $this->app['env'] = 'production';
        $this->artisan('content:publish-due')->assertSuccessful();
        $this->assertNotNull(ContentConnection::first()->scheduler_seen_at);
        Http::assertNothingSent();
        $this->removeContentStorage();
        $this->artisan('content:publish-due')->assertFailed();
        Http::assertNothingSent();
    }
}
