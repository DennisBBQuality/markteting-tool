<?php

namespace Tests\Unit\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_outgoing_message_is_restricted_to_current_assignee(): void
    {
        $assignee = User::factory()->create();
        $colleague = User::factory()->create();
        $ticket = $this->createTicket(['behandelaar_id' => $assignee->id]);

        $exception = $this->captureConflict(fn (): array => $this->addMessage(
            $ticket,
            $colleague,
            'uitgaand',
            1
        ));

        $this->assertSame('not_assignee', $exception->machineCode);
        $this->assertDatabaseCount('cs_ticket_messages', 0);

        $result = $this->addMessage($ticket, $assignee, 'uitgaand', 1);
        $this->assertSame('uitgaand', $result['bericht']->richting);
        $this->assertSame($assignee->id, $result['bericht']->auteur_id);
    }

    public function test_incoming_message_reopens_waiting_ticket_according_to_assignment(): void
    {
        $user = User::factory()->create();
        $assigned = $this->createTicket([
            'status' => 'wachten_op_klant',
            'behandelaar_id' => $user->id,
        ]);
        $unassigned = $this->createTicket(['status' => 'wachten_op_klant']);

        $assignedResult = $this->addMessage($assigned, $user, 'inkomend', 1);
        $unassignedResult = $this->addMessage($unassigned, $user, 'inkomend', 1);

        $this->assertSame('in_behandeling', $assignedResult['ticket']->status);
        $this->assertSame('nieuw', $unassignedResult['ticket']->status);
        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $assigned->id,
            'actie' => 'status_gewijzigd',
        ]);
        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $unassigned->id,
            'actie' => 'status_gewijzigd',
        ]);
    }

    public function test_message_updates_last_message_time_version_and_activity(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $result = $this->addMessage($ticket, $user, 'inkomend', 1);

        $this->assertSame(2, $result['ticket']->versie);
        $this->assertNotNull($result['ticket']->laatste_bericht_op);
        $this->assertTrue(
            $result['ticket']->laatste_bericht_op->equalTo($result['bericht']->created_at)
        );
        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actie' => 'bericht_toegevoegd',
        ]);
    }

    public function test_duplicate_client_message_id_returns_existing_message_without_new_row(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();
        $clientMessageId = (string) Str::uuid();
        $first = $this->service()->addMessage($ticket, $user, [
            'richting' => 'inkomend',
            'inhoud' => 'Eerste poging',
            'client_message_id' => $clientMessageId,
            'versie' => 1,
        ]);

        $exception = $this->captureConflict(fn (): array => $this->service()->addMessage(
            $first['ticket'],
            $user,
            [
                'richting' => 'inkomend',
                'inhoud' => 'Dubbele poging',
                'client_message_id' => $clientMessageId,
                'versie' => 2,
            ]
        ));

        $this->assertSame('duplicate_message', $exception->machineCode);
        $this->assertTrue($exception->existingMessage->is($first['bericht']));
        $this->assertDatabaseCount('cs_ticket_messages', 1);
        $this->assertSame(2, $ticket->fresh()->versie);
    }

    public function test_closed_ticket_rejects_messages_and_notes(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket([
            'status' => 'afgehandeld',
            'afgehandeld_op' => now(),
        ]);

        $messageConflict = $this->captureConflict(
            fn (): array => $this->addMessage($ticket, $user, 'inkomend', 1)
        );
        $noteConflict = $this->captureConflict(
            fn (): array => $this->service()->addNote($ticket, $user, 'Notitie', 1)
        );

        $this->assertSame('ticket_afgehandeld', $messageConflict->machineCode);
        $this->assertSame('ticket_afgehandeld', $noteConflict->machineCode);
        $this->assertDatabaseCount('cs_ticket_messages', 0);
        $this->assertDatabaseCount('cs_ticket_notes', 0);
    }

    public function test_note_increments_version_and_logs_activity(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();

        $result = $this->service()->addNote($ticket, $user, 'Interne testnotitie', 1);

        $this->assertSame('Interne testnotitie', $result['notitie']->inhoud);
        $this->assertSame($user->id, $result['notitie']->auteur_id);
        $this->assertSame(2, $result['ticket']->versie);
        $this->assertDatabaseHas('cs_ticket_activities', [
            'ticket_id' => $ticket->id,
            'actie' => 'notitie_toegevoegd',
        ]);
    }

    public function test_stale_version_rejects_message_without_partial_records(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket(['versie' => 2]);

        $exception = $this->captureConflict(
            fn (): array => $this->addMessage($ticket, $user, 'inkomend', 1)
        );

        $this->assertSame('version_conflict', $exception->machineCode);
        $this->assertDatabaseCount('cs_ticket_messages', 0);
        $this->assertDatabaseCount('cs_ticket_activities', 0);
    }

    private function addMessage(
        Ticket $ticket,
        User $user,
        string $richting,
        int $versie,
    ): array {
        return $this->service()->addMessage($ticket, $user, [
            'richting' => $richting,
            'inhoud' => 'Testbericht',
            'client_message_id' => (string) Str::uuid(),
            'versie' => $versie,
        ]);
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create(array_merge([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Berichttest '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'bericht'.$this->ticketSequence.'@example.test',
            'status' => 'nieuw',
            'prioriteit' => 'normaal',
            'versie' => 1,
        ], $attributes));
    }

    private function service(): TicketMessageService
    {
        return app(TicketMessageService::class);
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
