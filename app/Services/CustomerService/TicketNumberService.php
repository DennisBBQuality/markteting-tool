<?php

namespace App\Services\CustomerService;

use Illuminate\Support\Facades\DB;

class TicketNumberService
{
    public function next(?int $jaar = null): string
    {
        $jaar ??= now()->year;

        DB::table('cs_ticket_counters')->insertOrIgnore([
            'jaar' => $jaar,
            'laatste_nummer' => 0,
        ]);

        $counter = DB::table('cs_ticket_counters')
            ->where('jaar', $jaar)
            ->lockForUpdate()
            ->firstOrFail();

        $nummer = $counter->laatste_nummer + 1;

        DB::table('cs_ticket_counters')
            ->where('jaar', $jaar)
            ->update(['laatste_nummer' => $nummer]);

        return sprintf('CS-%d-%05d', $jaar, $nummer);
    }
}
