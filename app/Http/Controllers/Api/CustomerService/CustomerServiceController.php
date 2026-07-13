<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Controllers\Controller;
use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketMessage;
use App\Models\User;
use App\Services\CustomerService\TicketConflictException;
use Illuminate\Http\JsonResponse;

abstract class CustomerServiceController extends Controller
{
    protected function conflictResponse(TicketConflictException $exception): JsonResponse
    {
        $data = [
            'error' => $exception->getMessage(),
            'code' => $exception->machineCode,
            'ticket' => $this->ticketData($exception->ticket),
        ];

        if ($exception->existingMessage !== null) {
            $data['bericht'] = $this->messageData($exception->existingMessage);
        }

        return response()->json($data, 409);
    }

    protected function ticketData(Ticket $ticket): array
    {
        $ticket->loadMissing(['behandelaar', 'aangemaaktDoor']);

        return [
            'id' => $ticket->id,
            'ticketnummer' => $ticket->ticketnummer,
            'onderwerp' => $ticket->onderwerp,
            'klant_naam' => $ticket->klant_naam,
            'klant_email' => $ticket->klant_email,
            'status' => $ticket->status,
            'prioriteit' => $ticket->prioriteit,
            'behandelaar' => $this->userData($ticket->behandelaar),
            'aangemaakt_door' => $this->userData($ticket->aangemaaktDoor),
            'versie' => $ticket->versie,
            'laatste_bericht_op' => $ticket->laatste_bericht_op,
            'afgehandeld_op' => $ticket->afgehandeld_op,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }

    protected function userData(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'naam' => $user->naam,
            'kleur' => $user->kleur,
        ];
    }

    protected function messageData(TicketMessage $message): array
    {
        $message->loadMissing('auteur');

        return [
            'id' => $message->id,
            'ticket_id' => $message->ticket_id,
            'richting' => $message->richting,
            'kanaal' => $message->kanaal,
            'auteur' => $this->userData($message->auteur),
            'inhoud' => $message->inhoud,
            'created_at' => $message->created_at,
        ];
    }
}
