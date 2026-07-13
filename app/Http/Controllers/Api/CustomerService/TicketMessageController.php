<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Requests\CustomerService\StoreTicketMessageRequest;
use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketMessage;
use App\Models\User;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketMessageService;
use Illuminate\Http\JsonResponse;

class TicketMessageController extends CustomerServiceController
{
    public function __construct(private readonly TicketMessageService $messageService) {}

    public function index(string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $messages = $ticket->messages()
            ->with('auteur')
            ->get()
            ->map(fn (TicketMessage $message): array => $this->messageData($message));

        return response()->json($messages);
    }

    public function store(StoreTicketMessageRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $user = User::query()->findOrFail($request->session()->get('userId'));

        try {
            $result = $this->messageService->addMessage(
                $ticket,
                $user,
                $request->validated()
            );
        } catch (TicketConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json([
            'bericht' => $this->messageData($result['bericht']),
            'ticket' => $this->ticketData($result['ticket']),
        ], 201);
    }
}
