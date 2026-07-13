<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketActivity;
use Illuminate\Http\JsonResponse;

class TicketActivityController extends CustomerServiceController
{
    public function index(string $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $activities = $ticket->activities()
            ->with('gebruiker')
            ->get()
            ->map(fn (TicketActivity $activity): array => $this->activityData($activity));

        return response()->json($activities);
    }
}
