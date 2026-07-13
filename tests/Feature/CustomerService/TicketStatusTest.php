<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_all_allowed_status_transitions_work_over_http(): void
    {
        $user = $this->actingAsUser();
        $transitions = [
            ['nieuw', 'in_behandeling', true],
            ['nieuw', 'afgehandeld', false],
            ['in_behandeling', 'wachten_op_klant', true],
            ['in_behandeling', 'afgehandeld', true],
            ['wachten_op_klant', 'in_behandeling', true],
            ['wachten_op_klant', 'afgehandeld', true],
            ['afgehandeld', 'in_behandeling', false],
        ];

        foreach ($transitions as [$from, $to, $assigned]) {
            $ticket = $this->createTicket([
                'status' => $from,
                'behandelaar_id' => $assigned ? $user->id : null,
                'afgehandeld_op' => $from === 'afgehandeld' ? now() : null,
            ]);

            $response = $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
                'status' => $to,
                'versie' => 1,
            ]);

            $response->assertOk()
                ->assertJsonPath('status', $to)
                ->assertJsonPath('versie', 2);

            if ($from === 'afgehandeld') {
                $response->assertJsonPath('behandelaar.id', $user->id)
                    ->assertJsonPath('afgehandeld_op', null);
            }
        }
    }

    public function test_forbidden_transitions_return_structured_conflicts(): void
    {
        $user = $this->actingAsUser();
        $transitions = [
            ['nieuw', 'wachten_op_klant'],
            ['in_behandeling', 'nieuw'],
            ['wachten_op_klant', 'nieuw'],
            ['afgehandeld', 'nieuw'],
            ['afgehandeld', 'wachten_op_klant'],
            ['nieuw', 'nieuw'],
        ];

        foreach ($transitions as [$from, $to]) {
            $ticket = $this->createTicket([
                'status' => $from,
                'behandelaar_id' => $from === 'nieuw' ? null : $user->id,
                'afgehandeld_op' => $from === 'afgehandeld' ? now() : null,
            ]);

            $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
                'status' => $to,
                'versie' => 1,
            ])->assertConflict()
                ->assertJsonPath('code', 'invalid_status_transition')
                ->assertJsonPath('ticket.status', $from)
                ->assertJsonPath('ticket.versie', 1);
        }
    }

    public function test_version_conflict_returns_current_ticket_state(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket([
            'status' => 'in_behandeling',
            'behandelaar_id' => $user->id,
            'versie' => 2,
        ]);

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
            'status' => 'afgehandeld',
            'versie' => 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'version_conflict')
            ->assertJsonPath('ticket.versie', 2)
            ->assertJsonPath('ticket.status', 'in_behandeling');
    }

    public function test_closing_and_reopening_manage_closed_timestamp_and_assignee(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket();

        $closed = $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
            'status' => 'afgehandeld',
            'versie' => 1,
        ])->assertOk()->assertJsonPath('status', 'afgehandeld');
        $this->assertNotNull($closed->json('afgehandeld_op'));

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
            'status' => 'in_behandeling',
            'versie' => 2,
        ])->assertOk()
            ->assertJsonPath('afgehandeld_op', null)
            ->assertJsonPath('behandelaar.id', $user->id)
            ->assertJsonPath('versie', 3);
    }

    public function test_status_request_validates_status_and_version(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->putJson("/api/customer-service/tickets/{$ticket->id}/status", [
            'status' => 'open',
            'versie' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'versie']);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Statustest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'status'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }
}
