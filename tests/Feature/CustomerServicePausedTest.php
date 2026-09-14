<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCustomerServiceEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerServicePausedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.customer_service' => false]);
    }

    public function test_app_shell_does_not_expose_or_load_customer_service(): void
    {
        $this->get('/')->assertOk();
        $shell = file_get_contents(public_path('index.html'));

        $this->assertStringNotContainsString('customer-service', $shell);
        $this->assertStringNotContainsString('Klantenservice', $shell);
        foreach (['dashboard', 'projects', 'tasks', 'calendar', 'notes', 'converter', 'product-dossiers', 'settings'] as $view) {
            $this->assertStringContainsString('data-view="'.$view.'"', $shell);
            $this->assertStringContainsString('id="view-'.$view.'"', $shell);
        }
    }

    public function test_all_ticket_routes_are_gated(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/customer-service/'));
        $this->assertCount(12, $routes);

        foreach ($routes as $route) {
            $this->assertContains(EnsureCustomerServiceEnabled::class, $route->gatherMiddleware());
        }
    }

    public function test_disabled_endpoints_cannot_read_or_change_tickets_for_any_role(): void
    {
        // Fixtures are created through the retained API in the in-memory test database.
        config(['features.customer_service' => true]);
        $this->actingAsUser();
        $payload = [
            'onderwerp' => 'Bewaard testticket',
            'klant_naam' => 'Test Klant',
            'klant_email' => 'pause@example.test',
            'eerste_bericht' => 'Bewaard testbericht',
        ];
        $id = $this->postJson('/api/customer-service/tickets', $payload)->assertCreated()->json('id');
        $base = '/api/customer-service/tickets/'.$id;
        $this->postJson($base.'/notes', ['inhoud' => 'Bewaarde testnotitie', 'versie' => 1])->assertCreated();
        $before = $this->ticketData();
        config(['features.customer_service' => false]);

        $requests = [
            ['GET', '/api/customer-service/tickets', []],
            ['POST', '/api/customer-service/tickets', $payload],
            ['GET', $base, []],
            ['POST', $base.'/claim', ['versie' => 2]],
            ['POST', $base.'/release', ['versie' => 2]],
            ['PUT', $base.'/status', ['status' => 'afgehandeld', 'versie' => 2]],
            ['PUT', $base.'/priority', ['prioriteit' => 'hoog', 'versie' => 2]],
            ['GET', $base.'/messages', []],
            ['POST', $base.'/messages', ['richting' => 'inkomend', 'inhoud' => 'Niet opslaan', 'versie' => 2]],
            ['GET', $base.'/notes', []],
            ['POST', $base.'/notes', ['inhoud' => 'Niet opslaan', 'versie' => 2]],
            ['GET', $base.'/activities', []],
        ];

        foreach (['admin', 'manager', 'lid'] as $role) {
            $this->actingAsUser(['rol' => $role]);
            foreach ($requests as [$method, $url, $data]) {
                $this->json($method, $url, $data)->assertNotFound()
                    ->assertJsonPath('code', 'customer_service_disabled')
                    ->assertJsonPath('error', 'Klantenservice is voorlopig uitgeschakeld.');
            }
        }

        $this->assertSame($before, $this->ticketData());

        // Pausing does not destroy the ticket, message, note or activity history.
        config(['features.customer_service' => true]);
        $this->getJson($base)->assertOk()->assertJsonPath('onderwerp', 'Bewaard testticket');
        $this->getJson($base.'/messages')->assertOk()->assertJsonPath('0.inhoud', 'Bewaard testbericht');
        $this->getJson($base.'/notes')->assertOk()->assertJsonPath('0.inhoud', 'Bewaarde testnotitie');
    }

    public function test_missing_configuration_keeps_the_api_disabled(): void
    {
        config(['features' => []]);
        $this->actingAsUser();

        $this->getJson('/api/customer-service/tickets')->assertNotFound()
            ->assertJsonPath('code', 'customer_service_disabled');
    }

    public function test_projects_tasks_and_calendar_still_work_when_customer_service_is_disabled(): void
    {
        $this->actingAsUser();
        $project = $this->postJson('/api/projects', ['naam' => 'Testproject pauzeren'])->assertOk()->json('id');
        $task = $this->postJson('/api/tasks', ['titel' => 'Testtaak pauzeren', 'project_id' => $project])->assertOk()->json('id');
        $calendar = $this->postJson('/api/calendar', [
            'titel' => 'Testafspraak pauzeren',
            'datum_start' => '2026-09-11 12:00:00',
            'project_id' => $project,
        ])->assertOk()->json('id');

        $this->getJson('/api/projects')->assertOk()->assertJsonFragment(['id' => $project]);
        $this->getJson('/api/tasks')->assertOk()->assertJsonFragment(['id' => $task]);
        $this->getJson('/api/calendar')->assertOk()->assertJsonFragment(['id' => $calendar]);
        $this->assertDatabaseHas('projects', ['id' => $project, 'naam' => 'Testproject pauzeren']);
        $this->assertDatabaseHas('tasks', ['id' => $task, 'titel' => 'Testtaak pauzeren']);
        $this->assertDatabaseHas('calendar_items', ['id' => $calendar, 'titel' => 'Testafspraak pauzeren']);
    }

    private function ticketData(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing() as $table) {
            if (str_starts_with($table, 'cs_')) {
                $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }
        }

        return $snapshot;
    }
}
