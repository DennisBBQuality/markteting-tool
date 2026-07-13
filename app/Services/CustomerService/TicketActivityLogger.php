<?php

namespace App\Services\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketActivity;
use App\Models\User;

class TicketActivityLogger
{
    public function log(
        Ticket $ticket,
        User|string|null $gebruiker,
        string $actie,
        ?array $details = null,
    ): TicketActivity {
        return $ticket->activities()->create([
            'gebruiker_id' => $gebruiker instanceof User ? $gebruiker->id : $gebruiker,
            'actie' => $actie,
            'details' => $details,
        ]);
    }
}
