<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

final class HeartbeatScheduleTest extends TestCase
{
    protected function innLoggerConfig(): array
    {
        return ['heartbeat' => ['schedule' => true]];
    }

    public function test_heartbeat_is_scheduled_every_five_minutes_in_the_foreground(): void
    {
        $events = self::heartbeatEvents($this->app->make(Schedule::class));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        // A background run releases its mutex only via `schedule:finish`; a killed child
        // would leave the mutex in place and stop heartbeats.
        $this->assertFalse($event->runInBackground);
    }

    /**
     * @return list<Event>
     */
    public static function heartbeatEvents(Schedule $schedule): array
    {
        return array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'innlogger:heartbeat'),
        ));
    }
}
