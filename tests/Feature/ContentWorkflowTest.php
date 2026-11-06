<?php

namespace Tests\Feature;

use App\Models\ContentPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function data(User $user): array
    {
        return ['title' => 'Fictief winterrecept', 'brief' => 'Opnames voorbereiden', 'owner_id' => $user->id,
            'deliveries' => [['platform' => 'instagram', 'text' => 'Fictieve caption', 'scheduled_at' => '2026-12-01T12:00:00+01:00', 'media_ids' => [], 'options' => ['format' => 'photo']]]];
    }

    public function test_concept_review_approval_and_edit_invalidates_approval(): void
    {
        $user = $this->actingAsUser(['rol' => 'manager']);
        $post = $this->postJson('/api/content/posts', $this->data($user))->assertCreated()->assertJsonPath('review_status', 'draft')->json();
        $this->assertSame('2026-12-01T11:00:00.000000Z', $post['deliveries'][0]['scheduled_at']);
        $id = $post['id'];
        $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => 'request'])->assertOk()->assertJsonPath('review_status', 'review');
        $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => 'approve'])->assertOk()->assertJsonPath('approved_revision', 1);
        $this->putJson("/api/content/posts/$id", ['revision' => 1, ...$this->data($user), 'title' => 'Gewijzigd'])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('approved_revision', null);
        $this->assertDatabaseCount('calendar_items', 0);
        $this->assertDatabaseCount('content_activities', 4);
    }

    public function test_stale_editor_and_review_cannot_overwrite_a_newer_version(): void
    {
        $user = $this->actingAsUser(['rol' => 'manager']);
        $id = $this->postJson('/api/content/posts', $this->data($user))->json('id');
        $this->putJson("/api/content/posts/$id", ['revision' => 1, ...$this->data($user)])->assertOk();
        $this->putJson("/api/content/posts/$id", ['revision' => 1, ...$this->data($user), 'title' => 'STALE'])->assertConflict();
        $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => 'approve'])->assertConflict();
        $this->assertDatabaseHas('content_posts', ['id' => $id, 'title' => 'Fictief winterrecept', 'revision' => 2]);
    }

    public function test_member_can_request_review_but_not_approve_or_change_review_decision(): void
    {
        $this->getJson('/api/content/posts')->assertUnauthorized();
        $user = $this->actingAsUser(['rol' => 'lid']);
        $id = $this->postJson('/api/content/posts', $this->data($user))->json('id');
        $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => 'request'])->assertOk();
        foreach (['approve', 'changes'] as $action) {
            $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => $action])->assertForbidden();
        }
    }

    public function test_submitted_post_is_immutable_and_duplicate_is_a_new_unscheduled_draft(): void
    {
        $user = $this->actingAsUser(['rol' => 'manager']);
        $id = $this->postJson('/api/content/posts', $this->data($user))->json('id');
        $post = ContentPost::findOrFail($id);
        $post->deliveries()->update(['status' => 'scheduled', 'provider_job_id' => 'FAKE_JOB']);
        $this->putJson("/api/content/posts/$id", ['revision' => 1, ...$this->data($user)])->assertConflict();
        $this->postJson("/api/content/posts/$id/review", ['revision' => 1, 'action' => 'request'])->assertConflict();
        $copy = $this->postJson("/api/content/posts/$id/duplicate")->assertCreated()->assertJsonPath('review_status', 'draft')->assertJsonPath('deliveries.0.status', 'draft')->assertJsonPath('deliveries.0.scheduled_at', null)->assertJsonPath('deliveries.0.provider_job_id', null)->json();
        $this->assertNotSame($copy['id'], $id);
        $this->assertNotSame($copy['deliveries'][0]['external_id'], $post->deliveries()->first()->external_id);
    }

    public function test_rejects_duplicate_platforms_invalid_dates_and_arbitrary_metadata(): void
    {
        $user = $this->actingAsUser();
        $data = $this->data($user);
        $data['deliveries'][] = $data['deliveries'][0];
        $this->postJson('/api/content/posts', $data)->assertUnprocessable();
        $data = $this->data($user);
        $data['deliveries'][0]['scheduled_at'] = '2026-02-30T12:00:00+01:00';
        $this->postJson('/api/content/posts', $data)->assertUnprocessable();
        $data = $this->data($user);
        $data['deliveries'][0]['options']['api_key'] = 'BAD';
        $this->postJson('/api/content/posts', $data)->assertUnprocessable();
        $this->assertDatabaseCount('content_posts', 0);
    }

    public function test_media_is_private_and_checks_actual_file_content(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $media = $this->post('/api/content/media', ['file' => UploadedFile::fake()->image('test.jpg')], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertArrayNotHasKey('path', $media);
        $this->get('/api/content/media/'.$media['id'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->post('/api/content/media', ['file' => UploadedFile::fake()->createWithContent('script.jpg', '<?php echo "fake";')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/content/media', ['file' => UploadedFile::fake()->create('large.mp4', 25601, 'video/mp4')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->withSession(['userId' => null])->getJson('/api/content/media/'.$media['id'])->assertUnauthorized();
    }

    public function test_comments_preserve_revision_and_post_content(): void
    {
        $user = $this->actingAsUser();
        $id = $this->postJson('/api/content/posts', $this->data($user))->json('id');
        $this->postJson("/api/content/posts/$id/comments", ['message' => 'Fictieve reviewfeedback'])->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('activities.1.message', 'Fictieve reviewfeedback');
        $this->assertDatabaseCount('content_activities', 2);
    }
}
