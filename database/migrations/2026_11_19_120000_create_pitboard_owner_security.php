<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pitboard_owner_security', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('user_id')->nullable()->unique();
            $table->text('secret')->nullable();
            $table->text('recovery_hashes')->nullable();
            $table->unsignedBigInteger('last_step')->default(0);
            $table->unsignedInteger('generation')->default(0);
        });
        DB::table('pitboard_owner_security')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pitboard_owner_security');
    }
};
