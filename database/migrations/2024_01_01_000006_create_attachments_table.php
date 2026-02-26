<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->uuid('calendar_item_id')->nullable();
            $table->uuid('note_id')->nullable();
            $table->string('bestandsnaam');
            $table->string('originele_naam');
            $table->string('mimetype')->nullable();
            $table->unsignedBigInteger('grootte')->nullable();
            $table->uuid('geupload_door')->nullable();
            $table->timestamps();

            $table->foreign('geupload_door')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
