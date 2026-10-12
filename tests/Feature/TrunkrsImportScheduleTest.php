<?php

namespace Tests\Feature;

use App\Services\Trunkrs\TrunkrsSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrunkrsImportScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_schedule_preserves_retry_times_but_excludes_sunday_and_monday(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'trunkrs:sync'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('15,25,35,45,55 6-9 * * 2-6', $event->expression);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        foreach (['2026-10-06', '2026-12-22', '2026-03-24', '2026-10-20'] as $tuesday) {
            for ($offset = 0; $offset < 7; $offset++) {
                foreach (['05:55', '06:15', '06:25', '06:35', '06:45', '06:55', '09:55', '10:15'] as $time) {
                    $instant = CarbonImmutable::parse($tuesday.' '.$time, 'Europe/Amsterdam')->addDays($offset);
                    $this->travelTo($instant->utc());
                    $expected = $offset < 5 && $time !== '05:55' && $time !== '10:15';
                    $this->assertSame($expected, $event->isDue($this->app), $instant->toIso8601String());
                }
            }
        }
    }

    public function test_manual_command_remains_available_on_sunday_and_monday(): void
    {
        $this->mock(TrunkrsSync::class, fn ($mock) => $mock->shouldReceive('run')->twice()->andReturn('ok'));
        foreach (['2026-10-11', '2026-10-12'] as $day) {
            $this->travelTo(CarbonImmutable::parse($day.'T08:00:00Z'));
            $this->artisan('trunkrs:sync')->expectsOutput('Trunkrs-rapportmap gecontroleerd.')->assertSuccessful();
        }
    }
}
