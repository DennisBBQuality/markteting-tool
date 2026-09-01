<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('naam')->unique();
            $table->text('prompt');
            $table->uuid('bijgewerkt_door')->nullable();
            $table->timestamps();

            $table->foreign('bijgewerkt_door')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_prompts');
    }
};
