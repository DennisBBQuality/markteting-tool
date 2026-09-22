<?php

namespace Tests\Feature;

use App\Console\Commands\UpgradeImageSeoStorage;
use App\Services\ProductImageSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImageSeoReleaseUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_hook_runs_only_the_explicit_migration_and_preserves_all_existing_tables(): void
    {
        $user = $this->actingAsUser(['email' => 'release-test@example.test']);
        $timestamps = ['created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-08 14:30:00'];
        DB::table('projects')->insert(['id' => 'project-test', 'naam' => 'Bestaand testproject', 'aangemaakt_door' => $user->id, ...$timestamps]);
        DB::table('tasks')->insert(['id' => 'task-test', 'project_id' => 'project-test', 'titel' => 'Bestaande testtaak', ...$timestamps]);
        DB::table('calendar_items')->insert(['id' => 'calendar-test', 'project_id' => 'project-test', 'titel' => 'Bestaande testafspraak', 'datum_start' => '2026-09-25 09:00:00', ...$timestamps]);
        DB::table('notes')->insert(['id' => 'note-test', 'project_id' => 'project-test', 'task_id' => 'task-test', 'titel' => 'Bestaande testnotitie', 'inhoud' => 'Ongewijzigd bewaren.', ...$timestamps]);
        DB::table('product_image_requests')->insert(['id' => 'photo-test', 'user_id' => $user->id, 'source_path' => 'fake.png', 'prompt' => 'Fictieve foto', ...$timestamps]);
        $asset = DB::table('product_image_assets')->insertGetId(['product_image_request_id' => 'photo-test', 'filename' => 'fake.png', 'contents_base64' => base64_encode('fake-image-bytes'), ...$timestamps]);
        DB::table('product_image_metadata')->insert(['product_image_asset_id' => $asset, 'image_version' => 1, 'fields' => json_encode(['filename' => 'oude-testfoto.webp', 'alt' => 'Bestaande tekst']), ...$timestamps]);
        // Simulate precisely the failed release in the disposable test database.
        Schema::drop('product_image_download_names');
        DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->delete();
        $unrelated = '2026_09_15_080000_add_is_vacation_to_calendar_items';
        DB::table('migrations')->where('migration', $unrelated)->delete();
        $tables = collect(Schema::getTableListing())->map(fn ($name) => str_replace('main.', '', $name))->reject(fn ($name) => in_array($name, ['migrations', 'sqlite_sequence']))->values();
        $snapshot = fn () => $tables->mapWithKeys(fn ($table) => [$table => [
            'rows' => DB::table($table)->get()->toJson(),
            'schema' => DB::table('sqlite_master')->where('name', $table)->value('sql'),
        ]])->all();
        $before = $snapshot();
        $this->artisan('pitboard:upgrade-image-seo-storage')->assertSuccessful();
        $this->assertTrue(Schema::hasColumns('product_image_download_names', ['filename', 'product_image_asset_id']));
        $this->assertDatabaseCount('product_image_download_names', 0);
        $this->assertDatabaseHas('migrations', ['migration' => UpgradeImageSeoStorage::MIGRATION]);
        $this->assertDatabaseMissing('migrations', ['migration' => $unrelated]);
        $this->assertSame($before, $snapshot());
        $this->artisan('pitboard:upgrade-image-seo-storage')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertSame(1, DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->count());
        $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];
        $this->assertContains('@php artisan pitboard:upgrade-image-seo-storage --no-interaction', $scripts['post-install-cmd']);
    }

    public function test_schema_history_mismatch_stops_without_dropping_or_rewriting_anything(): void
    {
        DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->delete();
        $this->artisan('pitboard:upgrade-image-seo-storage')->assertFailed();
        $this->assertTrue(Schema::hasTable('product_image_download_names'));
        $this->assertDatabaseMissing('migrations', ['migration' => UpgradeImageSeoStorage::MIGRATION]);
    }

    public function test_recorded_migration_with_missing_table_is_not_silently_recreated(): void
    {
        Schema::drop('product_image_download_names');
        $this->artisan('pitboard:upgrade-image-seo-storage')->assertFailed();
        $this->assertFalse(Schema::hasTable('product_image_download_names'));
    }

    public function test_opted_in_seo_write_can_run_only_the_targeted_upgrade_and_releases_the_shared_lock(): void
    {
        Schema::drop('product_image_download_names');
        DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->delete();
        app(ProductImageSeo::class)->ensureStorageReady(repair: true);
        $this->assertTrue(Schema::hasTable('product_image_download_names'));
        $this->assertDatabaseCount('cache_locks', 0);
        app(ProductImageSeo::class)->ensureStorageReady(repair: true);
        $this->assertSame(1, DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->count());
    }
}
