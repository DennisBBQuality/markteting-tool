<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Requests\CustomerService\UpdateTicketStatusRequest;
use App\Models\CustomerService\Ticket;
use App\Services\CustomerService\TicketConflictException;
use App\Services\CustomerService\TicketWorkflowService;
use Illuminate\Http\JsonResponse;

class TicketStatusController extends CustomerServiceController
{
    public function __construct(private readonly TicketWorkflowService $workflowService) {}

    public function update(UpdateTicketStatusRequest $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);

        try {
            $ticket = $this->workflowService->changeStatus(
                $ticket,
                $request->session()->get('userId'),
                $request->string('status')->toString(),
                $request->integer('versie')
            );
        } catch (TicketConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json($this->ticketData($ticket));
    }
}
