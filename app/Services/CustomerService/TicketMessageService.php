<?php

namespace App\Services\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketMessage;
use App\Models\CustomerService\TicketNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TicketMessageService
{
    public function __construct(private readonly TicketActivityLogger $activityLogger) {}

    public function addInitialMessage(Ticket $ticket, User $user, string $inhoud): TicketMessage
    {
        $message = $ticket->messages()->create([
            'richting' => 'inkomend',
            'kanaal' => 'handmatig',
            'auteur_id' => $user->id,
            'inhoud' => $inhoud,
            'client_message_id' => (string) str()->uuid(),
        ]);

        $ticket->forceFill(['laatste_bericht_op' => $message->created_at])->save();
        $this->activityLogger->log($ticket, $user, 'bericht_toegevoegd', [
            'bericht_id' => $message->id,
            'richting' => 'inkomend',
        ]);

        return $message;
    }

    /**
     * @return array{bericht: TicketMessage, ticket: Ticket}
     */
    public function addMessage(Ticket $ticket, User $user, array $data): array
    {
        try {
            return DB::transaction(function () use ($ticket, $user, $data): array {
                $current = $this->current($ticket);
                $duplicate = $current->messages()
                    ->where('client_message_id', $data['client_message_id'])
                    ->first();

                if ($duplicate !== null) {
                    throw $this->duplicateMessageConflict($current, $duplicate);
                }

                $this->ensureMutable($current);
                $this->ensureVersion($current, (int) $data['versie']);

                if ($data['richting'] === 'uitgaand' && $current->behandelaar_id !== $user->id) {
                    throw new TicketConflictException(
                        'not_assignee',
                        $current,
                        'Alleen de behandelaar kan een antwoord toevoegen. Claim het ticket eerst.'
                    );
                }

                $message = $current->messages()->create([
                    'richting' => $data['richting'],
                    'kanaal' => 'handmatig',
                    'auteur_id' => $user->id,
                    'inhoud' => $data['inhoud'],
                    'client_message_id' => $data['client_message_id'],
                ]);

                $newStatus = $current->status;

                if ($data['richting'] === 'inkomend' && $current->status === 'wachten_op_klant') {
                    $newStatus = $current->behandelaar_id === null ? 'nieuw' : 'in_behandeling';
                }

                $updated = Ticket::query()
                    ->whereKey($current->id)
                    ->where('versie', $data['versie'])
                    ->update([
                        'status' => $newStatus,
                        'laatste_bericht_op' => $message->created_at,
                        'versie' => ((int) $data['versie']) + 1,
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    throw $this->versionConflict($this->current($ticket));
                }

                $current = $this->current($ticket);
                $this->activityLogger->log($current, $user, 'bericht_toegevoegd', [
                    'bericht_id' => $message->id,
                    'richting' => $message->richting,
                ]);

                if ($newStatus !== $ticket->status) {
                    $this->activityLogger->log($current, $user, 'status_gewijzigd', [
                        'van' => $ticket->status,
                        'naar' => $newStatus,
                        'automatisch' => true,
                    ]);
                }

                return [
                    'bericht' => $message->fresh(),
                    'ticket' => $this->current($ticket),
                ];
            });
        } catch (QueryException $exception) {
            $duplicate = TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->where('client_message_id', $data['client_message_id'])
                ->first();

            if ($duplicate !== null) {
                throw $this->duplicateMessageConflict($this->current($ticket), $duplicate);
            }

            throw $exception;
        }
    }

    /**
     * @return array{notitie: TicketNote, ticket: Ticket}
     */
    public function addNote(Ticket $ticket, User $user, string $inhoud, int $versie): array
    {
        return DB::transaction(function () use ($ticket, $user, $inhoud, $versie): array {
            $current = $this->current($ticket);

            $this->ensureMutable($current);
            $this->ensureVersion($current, $versie);

            $note = $current->notes()->create([
                'auteur_id' => $user->id,
                'inhoud' => $inhoud,
            ]);

            $updated = Ticket::query()
                ->whereKey($current->id)
                ->where('versie', $versie)
                ->update([
                    'versie' => $versie + 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw $this->versionConflict($this->current($ticket));
            }

            $current = $this->current($ticket);
            $this->activityLogger->log($current, $user, 'notitie_toegevoegd', [
                'notitie_id' => $note->id,
            ]);

            return [
                'notitie' => $note->fresh(),
                'ticket' => $this->current($ticket),
            ];
        });
    }

    private function ensureMutable(Ticket $ticket): void
    {
        if ($ticket->status === 'afgehandeld') {
            throw new TicketConflictException(
                'ticket_afgehandeld',
                $ticket,
                'Heropen het ticket voordat je een bericht of notitie toevoegt.'
            );
        }
    }

    private function ensureVersion(Ticket $ticket, int $versie): void
    {
        if ($ticket->versie !== $versie) {
            throw $this->versionConflict($ticket);
        }
    }

    private function versionConflict(Ticket $ticket): TicketConflictException
    {
        return new TicketConflictException(
            'version_conflict',
            $ticket,
            'Het ticket is intussen gewijzigd. Vernieuw en probeer opnieuw.'
        );
    }

    private function duplicateMessageConflict(
        Ticket $ticket,
        TicketMessage $message,
    ): TicketConflictException {
        return new TicketConflictException(
            'duplicate_message',
            $ticket,
            'Dit bericht is al geplaatst.',
            $message
        );
    }

    private function current(Ticket $ticket): Ticket
    {
        return Ticket::query()->findOrFail($ticket->id);
    }
}
