<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketMessageTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_incoming_and_assignee_outgoing_messages_are_created(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket(['behandelaar_id' => $user->id]);

        $incoming = $this->postMessage($ticket, 'inkomend', 1, 'Klantbericht');
        $incoming->assertCreated()
            ->assertJsonPath('bericht.richting', 'inkomend')
            ->assertJsonPath('bericht.kanaal', 'handmatig')
            ->assertJsonPath('bericht.auteur.id', $user->id)
            ->assertJsonPath('ticket.versie', 2);

        $outgoing = $this->postMessage($ticket, 'uitgaand', 2, 'Teamantwoord');
        $outgoing->assertCreated()
            ->assertJsonPath('bericht.richting', 'uitgaand')
            ->assertJsonPath('bericht.inhoud', 'Teamantwoord')
            ->assertJsonPath('ticket.versie', 3);
    }

    public function test_non_assignee_cannot_add_outgoing_message(): void
    {
        $this->actingAsUser();
        $assignee = User::factory()->create();
        $ticket = $this->createTicket(['behandelaar_id' => $assignee->id]);

        $this->postMessage($ticket, 'uitgaand', 1, 'Niet toegestaan')
            ->assertConflict()
            ->assertJsonPath('code', 'not_assignee')
            ->assertJsonPath('ticket.behandelaar.id', $assignee->id);

        $this->assertDatabaseCount('cs_ticket_messages', 0);
    }

    public function test_duplicate_client_message_id_returns_existing_message_without_duplicate_row(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();
        $clientMessageId = (string) Str::uuid();

        $first = $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
            'richting' => 'inkomend',
            'inhoud' => 'Eerste bericht',
            'client_message_id' => $clientMessageId,
            'versie' => 1,
        ])->assertCreated();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
            'richting' => 'inkomend',
            'inhoud' => 'Herhaalde submit',
            'client_message_id' => $clientMessageId,
            'versie' => 2,
        ])->assertConflict()
            ->assertJsonPath('code', 'duplicate_message')
            ->assertJsonPath('bericht.id', $first->json('bericht.id'))
            ->assertJsonPath('ticket.versie', 2);

        $this->assertDatabaseCount('cs_ticket_messages', 1);
    }

    public function test_closed_ticket_rejects_messages(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket([
            'status' => 'afgehandeld',
            'afgehandeld_op' => now(),
        ]);

        $this->postMessage($ticket, 'inkomend', 1, 'Te laat')
            ->assertConflict()
            ->assertJsonPath('code', 'ticket_afgehandeld');
    }

    public function test_incoming_message_automatically_reopens_waiting_ticket(): void
    {
        $user = $this->actingAsUser();
        $assigned = $this->createTicket([
            'status' => 'wachten_op_klant',
            'behandelaar_id' => $user->id,
        ]);
        $unassigned = $this->createTicket(['status' => 'wachten_op_klant']);

        $this->postMessage($assigned, 'inkomend', 1, 'Reactie')
            ->assertCreated()
            ->assertJsonPath('ticket.status', 'in_behandeling');
        $this->postMessage($unassigned, 'inkomend', 1, 'Reactie')
            ->assertCreated()
            ->assertJsonPath('ticket.status', 'nieuw');
    }

    public function test_message_index_is_chronological_and_contains_nullable_author(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket();
        $newer = $this->createDirectMessage($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Nieuw',
            'created_at' => now(),
        ]);
        $older = $this->createDirectMessage($ticket, [
            'auteur_id' => null,
            'inhoud' => 'Oud',
            'created_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/customer-service/tickets/{$ticket->id}/messages")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $older->id)
            ->assertJsonPath('0.auteur', null)
            ->assertJsonPath('1.id', $newer->id)
            ->assertJsonPath('1.auteur.id', $user->id);
    }

    public function test_message_request_validates_all_fields(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
            'richting' => 'intern',
            'inhoud' => '',
            'client_message_id' => 'geen-uuid',
            'versie' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'richting',
                'inhoud',
                'client_message_id',
                'versie',
            ]);
    }

    private function postMessage(
        Ticket $ticket,
        string $direction,
        int $version,
        string $content,
    ) {
        return $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
            'richting' => $direction,
            'inhoud' => $content,
            'client_message_id' => (string) Str::uuid(),
            'versie' => $version,
        ]);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Berichtendpoint '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'message'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }

    private function createDirectMessage(Ticket $ticket, array $attributes): TicketMessage
    {
        $message = new TicketMessage;
        $message->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'richting' => 'inkomend',
            'kanaal' => 'handmatig',
            'auteur_id' => null,
            'inhoud' => 'Test',
            'client_message_id' => (string) Str::uuid(),
        ], $attributes));
        $message->save();

        return $message;
    }
}
