<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Requests\CustomerService\StoreTicketNoteRequest;
use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketNote;
use App\Models\User;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketMessageService;
use Illuminate\Http\JsonResponse;

class TicketNoteController extends CustomerServiceController
{
    public function __construct(private readonly TicketMessageService $messageService) {}

    public function index(string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $notes = $ticket->notes()
            ->with('auteur')
            ->get()
            ->map(fn (TicketNote $note): array => $this->noteData($note));

        return response()->json($notes);
    }

    public function store(StoreTicketNoteRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $user = User::query()->findOrFail($request->session()->get('userId'));

        try {
            $result = $this->messageService->addNote(
                $ticket,
                $user,
                $request->string('inhoud')->toString(),
                $request->integer('versie')
            );
        } catch (TicketConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json([
            'notitie' => $this->noteData($result['notitie']),
            'ticket' => $this->ticketData($result['ticket']),
        ], 201);
    }
}
