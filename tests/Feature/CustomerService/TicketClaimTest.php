<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketClaimTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_claim_assigns_user_changes_new_status_and_logs_activities(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/claim", ['versie' => 1])
            ->assertOk()
            ->assertJsonPath('behandelaar.id', $user->id)
            ->assertJsonPath('status', 'in_behandeling')
            ->assertJsonPath('versie', 2);

        $this->assertEqualsCanonicalizing([
            'ticket_geclaimd',
            'status_gewijzigd',
        ], $ticket->activities()->pluck('actie')->all());
    }

    public function test_claim_conflicts_include_current_ticket_state(): void
    {
        $this->actingAsUser();
        $colleague = User::factory()->create();
        $assigned = $this->createTicket(['behandelaar_id' => $colleague->id]);

        $this->postJson("/api/customer-service/tickets/{$assigned->id}/claim", ['versie' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'claim_conflict')
            ->assertJsonPath('ticket.id', $assigned->id)
            ->assertJsonPath('ticket.behandelaar.id', $colleague->id)
            ->assertJsonStructure(['error', 'code', 'ticket']);

        $closed = $this->createTicket([
            'status' => 'afgehandeld',
            'afgehandeld_op' => now(),
        ]);
        $this->postJson("/api/customer-service/tickets/{$closed->id}/claim", ['versie' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'claim_conflict');
    }

    public function test_two_claims_from_same_version_have_exactly_one_winner(): void
    {
        $winner = $this->actingAsUser();
        $loser = User::factory()->create();
        $ticket = $this->createTicket();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/claim", ['versie' => 1])
            ->assertOk()
            ->assertJsonPath('behandelaar.id', $winner->id);

        $this->withSession(['userId' => $loser->id, 'rol' => $loser->rol]);
        $this->postJson("/api/customer-service/tickets/{$ticket->id}/claim", ['versie' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'claim_conflict')
            ->assertJsonPath('ticket.behandelaar.id', $winner->id)
            ->assertJsonPath('ticket.versie', 2);

        $this->assertSame($winner->id, $ticket->fresh()->behandelaar_id);
    }

    public function test_release_is_limited_to_assignee_and_resets_in_progress_ticket(): void
    {
        $assignee = $this->actingAsUser();
        $colleague = User::factory()->create();
        $ticket = $this->createTicket([
            'status' => 'in_behandeling',
            'behandelaar_id' => $assignee->id,
        ]);

        $this->withSession(['userId' => $colleague->id, 'rol' => $colleague->rol]);
        $this->postJson("/api/customer-service/tickets/{$ticket->id}/release", ['versie' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'claim_conflict');

        $this->withSession(['userId' => $assignee->id, 'rol' => $assignee->rol]);
        $this->postJson("/api/customer-service/tickets/{$ticket->id}/release", ['versie' => 1])
            ->assertOk()
            ->assertJsonPath('behandelaar', null)
            ->assertJsonPath('status', 'nieuw')
            ->assertJsonPath('versie', 2);
    }

    public function test_release_keeps_waiting_status_and_rejects_stale_version(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket([
            'status' => 'wachten_op_klant',
            'behandelaar_id' => $user->id,
            'versie' => 2,
        ]);

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/release", ['versie' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'version_conflict')
            ->assertJsonPath('ticket.versie', 2);

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/release", ['versie' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'wachten_op_klant')
            ->assertJsonPath('behandelaar', null)
            ->assertJsonPath('versie', 3);
    }

    public function test_claim_and_release_validate_version(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/claim", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('versie');
        $this->postJson("/api/customer-service/tickets/{$ticket->id}/release", ['versie' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('versie');
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Claimtest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'claim'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }
}
