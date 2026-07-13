<?php

namespace Tests\Unit\CustomerService;

use App\Services\CustomerService\TicketNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_of_a_year_starts_at_one_with_leading_zeroes(): void
    {
        $number = DB::transaction(
            fn (): string => app(TicketNumberService::class)->next(2026)
        );

        $this->assertSame('CS-2026-00001', $number);
        $this->assertDatabaseHas('cs_ticket_counters', [
            'jaar' => 2026,
            'laatste_nummer' => 1,
        ]);
    }

    public function test_successive_numbers_increment_within_the_same_year(): void
    {
        $service = app(TicketNumberService::class);

        $first = DB::transaction(fn (): string => $service->next(2026));
        $second = DB::transaction(fn (): string => $service->next(2026));
        $third = DB::transaction(fn (): string => $service->next(2026));

        $this->assertSame('CS-2026-00001', $first);
        $this->assertSame('CS-2026-00002', $second);
        $this->assertSame('CS-2026-00003', $third);
    }

    public function test_a_new_year_uses_an_independent_sequence(): void
    {
        $service = app(TicketNumberService::class);

        DB::transaction(fn (): string => $service->next(2026));
        DB::transaction(fn (): string => $service->next(2026));
        $nextYear = DB::transaction(fn (): string => $service->next(2027));

        $this->assertSame('CS-2027-00001', $nextYear);
        $this->assertDatabaseHas('cs_ticket_counters', [
            'jaar' => 2026,
            'laatste_nummer' => 2,
        ]);
        $this->assertDatabaseHas('cs_ticket_counters', [
            'jaar' => 2027,
            'laatste_nummer' => 1,
        ]);
    }

    public function test_current_year_is_used_when_no_year_is_supplied(): void
    {
        $number = DB::transaction(
            fn (): string => app(TicketNumberService::class)->next()
        );

        $this->assertSame('CS-'.now()->year.'-00001', $number);
    }
}
