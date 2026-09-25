<?php

namespace Tests\Feature;

use App\Models\PitboardNotification;
use App\Models\ProductDossier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PitboardUserConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_reading_a_target_is_idempotent_and_only_clears_its_current_users_notifications_for_every_role(): void
    {
        $actor = $this->actingAsUser();
        $users = collect(['admin', 'manager', 'lid'])->map(fn ($role) => User::factory()->create(['rol' => $role]));
        $ids = $users->pluck('id')->all();
        $task = $this->postJson('/api/tasks', ['titel' => 'TEST gedeelde taak', 'toegewezen_aan' => $ids])->assertOk()->json('id');
        $project = $this->postJson('/api/projects', ['naam' => 'TEST gedeeld project', 'medewerkers' => $ids])->assertOk()->json('id');
        foreach ($users as $index => $user) {
            $this->withSession(['userId' => $user->id]);
            $this->getJson('/api/tasks')->assertOk();
            $this->getJson('/api/projects')->assertOk();
            $this->getJson('/api/notifications')->assertJsonPath('unread_count', 2);
            foreach (['task' => $task, 'project' => $project] as $kind => $id) {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $this->postJson('/api/notifications/read-target', ['kind' => $kind, 'target_id' => $id, 'user_id' => $actor->id])
                        ->assertOk()->assertJsonPath('unread_count', $kind === 'task' ? 1 : 0);
                }
            }
            $this->assertSame((2 - $index) * 2, PitboardNotification::whereNull('read_at')->count());
        }
        $this->postJson('/api/notifications/read-target', ['kind' => 'anything', 'target_id' => $task])->assertUnprocessable();
        $this->postJson('/api/notifications/read-target', ['kind' => 'task', 'target_id' => 'missing'])->assertNotFound();
    }

    public function test_all_shared_settings_reject_members_managers_and_stale_admin_sessions(): void
    {
        foreach (['lid', 'manager'] as $role) {
            $user = $this->actingAsUser(['rol' => 'admin']);
            $user->update(['rol' => $role]);
            $this->getJson('/api/images/prompt')->assertForbidden();
            $this->putJson('/api/images/prompt', ['prompt' => str_repeat('TEST ', 8)])->assertForbidden();
            $this->postJson('/api/product-dossier-options', ['type' => 'category', 'label' => 'TEST'])->assertForbidden();
            $this->deleteJson('/api/product-dossier-options/1')->assertForbidden();
            $this->getJson('/api/settings/ai/openai')->assertForbidden();
            $this->getJson('/api/notification-mail')->assertForbidden();
            $this->postJson('/api/users', [])->assertForbidden();
            // Selecting existing options and personal preferences are not admin settings.
            $this->getJson('/api/product-dossier-options')->assertOk();
            $this->putJson('/api/notifications/preferences', ['task_email' => true, 'project_email' => true])->assertOk();
        }
    }

    public function test_attachments_accept_exactly_25_mb_and_reject_larger_files_for_every_role(): void
    {
        foreach (['admin', 'manager', 'lid'] as $role) {
            $this->actingAsUser(['rol' => $role]);
            $this->post('/api/attachments', ['bestand' => UploadedFile::fake()->image('TEST.jpg')->size(25600)], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('grootte', 25600 * 1024);
            $this->post('/api/attachments', ['bestand' => UploadedFile::fake()->image('TEST.jpg')->size(25601)], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('bestand');
        }
    }

    public function test_image_uploads_share_the_same_25_mb_boundary_without_starting_real_ai_jobs(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->actingAsUser();
        $dossier = ProductDossier::create(['user_id' => $user->id, 'product_name' => 'TEST product']);
        $cases = [
            ['/api/images/generate', 'foto', false, 202],
            ['/api/images/generate', 'fotos', true, 202],
            ["/api/product-dossiers/{$dossier->id}/labels", 'labels', true, 200],
            ["/api/product-dossiers/{$dossier->id}/expert-assets/photo", 'file', false, 200],
            ['/api/convert/webp', 'bestanden', true, 200],
        ];
        foreach ($cases as [$url, $field, $multiple, $status]) {
            foreach ([25600, 25601] as $size) {
                $file = UploadedFile::fake()->image('TEST.jpg', 20, 20)->size($size);
                $response = $this->post($url, [$field => $multiple ? [$file] : $file], ['Accept' => 'application/json']);
                if ($size === 25600) {
                    $response->assertStatus($status);
                } else {
                    $response->assertUnprocessable()->assertJsonValidationErrors($multiple ? $field.'.0' : $field);
                }
            }
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
