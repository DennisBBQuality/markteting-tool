<?php

namespace App\Services\Content;

use App\Models\ContentActivity;
use App\Models\ContentPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentWorkflow
{
    public const PLATFORMS = ['instagram', 'facebook', 'youtube', 'tiktok'];

    public function activity(ContentPost $post, ?string $user, string $kind, string $message): void
    {
        ContentActivity::create(['post_id' => $post->id, 'user_id' => $user, 'kind' => $kind, 'message' => $message, 'revision' => $post->revision]);
    }

    public function editable(ContentPost $post): void
    {
        abort_if($post->deliveries()->whereNotIn('status', ['draft', 'cancelled'])->exists(), 409,
            'Deze versie is al aangeboden voor publicatie. Annuleer de planning eerst of dupliceer het bericht.');
    }

    public function revision(ContentPost $post, int $revision): void
    {
        abort_if($post->revision !== $revision, 409, 'Een collega heeft dit bericht gewijzigd. Heropen het bericht; je invoer is niet overschreven.');
    }

    public function time(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        // Require an explicit offset; the browser chooses Amsterdam's actual offset.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ValidationException::withMessages(['scheduled_at' => 'Kies een geldige datum en tijd met tijdzone.']);
        }
        try {
            $time = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['scheduled_at' => 'Ongeldige datum.']);
        }
        if ($time->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
            throw ValidationException::withMessages(['scheduled_at' => 'Deze datum bestaat niet.']);
        }

        return $time->utc();
    }

    public function save(array $data, string $user, ?string $id = null): ContentPost
    {
        return DB::transaction(function () use ($data, $user, $id) {
            $post = $id ? ContentPost::lockForUpdate()->findOrFail($id) : new ContentPost;
            if ($id) {
                $this->revision($post, $data['revision']);
                $this->editable($post);
                $post->revision++;
            } else {
                $post->revision = 1;
            }
            $post->fill(collect($data)->only(['title', 'brief', 'source_url', 'owner_id', 'project_id', 'task_id'])->all());
            $post->review_status = 'draft';
            $post->approved_revision = $post->approved_by = $post->approved_at = null;
            $post->save();
            $platforms = [];
            foreach ($data['deliveries'] ?? [] as $row) {
                $platforms[] = $row['platform'];
                $delivery = $post->deliveries()->firstOrNew(['platform' => $row['platform']]);
                $delivery->fill(['text' => $row['text'] ?? '', 'media_ids' => $row['media_ids'] ?? [],
                    'options' => $row['options'] ?? [], 'scheduled_at' => $this->time($row['scheduled_at'] ?? null),
                    'timezone' => 'Europe/Amsterdam', 'status' => 'draft', 'error' => null]);
                // Each approved new version has a new correlation identity.
                $delivery->external_id = (string) Str::uuid();
                $delivery->fill(['connection_id' => null, 'account_snapshot' => null, 'provider_job_id' => null,
                    'provider_request_id' => null, 'upload_session_url' => null, 'published_url' => null,
                    'submitted_at' => null, 'checked_at' => null]);
                $delivery->save();
            }
            $post->deliveries()->whereNotIn('platform', $platforms)->delete();
            $this->activity($post, $user, $id ? 'edited' : 'created', $id ? 'Nieuwe conceptversie opgeslagen; eerdere goedkeuring vervallen.' : 'Concept aangemaakt.');

            return $post->load('deliveries', 'activities');
        });
    }
}
