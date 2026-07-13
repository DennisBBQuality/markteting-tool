<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNoteTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_any_authenticated_user_can_add_internal_note(): void
    {
        $user = $this->actingAsUser();
        $assignee = User::factory()->create();
        $ticket = $this->createTicket(['behandelaar_id' => $assignee->id]);

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/notes", [
            'inhoud' => 'Alleen intern zichtbaar',
            'versie' => 1,
        ])->assertCreated()
            ->assertJsonPath('notitie.inhoud', 'Alleen intern zichtbaar')
            ->assertJsonPath('notitie.auteur.id', $user->id)
            ->assertJsonPath('ticket.versie', 2);

        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actie' => 'notitie_toegevoegd',
        ]);
    }

    public function test_closed_ticket_rejects_note(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket([
            'status' => 'afgehandeld',
            'afgehandeld_op' => now(),
        ]);

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/notes", [
            'inhoud' => 'Niet toegestaan',
            'versie' => 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'ticket_afgehandeld');

        $this->assertDatabaseCount('cs_ticket_notes', 0);
    }

    public function test_notes_are_returned_chronologically_with_nullable_author(): void
    {
        $user = $this->actingAsUser();
        $ticket = $this->createTicket();
        $newer = $this->createNote($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Nieuw',
            'created_at' => now(),
        ]);
        $older = $this->createNote($ticket, [
            'auteur_id' => null,
            'inhoud' => 'Oud',
            'created_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/customer-service/tickets/{$ticket->id}/notes")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $older->id)
            ->assertJsonPath('0.auteur', null)
            ->assertJsonPath('1.id', $newer->id)
            ->assertJsonPath('1.auteur.id', $user->id);
    }

    public function test_note_request_validates_content_and_version(): void
    {
        $this->actingAsUser();
        $ticket = $this->createTicket();

        $this->postJson("/api/customer-service/tickets/{$ticket->id}/notes", [
            'inhoud' => '',
            'versie' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['inhoud', 'versie']);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Notitietest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'note'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }

    private function createNote(Ticket $ticket, array $attributes): TicketNote
    {
        $note = new TicketNote;
        $note->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'auteur_id' => null,
            'inhoud' => 'Testnotitie',
        ], $attributes));
        $note->save();

        return $note;
    }
}
