<?php

namespace Tests\Unit;

use Cron\CronExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class TrunkrsMorningScheduleTest extends TestCase
{
    public function test_cloud_schedule_covers_dutch_mornings_in_summer_and_winter(): void
    {
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/trunkrs-sync.yml');
        $schedule = $workflow['on']['schedule'][0];
        $this->assertSame('Europe/Amsterdam', $schedule['timezone']);
        $cron = new CronExpression($schedule['cron']);

        foreach (['2026-09-25', '2026-12-25'] as $day) {
            $start = new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone($schedule['timezone']));
            $runs = $cron->getMultipleRunDates(20, $start, false, false, $schedule['timezone']);
            $this->assertSame($day.' 06:15', $runs[0]->format('Y-m-d H:i'));
            $this->assertSame($day.' 09:55', $runs[19]->format('Y-m-d H:i'));
            $this->assertSame($day.' 06:25', $runs[1]->format('Y-m-d H:i'));
        }

        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $this->assertSame('read', $workflow['permissions']['contents']);
        $this->assertSame('write', $workflow['permissions']['id-token']);
        $this->assertSame('ubuntu-latest', $workflow['jobs']['request-sync']['runs-on']);
    }
}
