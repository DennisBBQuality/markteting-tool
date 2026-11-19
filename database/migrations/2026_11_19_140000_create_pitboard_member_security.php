<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pitboard_member_security', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->text('secret')->nullable();
            $table->text('recovery_hashes')->nullable();
            $table->unsignedBigInteger('last_step')->default(0);
            $table->unsignedInteger('generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pitboard_member_security');
    }
};
