<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_update_increments_version_and_logs_activity(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/priority", [
            'prioriteit' => 'urgent',
            'versie' => 1,
        ])->assertOk()
            ->assertJsonPath('prioriteit', 'urgent')
            ->assertJsonPath('versie', 2);

        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actie' => 'prioriteit_gewijzigd',
        ]);
    }

    public function test_priority_update_returns_version_conflict_with_current_state(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket(['prioriteit' => 'hoog', 'versie' => 2]);

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/priority", [
            'prioriteit' => 'urgent',
            'versie' => 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'version_conflict')
            ->assertJsonPath('ticket.prioriteit', 'hoog')
            ->assertJsonPath('ticket.versie', 2);
    }

    public function test_priority_request_validates_priority_and_version(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/priority", [
            'prioriteit' => 'kritiek',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['prioriteit', 'versie']);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        return Ticket::query()->create(array_merge([
            'ticketnummer' => 'CS-2026-00001',
            'onderwerp' => 'Prioriteitstest',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'prioriteit@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }
}
