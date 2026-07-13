<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_history_is_complete_and_newest_first(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket();
        $older = $this->createActivity($ticket, [
            'gebruiker_id' => null,
            'actie' => 'ticket_aangemaakt',
            'details' => ['prioriteit' => 'normaal'],
            'created_at' => now()->subMinute(),
        ]);
        $newer = $this->createActivity($ticket, [
            'gebruiker_id' => $user->id,
            'actie' => 'status_gewijzigd',
            'details' => ['van' => 'nieuw', 'naar' => 'in_behandeling'],
            'created_at' => now(),
        ]);

        $this->getJson("/api/customer-service/tickets/{$ticket->id}/activities")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newer->id)
            ->assertJsonPath('0.gebruiker.id', $user->id)
            ->assertJsonPath('0.details.van', 'nieuw')
            ->assertJsonPath('1.id', $older->id)
            ->assertJsonPath('1.gebruiker', null);
    }

    public function test_no_activity_mutation_routes_exist(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();
        $activity = $this->createActivity($ticket);
        $url = "/api/customer-service/tickets/{$ticket->id}/activities/{$activity->id}";

        $this->putJson($url, ['actie' => 'gewijzigd'])->assertMethodNotAllowed();
        $this->deleteJson($url)->assertMethodNotAllowed();
    }

    private function createTicket(): Ticket
    {
        return Ticket::query()->create([
            'ticketnummer' => 'CS-2026-00001',
            'onderwerp' => 'Activiteitentest',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'activity@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ]);
    }

    private function createActivity(Ticket $ticket, array $attributes = []): TicketActivity
    {
        $activity = new TicketActivity;
        $activity->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'gebruiker_id' => null,
            'actie' => 'ticket_aangemaakt',
            'details' => null,
        ], $attributes));
        $activity->save();

        return $activity;
    }
}
