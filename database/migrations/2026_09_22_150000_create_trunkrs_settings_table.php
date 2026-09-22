<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trunkrs_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('payload');
            $table->text('pending')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamp('scheduler_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trunkrs_settings');
    }
};
