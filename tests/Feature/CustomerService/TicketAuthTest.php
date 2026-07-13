<?php

namespace Tests\Feature\CustomerService;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_endpoints_require_an_authenticated_session(): void
    {
        $this->getJson('/api/customer-service/tickets')->assertUnauthorized();
        $this->postJson('/api/customer-service/tickets')->assertUnauthorized();
        $this->getJson('/api/customer-service/tickets/'.Str::uuid())->assertUnauthorized();
        $this->postJson('/api/customer-service/tickets/'.Str::uuid().'/claim')->assertUnauthorized();
        $this->postJson('/api/customer-service/tickets/'.Str::uuid().'/release')->assertUnauthorized();
        $this->putJson('/api/customer-service/tickets/'.Str::uuid().'/status')->assertUnauthorized();
        $this->putJson('/api/customer-service/tickets/'.Str::uuid().'/priority')->assertUnauthorized();
        $this->getJson('/api/customer-service/tickets/'.Str::uuid().'/messages')->assertUnauthorized();
        $this->postJson('/api/customer-service/tickets/'.Str::uuid().'/messages')->assertUnauthorized();
        $this->getJson('/api/customer-service/tickets/'.Str::uuid().'/notes')->assertUnauthorized();
        $this->postJson('/api/customer-service/tickets/'.Str::uuid().'/notes')->assertUnauthorized();
        $this->getJson('/api/customer-service/tickets/'.Str::uuid().'/activities')->assertUnauthorized();
    }
}
