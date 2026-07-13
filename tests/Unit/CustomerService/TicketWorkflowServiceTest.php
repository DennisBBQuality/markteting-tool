<?php

namespace Tests\Unit\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_all_allowed_status_transitions_succeed(): void
    {
        $user = User::factory()->create();
        $transitions = [
            ['nieuw', 'in_behandeling', true],
            ['nieuw', 'afgehandeld', false],
            ['in_behandeling', 'wachten_op_klant', true],
            ['in_behandeling', 'afgehandeld', true],
            ['wachten_op_klant', 'in_behandeling', true],
            ['wachten_op_klant', 'afgehandeld', true],
            ['afgehandeld', 'in_behandeling', false],
        ];

        foreach ($transitions as [$from, $to, $withAssignee]) {
            $ticket = $this->createTicket([
                'status' => $from,
                'behandelaar_id' => $withAssignee ? $user->id : null,
                'afgehandeld_op' => $from === 'afgehandeld' ? now() : null,
            ]);

            $result = $this->service()->changeStatus($ticket, $user, $to, 1);

            $this->assertSame($to, $result->status, "Transition {$from} -> {$to}");
            $this->assertSame(2, $result->versie);
            $this->assertDatabaseHas('cs_ticket_activities', [
                'ticket_id' => $ticket->id,
                'actie' => 'status_gewijzigd',
            ]);

            if ($from === 'afgehandeld') {
                $this->assertSame($user->id, $result->behandelaar_id);
            }
        }
    }

    public function test_forbidden_status_transitions_and_no_ops_fail(): void
    {
        $user = User::factory()->create();
        $transitions = [
            ['nieuw', 'wachten_op_klant'],
            ['in_behandeling', 'nieuw'],
            ['wachten_op_klant', 'nieuw'],
            ['afgehandeld', 'nieuw'],
            ['afgehandeld', 'wachten_op_klant'],
            ['nieuw', 'nieuw'],
            ['in_behandeling', 'in_behandeling'],
            ['wachten_op_klant', 'wachten_op_klant'],
            ['afgehandeld', 'afgehandeld'],
        ];

        foreach ($transitions as [$from, $to]) {
            $ticket = $this->createTicket([
                'status' => $from,
                'behandelaar_id' => $from === 'nieuw' ? null : $user->id,
                'afgehandeld_op' => $from === 'afgehandeld' ? now() : null,
            ]);

            $exception = $this->captureConflict(
                fn (): Ticket => $this->service()->changeStatus($ticket, $user, $to, 1)
            );

            $this->assertSame('invalid_status_transition', $exception->machineCode);
            $this->assertSame($from, $ticket->fresh()->status);
            $this->assertSame(1, $ticket->fresh()->versie);
        }
    }

    public function test_new_ticket_cannot_move_to_in_progress_without_an_assignee(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $exception = $this->captureConflict(
            fn (): Ticket => $this->service()->changeStatus(
                $ticket,
                $user,
                'in_behandeling',
                1
            )
        );

        $this->assertSame('invalid_status_transition', $exception->machineCode);
    }

    public function test_claim_assigns_user_changes_new_status_and_logs_activities(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $result = $this->service()->claim($ticket, $user, 1);

        $this->assertSame($user->id, $result->behandelaar_id);
        $this->assertSame('in_behandeling', $result->status);
        $this->assertSame(2, $result->versie);
        $this->assertEqualsCanonicalizing([
            'ticket_geclaimd',
            'status_gewijzigd',
        ], $result->activities()->pluck('actie')->all());
    }

    public function test_claim_rejects_assigned_closed_and_stale_tickets(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $assigned = $this->createTicket(['behandelaar_id' => $colleague->id]);
        $assignedConflict = $this->captureConflict(
            fn (): Ticket => $this->service()->claim($assigned, $user, 1)
        );
        $this->assertSame('claim_conflict', $assignedConflict->machineCode);

        $closed = $this->createTicket(['status' => 'afgehandeld', 'afgehandeld_op' => now()]);
        $closedConflict = $this->captureConflict(
            fn (): Ticket => $this->service()->claim($closed, $user, 1)
        );
        $this->assertSame('claim_conflict', $closedConflict->machineCode);

        $stale = $this->createTicket(['versie' => 2]);
        $versionConflict = $this->captureConflict(
            fn (): Ticket => $this->service()->claim($stale, $user, 1)
        );
        $this->assertSame('version_conflict', $versionConflict->machineCode);
        $this->assertSame(2, $versionConflict->ticket->versie);
    }

    public function test_release_is_restricted_to_assignee_and_updates_status_correctly(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();
        $ticket = $this->createTicket([
            'status' => 'in_behandeling',
            'behandelaar_id' => $user->id,
        ]);

        $conflict = $this->captureConflict(
            fn (): Ticket => $this->service()->release($ticket, $colleague, 1)
        );
        $this->assertSame('claim_conflict', $conflict->machineCode);

        $released = $this->service()->release($ticket, $user, 1);
        $this->assertNull($released->behandelaar_id);
        $this->assertSame('nieuw', $released->status);
        $this->assertSame(2, $released->versie);
        $this->assertEqualsCanonicalizing([
            'ticket_vrijgegeven',
            'status_gewijzigd',
        ], $released->activities()->pluck('actie')->all());

        $waiting = $this->createTicket([
            'status' => 'wachten_op_klant',
            'behandelaar_id' => $user->id,
        ]);
        $releasedWaiting = $this->service()->release($waiting, $user, 1);
        $this->assertSame('wachten_op_klant', $releasedWaiting->status);
        $this->assertNull($releasedWaiting->behandelaar_id);
    }

    public function test_closed_ticket_cannot_be_released(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket([
            'status' => 'afgehandeld',
            'behandelaar_id' => $user->id,
            'afgehandeld_op' => now(),
        ]);

        $exception = $this->captureConflict(
            fn (): Ticket => $this->service()->release($ticket, $user, 1)
        );

        $this->assertSame('claim_conflict', $exception->machineCode);
    }

    public function test_closing_sets_timestamp_and_reopening_clears_it_and_assigns_user(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $closed = $this->service()->changeStatus($ticket, $user, 'afgehandeld', 1);

        $this->assertNotNull($closed->afgehandeld_op);
        $this->assertNull($closed->behandelaar_id);

        $reopened = $this->service()->changeStatus($closed, $user, 'in_behandeling', 2);

        $this->assertNull($reopened->afgehandeld_op);
        $this->assertSame($user->id, $reopened->behandelaar_id);
        $this->assertSame(3, $reopened->versie);
    }

    public function test_priority_change_increments_version_logs_activity_and_rejects_stale_version(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $updated = $this->service()->changePriority($ticket, $user, 'hoog', 1);

        $this->assertSame('hoog', $updated->prioriteit);
        $this->assertSame(2, $updated->versie);
        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actie' => 'prioriteit_gewijzigd',
        ]);

        $exception = $this->captureConflict(
            fn (): Ticket => $this->service()->changePriority($updated, $user, 'urgent', 1)
        );

        $this->assertSame('version_conflict', $exception->machineCode);
        $this->assertSame(2, $exception->ticket->versie);
        $this->assertSame('hoog', $updated->fresh()->prioriteit);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Workflowtest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'workflow'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }

    private function service(): TicketWorkflowService
    {
        return app(TicketWorkflowService::class);
    }

    private function captureConflict(callable $callback): TicketConflictException
    {
        try {
            $callback();
        } catch (TicketConflictException $exception) {
            return $exception;
        }

        $this->fail('Expected a TicketConflictException.');
    }
}
