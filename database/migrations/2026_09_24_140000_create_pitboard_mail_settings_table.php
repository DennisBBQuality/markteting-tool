<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pitboard_mail_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('payload');
            $table->text('refresh_token')->nullable();
            $table->text('pending')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pitboard_mail_settings');
    }
};
