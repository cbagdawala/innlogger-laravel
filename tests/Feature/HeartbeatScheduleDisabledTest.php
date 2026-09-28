<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;

final class HeartbeatScheduleDisabledTest extends TestCase
{
    protected function innLoggerConfig(): array
    {
        return ['heartbeat' => ['schedule' => false]];
    }

    public function test_nothing_is_scheduled_when_the_heartbeat_schedule_is_off(): void
    {
        $this->assertSame([], HeartbeatScheduleTest::heartbeatEvents($this->app->make(Schedule::class)));
    }
}
