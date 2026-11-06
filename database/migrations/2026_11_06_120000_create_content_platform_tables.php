<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_posts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title');
            $t->text('brief')->nullable();
            $t->text('source_url')->nullable();
            $t->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->foreignUuid('owner_id')->constrained('users');
            $t->unsignedInteger('revision')->default(1);
            $t->string('review_status')->default('draft')->index();
            $t->unsignedInteger('approved_revision')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('content_media', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('path')->unique();
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->foreignUuid('uploaded_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('content_connections', function (Blueprint $t) {
            $t->id();
            $t->string('provider')->unique();
            $t->string('client_id')->nullable();
            $t->text('client_secret')->nullable();
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('api_version')->nullable();
            $t->text('accounts')->nullable();
            $t->string('selected_account')->nullable();
            $t->timestamp('scheduler_seen_at')->nullable();
            $t->boolean('enabled')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('content_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('post_id')->constrained('content_posts')->cascadeOnDelete();
            $t->string('platform');
            $t->text('text')->nullable();
            $t->json('media_ids')->nullable();
            $t->json('options')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->string('timezone')->default('Europe/Amsterdam');
            $t->string('status')->default('draft')->index();
            $t->string('external_id')->unique();
            $t->foreignId('connection_id')->nullable()->constrained('content_connections');
            $t->json('account_snapshot')->nullable();
            $t->string('provider_job_id')->nullable();
            $t->string('provider_request_id')->nullable();
            $t->text('upload_session_url')->nullable();
            $t->text('published_url')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->unique(['post_id', 'platform']);
        });
        Schema::create('content_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('post_id')->constrained('content_posts')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind');
            $t->text('message');
            $t->unsignedInteger('revision');
            $t->timestamps();
        });

    }

    public function down(): void
    {
        foreach (['content_activities', 'content_deliveries', 'content_connections', 'content_media', 'content_posts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
