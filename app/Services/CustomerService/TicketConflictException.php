<?php

namespace App\Services\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketMessage;
use RuntimeException;

class TicketConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        public readonly Ticket $ticket,
        string $message,
        public readonly ?TicketMessage $existingMessage = null,
    ) {
        parent::__construct($message);
    }
}
