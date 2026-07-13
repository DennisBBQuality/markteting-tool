<?php

namespace Tests\Feature\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use Database\Seeders\CustomerServiceTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_realistic_consistent_local_test_tickets(): void
    {
        User::factory()->count(3)->create();

        $this->seed(CustomerServiceTestSeeder::class);

        $tickets = Ticket::query()
            ->with(['messages', 'notes', 'activities'])
            ->get();

        $this->assertCount(16, $tickets);
        $this->assertEqualsCanonicalizing([
            'nieuw',
            'in_behandeling',
            'wachten_op_klant',
            'afgehandeld',
        ], $tickets->pluck('status')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([
            'laag',
            'normaal',
            'hoog',
            'urgent',
        ], $tickets->pluck('prioriteit')->unique()->values()->all());
        $this->assertSame(16, $tickets->pluck('ticketnummer')->unique()->count());

        foreach ($tickets as $ticket) {
            $this->assertMatchesRegularExpression('/^CS-\d{4}-\d{5,}$/', $ticket->ticketnummer);
            $this->assertStringEndsWith('@example.test', $ticket->klant_email);
            $this->assertGreaterThanOrEqual(5, $ticket->versie);
            $this->assertGreaterThanOrEqual(3, $ticket->messages->count());
            $this->assertContains('inkomend', $ticket->messages->pluck('richting')->all());
            $this->assertContains('uitgaand', $ticket->messages->pluck('richting')->all());
            $this->assertGreaterThanOrEqual(1, $ticket->notes->count());
            $this->assertGreaterThanOrEqual(6, $ticket->activities->count());
            $this->assertNotNull($ticket->laatste_bericht_op);
        }
    }

    public function test_seeder_is_idempotent_and_is_not_part_of_default_seeder(): void
    {
        User::factory()->count(2)->create();

        $this->seed(CustomerServiceTestSeeder::class);
        $firstNumbers = Ticket::query()->pluck('ticketnummer');
        $this->seed(CustomerServiceTestSeeder::class);

        $this->assertDatabaseCount('cs_tickets', 16);
        $this->assertSame(16, Ticket::query()->distinct()->count('ticketnummer'));
        $this->assertCount(16, $firstNumbers);
        $this->assertStringNotContainsString(
            'CustomerServiceTestSeeder',
            file_get_contents(database_path('seeders/DatabaseSeeder.php'))
        );
    }
}
