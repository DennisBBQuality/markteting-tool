<?php

namespace Tests\Feature;

use App\Console\Commands\UpgradeNotificationStorage;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationStorageUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_release_upgrade_preserves_existing_data_and_is_repeatable(): void
    {
        $user = $this->actingAsUser();
        Project::create(['naam' => 'TEST bestaand', 'aangemaakt_door' => $user->id]);
        Task::create(['titel' => 'TEST bestaand']);
        Schema::drop('pitboard_notification_preferences');
        Schema::drop('pitboard_notifications');
        DB::table('migrations')->where('migration', UpgradeNotificationStorage::MIGRATION)->delete();
        $tables = collect(Schema::getTableListing())->map(fn ($name) => str_replace('main.', '', $name))
            ->reject(fn ($name) => in_array($name, ['migrations', 'sqlite_sequence', 'cache_locks']));
        $snapshot = fn () => $tables->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $before = $snapshot();
        $this->artisan('pitboard:upgrade-notification-storage')->assertSuccessful();
        $this->artisan('pitboard:upgrade-notification-storage')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseCount('pitboard_notifications', 0);
        $this->assertTrue(Schema::hasColumns('pitboard_notification_preferences', ['user_id', 'task_email', 'project_email']));
        $this->assertSame(1, DB::table('migrations')->where('migration', UpgradeNotificationStorage::MIGRATION)->count());
    }

    public function test_ambiguous_schema_is_not_repaired_or_dropped(): void
    {
        DB::table('migrations')->where('migration', UpgradeNotificationStorage::MIGRATION)->delete();
        $this->artisan('pitboard:upgrade-notification-storage')->assertFailed();
        $this->assertTrue(Schema::hasTable('pitboard_notifications'));
    }

    public function test_only_confirmed_admin_can_initialize_missing_tables(): void
    {
        $this->actingAsUser();
        Schema::drop('pitboard_notification_preferences');
        Schema::drop('pitboard_notifications');
        DB::table('migrations')->where('migration', UpgradeNotificationStorage::MIGRATION)->delete();
        $this->getJson('/api/notifications')->assertJsonPath('ready', false)->assertJsonPath('can_initialize', false);
        $this->postJson('/api/notifications/initialize', ['confirm' => true])->assertForbidden();
        $this->assertFalse(Schema::hasTable('pitboard_notifications'));
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson('/api/notifications')->assertJsonPath('ready', false)->assertJsonPath('can_initialize', true);
        $this->postJson('/api/notifications/initialize', ['confirm' => false])->assertUnprocessable();
        $this->postJson('/api/notifications/initialize', ['confirm' => true])->assertOk();
        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }
}
