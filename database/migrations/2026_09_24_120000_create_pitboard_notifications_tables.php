<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pitboard_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->uuid('target_id');
            $table->string('title');
            $table->string('actor_name');
            $table->date('deadline')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('email_status', 30)->default('disabled');
            $table->timestamp('email_attempted_at')->nullable();
            $table->timestamp('email_accepted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at'], 'pitboard_notifications_inbox');
        });
        Schema::create('pitboard_notification_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('task_email')->default(true);
            $table->boolean('project_email')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pitboard_notification_preferences');
        Schema::dropIfExists('pitboard_notifications');
    }
};
