<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductStudioMigrationPreservationTest extends TestCase
{
    public function test_productstudio_upgrade_preserves_existing_planning_data_and_relationships(): void
    {
        // A dedicated, disposable in-memory connection; never the configured live/local database.
        config(['database.connections.preservation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('preservation_test');
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $baseline = [
            '2024_01_01_000001_create_users_table.php',
            '2024_01_01_000002_create_projects_table.php',
            '2024_01_01_000003_create_tasks_table.php',
            '2024_01_01_000004_create_calendar_items_table.php',
            '2024_01_01_000005_create_notes_table.php',
            '2026_04_02_000001_create_taak_gebruiker_pivot_table.php',
            '2026_04_02_000002_create_project_gebruiker_pivot_table.php',
        ];
        $paths = fn ($files) => array_map(fn ($file) => 'database/migrations/'.$file, $files);
        $this->artisan('migrate', ['--path' => $paths($baseline)])->assertExitCode(0);

        $user = $this->actingAsUser(['naam' => 'Fictieve planningsmedewerker', 'email' => 'planning-migratie@example.test']);
        $projectId = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $timestamps = ['created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-08 14:30:00'];
        DB::table('projects')->insert([
            'id' => $projectId, 'naam' => 'Fictieve najaarscampagne', 'beschrijving' => 'Bestaande projectinformatie bewaren.',
            'status' => 'actief', 'prioriteit' => 'hoog', 'deadline' => '2026-10-01', 'aangemaakt_door' => $user->id, ...$timestamps,
        ]);
        DB::table('tasks')->insert([
            'id' => $taskId, 'project_id' => $projectId, 'titel' => 'Fictieve reeds ingeplande taak', 'beschrijving' => 'Inhoud, status en positie behouden.',
            'status' => 'bezig', 'prioriteit' => 'urgent', 'deadline' => '2026-09-20', 'positie' => 17, ...$timestamps,
        ]);
        DB::table('calendar_items')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $projectId, 'titel' => 'Fictieve bestaande afspraak', 'beschrijving' => 'Datum en tijd niet wijzigen.',
            'type' => 'meeting', 'datum_start' => '2026-09-25 09:15:00', 'datum_eind' => '2026-09-25 10:45:00', 'aangemaakt_door' => $user->id, ...$timestamps,
        ]);
        DB::table('taak_gebruiker')->insert(['id' => (string) Str::uuid(), 'task_id' => $taskId, 'user_id' => $user->id, ...$timestamps]);
        DB::table('project_gebruiker')->insert(['id' => (string) Str::uuid(), 'project_id' => $projectId, 'user_id' => $user->id, ...$timestamps]);
        DB::table('notes')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $projectId, 'task_id' => $taskId,
            'titel' => 'Fictieve bestaande notitie', 'inhoud' => 'Deze inhoud en relaties moeten exact behouden blijven.',
            'aangemaakt_door' => $user->id, ...$timestamps,
        ]);

        $protected = ['users', 'projects', 'tasks', 'calendar_items', 'notes', 'taak_gebruiker', 'project_gebruiker'];
        $snapshot = fn () => collect($protected)->mapWithKeys(fn ($table) => [$table => [
            'rows' => DB::table($table)->orderBy('id')->get()->toJson(),
            'schema' => DB::table('sqlite_master')->where('name', $table)->value('sql'),
        ]])->all();
        $before = $snapshot();
        $upgrade = [
            '2026_09_04_090000_create_product_dossiers_table.php',
            '2026_09_04_100000_create_product_dossier_options_table.php',
            '2026_09_04_110000_align_product_dossier_options_with_bbquality.php',
            '2026_09_07_100000_add_generation_to_product_dossiers.php',
            '2026_09_07_110000_add_expert_assets_to_product_dossiers.php',
            '2026_09_09_100000_create_product_dossier_assets_table.php',
            '2026_09_11_100000_create_product_image_model_settings_table.php',
            '2026_09_11_160000_create_trunkrs_reports_tables.php',
            '2026_09_14_140000_create_dashboard_preferences_table.php',
        ];
        $this->artisan('migrate', ['--path' => $paths($upgrade)])->assertExitCode(0);
        $this->assertSame($before, $snapshot(), 'Bestaande planningsgegevens, relaties en tabellen moeten exact gelijk blijven.');
        $this->assertTrue(Schema::hasColumns('product_dossiers', ['generation', 'expert_assets']));
        $this->assertGreaterThan(0, DB::table('product_dossier_options')->count());
        $this->assertSame(0, DB::table('product_dossiers')->count(), 'Geen testdossiers toevoegen bij een upgrade.');
        foreach (['dashboard_preferences', 'product_image_model_settings', 'trunkrs_reports', 'trunkrs_connections'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count(), 'Nieuwe tabellen mogen geen lokale voorbeeldgegevens bevatten.');
        }

        // Running the approved upgrade again must be a no-op for existing planning data.
        $this->artisan('migrate', ['--path' => $paths($upgrade)])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
    }
}
