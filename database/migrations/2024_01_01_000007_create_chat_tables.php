<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('naam');
            $table->enum('type', ['general', 'project'])->default('general');
            $table->uuid('project_id')->nullable();
            $table->text('beschrijving')->nullable();
            $table->uuid('aangemaakt_door')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('aangemaakt_door')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('chat_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user1_id');
            $table->uuid('user2_id');
            $table->timestamps();

            $table->foreign('user1_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user2_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('afzender_id');
            $table->text('inhoud');
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->cascadeOnDelete();
            $table->foreign('afzender_id')->references('id')->on('users');

            $table->index(['channel_id', 'created_at']);
            $table->index(['thread_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('type', ['mention', 'direct_message'])->default('mention');
            $table->uuid('message_id')->nullable();
            $table->uuid('channel_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->boolean('gelezen')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->cascadeOnDelete();

            $table->index(['user_id', 'gelezen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
        Schema::dropIfExists('chat_channels');
    }
};
