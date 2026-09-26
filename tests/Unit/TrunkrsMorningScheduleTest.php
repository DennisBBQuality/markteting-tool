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
        foreach (['2026-09-26', '2026-12-26'] as $day) {
            foreach (['06:17', '06:27', '09:57', '12:17', '21:17'] as $time) {
                $instant = new \DateTimeImmutable($day.' '.$time, new \DateTimeZone('Europe/Amsterdam'));
                $covered = false;
                foreach ($workflow['on']['schedule'] as $schedule) {
                    $this->assertArrayNotHasKey('timezone', $schedule);
                    $covered = $covered || (new CronExpression($schedule['cron']))->isDue($instant, 'UTC');
                }
                $this->assertTrue($covered, 'Missing cloud retry at '.$day.' '.$time);
            }
        }

        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $this->assertSame('read', $workflow['permissions']['contents']);
        $this->assertSame('write', $workflow['permissions']['id-token']);
        $this->assertSame('ubuntu-latest', $workflow['jobs']['request-sync']['runs-on']);
        $this->assertSame('python3 scripts/trunkrs-cloud-check.py', $workflow['jobs']['request-sync']['steps'][1]['run']);
    }
}
