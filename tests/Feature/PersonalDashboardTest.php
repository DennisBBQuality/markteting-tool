<?php

namespace Tests\Feature;

use App\Models\CalendarItem;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_lists_require_login(): void
    {
        $this->getJson('/api/tasks?mine=1')->assertUnauthorized();
        $this->getJson('/api/notes?mine=1')->assertUnauthorized();
    }

    public function test_my_tasks_use_session_assignment_even_for_admin_and_shared_tasks(): void
    {
        $me = $this->actingAsUser(['rol' => 'admin']);
        $other = User::factory()->create();
        $mine = Task::create(['titel' => 'TEST shared task']);
        $theirs = Task::create(['titel' => 'TEST other task']);
        Task::create(['titel' => 'TEST unassigned']);
        foreach ([[$mine, $me], [$mine, $other], [$theirs, $other]] as [$task, $user]) {
            DB::table('taak_gebruiker')->insert(['id' => (string) Str::uuid(), 'task_id' => $task->id, 'user_id' => $user->id]);
        }
        $this->getJson('/api/tasks?mine=1&userId='.$other->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);
        $this->getJson('/api/tasks')->assertJsonCount(3);
        $this->withSession(['userId' => $other->id, 'rol' => $other->rol]);
        $this->getJson('/api/tasks?mine=1')->assertJsonCount(2);
    }

    public function test_my_notes_are_authored_by_session_user_not_another_author_or_unowned(): void
    {
        $me = $this->actingAsUser();
        $other = User::factory()->create();
        $mine = Note::create(['titel' => 'TEST my note', 'aangemaakt_door' => $me->id]);
        Note::create(['titel' => 'TEST other note', 'aangemaakt_door' => $other->id]);
        Note::create(['titel' => 'TEST no author']);
        $this->getJson('/api/notes?mine=1&aangemaakt_door='.$other->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);
        $this->getJson('/api/notes')->assertJsonCount(3);
        $this->withSession(['userId' => $other->id, 'rol' => $other->rol]);
        $this->getJson('/api/notes?mine=1')->assertJsonCount(1)->assertJsonPath('0.titel', 'TEST other note');
    }

    public function test_week_includes_sunday_and_overlapping_events_but_not_next_monday_or_ended_events(): void
    {
        $this->actingAsUser();
        foreach ([
            ['TEST Monday', '2026-09-14T00:00:00', null],
            ['TEST Sunday', '2026-09-20T23:30:00', null],
            ['TEST overlap', '2026-09-13T10:00:00', '2026-09-14T11:00:00'],
            ['TEST next week', '2026-09-21T00:00:00', null],
            ['TEST ended', '2026-09-13T10:00:00', '2026-09-14T00:00:00'],
            ['TEST past', '2026-09-13T10:00:00', null],
        ] as [$title, $start, $end]) {
            CalendarItem::create(['titel' => $title, 'datum_start' => $start, 'datum_eind' => $end]);
        }
        $this->getJson('/api/calendar?overlap=1&start=2026-09-14&end=2026-09-21')->assertOk()->assertJsonCount(3)
            ->assertJsonFragment(['titel' => 'TEST overlap'])->assertJsonFragment(['titel' => 'TEST Sunday'])
            ->assertJsonMissing(['titel' => 'TEST ended'])->assertJsonMissing(['titel' => 'TEST next week']);
        $this->getJson('/api/calendar?overlap=1&start=bad&end=2026-09-21')->assertUnprocessable();
        $this->getJson('/api/calendar?overlap=1&start=2026-09-21&end=2026-09-14')->assertUnprocessable();
    }
}
