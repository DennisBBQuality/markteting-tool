<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Schema;

class ContentStorage
{
    public const MIGRATION = '2026_11_06_120000_create_content_platform_tables';

    public const COLUMNS = [
        'content_posts' => ['id', 'title', 'brief', 'source_url', 'project_id', 'task_id', 'owner_id', 'revision', 'review_status', 'approved_revision', 'approved_by', 'approved_at', 'created_at', 'updated_at'],
        'content_media' => ['id', 'name', 'path', 'mime', 'size', 'sha256', 'uploaded_by', 'created_at', 'updated_at'],
        'content_connections' => ['id', 'provider', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_at', 'api_version', 'accounts', 'selected_account', 'scheduler_seen_at', 'enabled', 'verified_at', 'created_at', 'updated_at'],
        'content_deliveries' => ['id', 'post_id', 'platform', 'text', 'media_ids', 'options', 'scheduled_at', 'timezone', 'status', 'external_id', 'connection_id', 'account_snapshot', 'provider_job_id', 'provider_request_id', 'upload_session_url', 'published_url', 'error', 'submitted_at', 'checked_at', 'created_at', 'updated_at'],
        'content_activities' => ['id', 'post_id', 'user_id', 'kind', 'message', 'revision', 'created_at', 'updated_at'],
    ];

    public function ready(): bool
    {
        try {
            foreach (self::COLUMNS as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
