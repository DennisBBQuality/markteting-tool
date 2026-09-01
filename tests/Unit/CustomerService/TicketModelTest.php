<?php

namespace Tests\Unit\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_and_rolls_back_both_tables(): void
    {
        $this->assertTrue(Schema::hasTable('cs_ticket_counters'));
        $this->assertTrue(Schema::hasTable('cs_tickets'));

        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('cs_tickets'));
        $this->assertFalse(Schema::hasTable('cs_ticket_counters'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('cs_ticket_counters'));
        $this->assertTrue(Schema::hasTable('cs_tickets'));
    }

    public function test_migration_defines_only_the_specified_columns_and_indexes(): void
    {
        $this->assertSame([
            'jaar',
            'laatste_nummer',
        ], Schema::getColumnListing('cs_ticket_counters'));

        $this->assertSame([
            'id',
            'ticketnummer',
            'onderwerp',
            'klant_naam',
            'klant_email',
            'status',
            'prioriteit',
            'behandelaar_id',
            'aangemaakt_door',
            'versie',
            'laatste_bericht_op',
            'afgehandeld_op',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('cs_tickets'));

        $this->assertTrue(Schema::hasIndex('cs_ticket_counters', ['jaar'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['ticketnummer'], 'unique'));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['status']));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['prioriteit']));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['behandelaar_id']));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['laatste_bericht_op']));
        $this->assertTrue(Schema::hasIndex('cs_tickets', ['created_at']));
    }

    public function test_ticket_uses_a_uuid_primary_key_and_database_defaults(): void
    {
        $ticket = Ticket::query()->create([
            'ticketnummer' => 'CS-2026-00001',
            'onderwerp' => 'Vraag over een campagne',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'klant@example.test',
        ]);

        $this->assertTrue(Str::isUuid($ticket->id));
        $this->assertSame('id', $ticket->getKeyName());
        $this->assertSame('string', $ticket->getKeyType());
        $this->assertFalse($ticket->getIncrementing());

        $ticket->refresh();

        $this->assertSame('nieuw', $ticket->status);
        $this->assertSame('normaal', $ticket->prioriteit);
        $this->assertSame(1, $ticket->versie);
        $this->assertNull($ticket->behandelaar_id);
        $this->assertNull($ticket->aangemaakt_door);
        $this->assertNull($ticket->laatste_bericht_op);
        $this->assertNull($ticket->afgehandeld_op);

        DB::table('cs_ticket_counters')->insert(['jaar' => 2026]);

        $this->assertSame(0, DB::table('cs_ticket_counters')->value('laatste_nummer'));
    }

    public function test_ticket_model_configuration_matches_the_specification(): void
    {
        $ticket = new Ticket;

        $this->assertSame('cs_tickets', $ticket->getTable());
        $this->assertSame([
            'ticketnummer',
            'onderwerp',
            'klant_naam',
            'klant_email',
            'status',
            'prioriteit',
            'behandelaar_id',
            'aangemaakt_door',
            'versie',
            'laatste_bericht_op',
            'afgehandeld_op',
        ], $ticket->getFillable());
        $this->assertSame('datetime', $ticket->getCasts()['laatste_bericht_op']);
        $this->assertSame('datetime', $ticket->getCasts()['afgehandeld_op']);
        $this->assertSame('integer', $ticket->getCasts()['versie']);
    }

    public function test_ticket_relations_resolve_users_and_foreign_keys_set_null_on_delete(): void
    {
        $behandelaar = User::factory()->create();
        $aangemaaktDoor = User::factory()->create();
        $ticket = Ticket::query()->create([
            'ticketnummer' => 'CS-2026-00002',
            'onderwerp' => 'Relatietest',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'relatie@example.test',
            'behandelaar_id' => $behandelaar->id,
            'aangemaakt_door' => $aangemaaktDoor->id,
        ]);

        $this->assertTrue($ticket->behandelaar->is($behandelaar));
        $this->assertTrue($ticket->aangemaaktDoor->is($aangemaaktDoor));

        $behandelaar->delete();
        $aangemaaktDoor->delete();
        $ticket->refresh();

        $this->assertNull($ticket->behandelaar_id);
        $this->assertNull($ticket->aangemaakt_door);
    }

    public function test_ticket_number_must_be_unique(): void
    {
        Ticket::query()->create([
            'ticketnummer' => 'CS-2026-00003',
            'onderwerp' => 'Eerste ticket',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'eerste@example.test',
        ]);

        $this->expectException(QueryException::class);

        Ticket::query()->create([
            'ticketnummer' => 'CS-2026-00003',
            'onderwerp' => 'Dubbel ticket',
            'klant_naam' => 'Andere Test Klant',
            'klant_email' => 'dubbel@example.test',
        ]);
    }
}
