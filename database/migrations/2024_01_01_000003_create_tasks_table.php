<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->string('titel');
            $table->text('beschrijving')->nullable();
            $table->enum('status', ['todo', 'bezig', 'review', 'klaar'])->default('todo');
            $table->enum('prioriteit', ['laag', 'normaal', 'hoog', 'urgent'])->default('normaal');
            $table->uuid('toegewezen_aan')->nullable();
            $table->date('deadline')->nullable();
            $table->string('kleur')->nullable();
            $table->string('link')->nullable();
            $table->integer('positie')->default(0);
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('toegewezen_aan')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
