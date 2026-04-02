<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Maak pivot tabel aan
        Schema::create('taak_gebruiker', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('user_id');
            $table->timestamps();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['task_id', 'user_id']);
        });

        // 2. Migreer bestaande toewijzingen naar pivot tabel
        $tasks = DB::select("SELECT id, toegewezen_aan, created_at, updated_at FROM tasks WHERE toegewezen_aan IS NOT NULL");
        foreach ($tasks as $task) {
            DB::table('taak_gebruiker')->insert([
                'id'         => (string) Str::uuid(),
                'task_id'    => $task->id,
                'user_id'    => $task->toegewezen_aan,
                'created_at' => $task->created_at,
                'updated_at' => $task->updated_at,
            ]);
        }

        // 3. Bouw tasks tabel opnieuw op zonder toegewezen_aan (SQLite-safe)
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE tasks_new (
            id VARCHAR NOT NULL PRIMARY KEY,
            project_id VARCHAR,
            titel VARCHAR NOT NULL,
            beschrijving TEXT,
            status VARCHAR NOT NULL DEFAULT 'todo',
            prioriteit VARCHAR NOT NULL DEFAULT 'normaal',
            deadline DATE,
            kleur VARCHAR,
            link VARCHAR,
            positie INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )");

        DB::statement("INSERT INTO tasks_new
            SELECT id, project_id, titel, beschrijving, status, prioriteit, deadline, kleur, link, positie, created_at, updated_at
            FROM tasks");

        DB::statement("DROP TABLE tasks");
        DB::statement("ALTER TABLE tasks_new RENAME TO tasks");

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Herstel tasks tabel met toegewezen_aan kolom
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("CREATE TABLE tasks_new (
            id VARCHAR NOT NULL PRIMARY KEY,
            project_id VARCHAR,
            titel VARCHAR NOT NULL,
            beschrijving TEXT,
            status VARCHAR NOT NULL DEFAULT 'todo',
            prioriteit VARCHAR NOT NULL DEFAULT 'normaal',
            toegewezen_aan VARCHAR,
            deadline DATE,
            kleur VARCHAR,
            link VARCHAR,
            positie INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (toegewezen_aan) REFERENCES users(id) ON DELETE SET NULL
        )");

        DB::statement("INSERT INTO tasks_new
            SELECT id, project_id, titel, beschrijving, status, prioriteit, deadline, kleur, link, positie, created_at, updated_at
            FROM tasks");

        // Zet eerste toegewezen persoon terug
        $pivots = DB::select("SELECT task_id, MIN(user_id) as user_id FROM taak_gebruiker GROUP BY task_id");
        foreach ($pivots as $pivot) {
            DB::statement("UPDATE tasks_new SET toegewezen_aan = ? WHERE id = ?", [$pivot->user_id, $pivot->task_id]);
        }

        DB::statement("DROP TABLE tasks");
        DB::statement("ALTER TABLE tasks_new RENAME TO tasks");

        DB::statement('PRAGMA foreign_keys = ON');

        Schema::dropIfExists('taak_gebruiker');
    }
};
