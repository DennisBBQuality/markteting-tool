<?php

namespace App\Services\CustomerService;

use App\Models\CustomerService\Ticket;
use RuntimeException;

class TicketConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        public readonly Ticket $ticket,
        string $message,
    ) {
        parent::__construct($message);
    }
}
