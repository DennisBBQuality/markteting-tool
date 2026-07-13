<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketListTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_default_list_hides_closed_tickets_and_returns_meta_envelope(): void
    {
        $this->actingAsUser();
        $open = $this->createTicket(['status' => 'nieuw']);
        $this->createTicket(['status' => 'afgehandeld', 'afgehandeld_op' => now()]);

        $this->getJson('/api/customer-service/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);

        $this->getJson('/api/customer-service/tickets?status=alle')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_status_priority_and_assignee_filters_are_combinable(): void
    {
        $this->actingAsUser();
        $assignee = User::factory()->create();
        $match = $this->createTicket([
            'status' => 'in_behandeling',
            'prioriteit' => 'urgent',
            'behandelaar_id' => $assignee->id,
        ]);
        $this->createTicket([
            'status' => 'in_behandeling',
            'prioriteit' => 'normaal',
            'behandelaar_id' => $assignee->id,
        ]);
        $unassigned = $this->createTicket(['prioriteit' => 'urgent']);

        $query = http_build_query([
            'status' => 'in_behandeling',
            'prioriteit' => 'urgent',
            'behandelaar_id' => $assignee->id,
        ]);
        $this->getJson('/api/customer-service/tickets?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson('/api/customer-service/tickets?niet_toegewezen=1&prioriteit=urgent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unassigned->id);
    }

    public function test_search_finds_each_specified_field_case_insensitively(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket([
            'ticketnummer' => 'CS-2026-54321',
            'onderwerp' => 'Bijzondere Picanha Vraag',
            'klant_naam' => 'Zoekbare Klant',
            'klant_email' => 'uniek@example.test',
        ]);

        foreach (['54321', 'picanha', 'zoekbare', 'UNIEK@EXAMPLE.TEST'] as $term) {
            $this->getJson('/api/customer-service/tickets?zoek='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ticket->id);
        }
    }

    public function test_all_sorting_options_follow_the_specification(): void
    {
        $this->actingAsUser();
        $older = $this->createTicket([
            'prioriteit' => 'laag',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
            'laatste_bericht_op' => now()->subHour(),
        ]);
        $urgent = $this->createTicket([
            'prioriteit' => 'urgent',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'laatste_bericht_op' => now(),
        ]);
        $withoutMessage = $this->createTicket([
            'prioriteit' => 'normaal',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'laatste_bericht_op' => null,
        ]);

        $this->getJson('/api/customer-service/tickets')
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.2.id', $withoutMessage->id);

        $this->getJson('/api/customer-service/tickets?sorteer=aangemaakt&richting=asc')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.2.id', $withoutMessage->id);

        $this->getJson('/api/customer-service/tickets?sorteer=prioriteit')
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('data.1.id', $withoutMessage->id)
            ->assertJsonPath('data.2.id', $older->id);
    }

    public function test_pagination_and_validation_limits_are_applied(): void
    {
        $this->actingAsUser();

        for ($index = 0; $index < 5; $index++) {
            $this->createTicket();
        }

        $this->getJson('/api/customer-service/tickets?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/customer-service/tickets?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/customer-service/tickets?status=open')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;
        $ticket = new Ticket;
        $ticket->forceFill(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Lijsttest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant '.$this->ticketSequence,
            'klant_email' => 'lijst'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
        $ticket->save();

        return $ticket;
    }
}
