<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_items', function (Blueprint $table) {
            // Null retains legacy title-based display. False is an explicit opt-out.
            // No existing record, title, date or timestamp is rewritten.
            $table->boolean('is_vacation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_items', function (Blueprint $table) {
            $table->dropColumn('is_vacation');
        });
    }
};
