<?php

namespace Tests\Feature;

use App\Jobs\SendAssignmentNotification;
use App\Mail\AssignmentMail;
use App\Models\PitboardNotification;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function taskData(array $ids): array
    {
        return ['titel' => 'TEST Beelden controleren', 'status' => 'todo', 'beschrijving' => '', 'prioriteit' => 'normaal', 'deadline' => '2026-10-02', 'toegewezen_aan' => $ids];
    }

    private function emailConfig(): void
    {
        Bus::fake([SendAssignmentNotification::class]);
        config(['pitboard_notifications.email_enabled' => true, 'pitboard_notifications.from_address' => 'pitboard@example.test', 'pitboard_notifications.url' => 'https://pitboard.example.test']);
    }

    public function test_new_assignment_notifies_only_new_active_recipients_and_not_actor(): void
    {
        Mail::fake();
        $actor = $this->actingAsUser();
        $colleague = User::factory()->create();
        $inactive = User::factory()->create(['actief' => false]);
        $data = $this->taskData([$actor->id, $colleague->id, $colleague->id, $inactive->id]);
        $task = $this->postJson('/api/tasks', $data)->assertOk()->json();
        $this->assertDatabaseCount('pitboard_notifications', 1);
        $this->assertDatabaseCount('taak_gebruiker', 3);
        $this->assertDatabaseHas('pitboard_notifications', ['user_id' => $colleague->id, 'target_id' => $task['id'], 'email_status' => 'disabled', 'actor_name' => $actor->naam]);
        $this->putJson('/api/tasks/'.$task['id'], $data)->assertOk();
        $this->assertDatabaseCount('pitboard_notifications', 1);
        $third = User::factory()->create();
        $this->putJson('/api/tasks/'.$task['id'], $this->taskData([$colleague->id, $third->id]))->assertOk();
        $this->assertDatabaseCount('pitboard_notifications', 2);
        $this->putJson('/api/tasks/'.$task['id'], $this->taskData([]))->assertOk();
        $this->putJson('/api/tasks/'.$task['id'], $this->taskData([$colleague->id]))->assertOk();
        $this->assertDatabaseCount('pitboard_notifications', 3);
        Mail::assertNothingSent();
    }

    public function test_project_additions_notify_and_unchanged_members_do_not(): void
    {
        $actor = $this->actingAsUser();
        $colleague = User::factory()->create();
        $data = ['naam' => 'TEST Najaar', 'medewerkers' => [$actor->id, $colleague->id], 'status' => 'actief', 'prioriteit' => 'normaal', 'kleur' => '#ef454b'];
        $id = $this->postJson('/api/projects', $data)->assertOk()->json('id');
        $this->putJson('/api/projects/'.$id, $data)->assertOk();
        $this->assertDatabaseCount('pitboard_notifications', 1);
        $this->assertDatabaseHas('pitboard_notifications', ['kind' => 'project', 'user_id' => $colleague->id, 'target_id' => $id]);
        unset($data['medewerkers']);
        $this->putJson('/api/projects/'.$id, $data)->assertOk();
        $this->assertDatabaseCount('project_gebruiker', 2);
    }

    public function test_invalid_assignments_roll_back_task_and_project_changes(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/tasks', $this->taskData([(string) Str::uuid()]))->assertUnprocessable();
        $this->postJson('/api/projects', ['naam' => 'TEST invalid', 'medewerkers' => ['invalid']])->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('pitboard_notifications', 0);
    }

    public function test_notification_ownership_read_status_and_preferences_are_isolated(): void
    {
        $actor = $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $notification = PitboardNotification::firstOrFail();
        $this->getJson('/api/notifications?user_id='.$colleague->id)->assertJsonPath('unread_count', 0);
        $this->getJson('/api/notifications/'.$notification->id)->assertNotFound();
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertNull($notification->fresh()->read_at);
        $this->withSession(['userId' => $colleague->id]);
        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'items');
        $this->getJson('/api/notifications/'.$notification->id)->assertOk()->assertJsonMissingPath('user_id')->assertJsonMissingPath('email_status');
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->getJson('/api/notifications?unread=1')->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'items');
        $this->getJson('/api/notifications')->assertJsonCount(1, 'items');
        $this->getJson('/api/notifications/preferences')->assertJsonPath('task_email', true)->assertJsonPath('email_active', false);
        $this->putJson('/api/notifications/preferences', ['task_email' => false, 'project_email' => true, 'user_id' => $actor->id])->assertOk();
        $this->assertDatabaseHas('pitboard_notification_preferences', ['user_id' => $colleague->id, 'task_email' => false]);
        $this->withSession(['userId' => $actor->id]);
        $this->getJson('/api/notifications/preferences')->assertJsonPath('task_email', true);
    }

    public function test_preferences_opt_out_email_not_in_app_notification(): void
    {
        $this->emailConfig();
        $actor = $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->withSession(['userId' => $colleague->id]);
        $this->putJson('/api/notifications/preferences', ['task_email' => false, 'project_email' => false])->assertOk();
        $this->withSession(['userId' => $actor->id]);
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $this->assertDatabaseHas('pitboard_notifications', ['user_id' => $colleague->id, 'email_status' => 'opted_out']);
    }

    public function test_sender_is_dedicated_and_mail_job_claims_only_once(): void
    {
        Mail::fake();
        $this->emailConfig();
        $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $notification = PitboardNotification::firstOrFail();
        $job = new SendAssignmentNotification($notification->id);
        $job->handle(app(AssignmentNotifications::class));
        $job->handle(app(AssignmentNotifications::class));
        Mail::assertSent(AssignmentMail::class, 1);
        Mail::assertSent(AssignmentMail::class, fn ($mail) => $mail->hasTo($colleague->email)
            && $mail->envelope()->from->address === 'pitboard@example.test'
            && $mail->envelope()->from->name === 'BBQuality Pitboard');
        $this->assertSame('accepted', $notification->fresh()->email_status);
        $rendered = (new AssignmentMail($notification))->render();
        $this->assertStringContainsString('/?melding='.$notification->id, $rendered);
        $this->assertStringContainsString('02-10-2026', $rendered);
        $this->assertStringContainsString('Bekijk taak', $rendered);
        $this->assertStringContainsString('BBQuality Pitboard', $rendered);
        $this->assertStringStartsWith('BBQuality Pitboard · ', (new AssignmentMail($notification))->envelope()->subject);
        $this->getJson('/api/notifications/preferences')->assertJsonPath('sender_name', 'BBQuality Pitboard');
    }

    public function test_job_rechecks_assignment_and_local_environment_never_sends(): void
    {
        Mail::fake();
        $this->emailConfig();
        $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $notification = PitboardNotification::firstOrFail();
        DB::table('taak_gebruiker')->delete();
        (new SendAssignmentNotification($notification->id))->handle(app(AssignmentNotifications::class));
        $this->assertSame('cancelled', $notification->fresh()->email_status);
        $this->app->instance('env', 'local');
        $this->assertFalse(app(AssignmentNotifications::class)->emailReady());
        $this->app->instance('env', 'testing');
        config(['pitboard_notifications.email_enabled' => false]);
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $this->assertDatabaseHas('pitboard_notifications', ['email_status' => 'disabled']);
        Mail::assertNothingSent();
    }

    public function test_ambiguous_transport_failure_is_not_retried_and_inbox_survives(): void
    {
        $this->emailConfig();
        $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('TEST transport failure'));
        $notification = PitboardNotification::firstOrFail();
        $job = new SendAssignmentNotification($notification->id);
        $job->handle(app(AssignmentNotifications::class));
        $job->handle(app(AssignmentNotifications::class));
        $this->assertSame('uncertain', $notification->fresh()->email_status);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_deleted_target_is_clear_and_private(): void
    {
        $this->actingAsUser();
        $colleague = User::factory()->create();
        $this->postJson('/api/tasks', $this->taskData([$colleague->id]))->assertOk();
        $notification = PitboardNotification::firstOrFail();
        Task::findOrFail($notification->target_id)->delete();
        $this->getJson('/api/notifications/'.$notification->id)->assertNotFound();
        $this->withSession(['userId' => $colleague->id]);
        $this->getJson('/api/notifications/'.$notification->id)->assertStatus(410);
    }

    public function test_endpoints_require_login_and_validate_preferences(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/preferences')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
        $this->actingAsUser();
        $this->putJson('/api/notifications/preferences', ['task_email' => 'bad'])->assertUnprocessable();
        $this->getJson('/api/notifications?page=-1')->assertUnprocessable();
    }
}
