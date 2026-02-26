<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('naam');
            $table->text('beschrijving')->nullable();
            $table->string('kleur')->default('#3B82F6');
            $table->enum('status', ['actief', 'gepauzeerd', 'afgerond', 'gearchiveerd'])->default('actief');
            $table->enum('prioriteit', ['laag', 'normaal', 'hoog', 'urgent'])->default('normaal');
            $table->date('deadline')->nullable();
            $table->uuid('aangemaakt_door')->nullable();
            $table->timestamps();

            $table->foreign('aangemaakt_door')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
