<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->string('titel');
            $table->text('beschrijving')->nullable();
            $table->enum('type', ['content', 'deadline', 'meeting', 'social', 'email', 'blog'])->default('content');
            $table->dateTime('datum_start');
            $table->dateTime('datum_eind')->nullable();
            $table->string('kleur')->nullable();
            $table->string('link')->nullable();
            $table->uuid('aangemaakt_door')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('aangemaakt_door')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_items');
    }
};
