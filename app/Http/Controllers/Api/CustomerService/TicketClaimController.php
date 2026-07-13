<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Requests\CustomerService\TicketVersionRequest;
use App\Models\CustomerService\Ticket;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketWorkflowService;
use Illuminate\Http\JsonResponse;

class TicketClaimController extends CustomerServiceController
{
    public function __construct(private readonly TicketWorkflowService $workflowService) {}

    public function claim(TicketVersionRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);

        try {
            $ticket = $this->workflowService->claim(
                $ticket,
                $request->session()->get('userId'),
                $request->integer('versie')
            );
        } catch (TicketConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json($this->ticketData($ticket));
    }

    public function release(TicketVersionRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);

        try {
            $ticket = $this->workflowService->release(
                $ticket,
                $request->session()->get('userId'),
                $request->integer('versie')
            );
        } catch (TicketConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json($this->ticketData($ticket));
    }
}
