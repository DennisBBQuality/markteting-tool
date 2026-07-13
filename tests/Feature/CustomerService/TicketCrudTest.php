<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_ticket_with_first_message_and_activities(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/customer-service/tickets', [
            'onderwerp' => 'Vraag over levering picanha',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'testklant@example.test',
            'prioriteit' => 'hoog',
            'eerste_bericht' => 'Wanneer wordt mijn bestelling geleverd?',
        ]);

        $response->assertCreated()
            ->assertJsonPath('onderwerp', 'Vraag over levering picanha')
            ->assertJsonPath('klant_naam', 'Test Klant')
            ->assertJsonPath('status', 'nieuw')
            ->assertJsonPath('prioriteit', 'hoog')
            ->assertJsonPath('behandelaar', null)
            ->assertJsonPath('aangemaakt_door.id', $user->id)
            ->assertJsonPath('versie', 1)
            ->assertJsonStructure([
                'id',
                'ticketnummer',
                'laatste_bericht_op',
                'created_at',
                'updated_at',
            ]);

        $ticket = Ticket::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^CS-\d{4}-\d{5}$/', $ticket->ticketnummer);
        $this->assertNotNull($ticket->laatste_bericht_op);
        $this->assertDatabaseHas('cs_ticket_messages', [
            'ticket_id' => $ticket->id,
            'richting' => 'inkomend',
            'kanaal' => 'handmatig',
            'auteur_id' => $user->id,
            'inhoud' => 'Wanneer wordt mijn bestelling geleverd?',
        ]);
        $this->assertDatabaseCount('cs_ticket_activities', 2);
        $this->assertEqualsCanonicalizing([
            'ticket_aangemaakt',
            'bericht_toegevoegd',
        ], $ticket->activities()->pluck('actie')->all());
    }

    public function test_ticket_detail_returns_expected_user_snapshots(): void
    {
        $user = $this->actingAsUser();
        $createResponse = $this->postJson('/api/customer-service/tickets', [
            'onderwerp' => 'Detailtest',
            'klant_naam' => 'Detail Klant',
            'klant_email' => 'detail@example.test',
            'eerste_bericht' => 'Eerste bericht',
        ])->assertCreated();

        $this->getJson('/api/customer-service/tickets/'.$createResponse->json('id'))
            ->assertOk()
            ->assertJsonPath('ticketnummer', $createResponse->json('ticketnummer'))
            ->assertJsonPath('aangemaakt_door.id', $user->id)
            ->assertJsonPath('aangemaakt_door.naam', $user->naam)
            ->assertJsonPath('behandelaar', null);
    }

    public function test_unknown_ticket_returns_not_found(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/customer-service/tickets/'.Str::uuid())->assertNotFound();
    }

    public function test_ticket_creation_validates_all_fields(): void
    {
        $this->actingAsUser();
        $valid = [
            'onderwerp' => 'Validatie',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'validatie@example.test',
            'prioriteit' => 'normaal',
            'eerste_bericht' => 'Testbericht',
        ];

        foreach (['onderwerp', 'klant_naam', 'klant_email', 'eerste_bericht'] as $field) {
            $payload = $valid;
            unset($payload[$field]);

            $this->postJson('/api/customer-service/tickets', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/customer-service/tickets', [
            ...$valid,
            'klant_email' => 'geen-email',
        ])->assertUnprocessable()->assertJsonValidationErrors('klant_email');

        $this->postJson('/api/customer-service/tickets', [
            ...$valid,
            'prioriteit' => 'kritiek',
        ])->assertUnprocessable()->assertJsonValidationErrors('prioriteit');
    }
}
