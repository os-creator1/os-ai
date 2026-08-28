<?php

namespace Tests\Feature\Usage;

use App\Console\Kernel;
use App\Jobs\Usage\ExpireStaleUsageReservations;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §3/§11 —
 * scheduler-reachability + exact-cadence proof for
 * ExpireStaleUsageReservations, mirroring
 * OpportunitySnoozeSweepScheduleTest's own established reflection
 * technique. That existing test identifies its target event via
 * $event->command, which is specific to a $schedule->command(...)
 * registration; every RFC-005 scheduled job (including this one) is
 * registered via $schedule->job(new X()) instead, which — confirmed by
 * direct read of Schedule::job() (vendor/laravel/framework, Illuminate\
 * Console\Scheduling\Schedule::job() calls $event->name($job::class),
 * an alias for ManagesAttributes::description()) — is identified via
 * $event->description, set to the job's own fully-qualified class name.
 */
class UsageJobSchedulingTest extends TestCase
{
    private function registerScheduleAndFindExpireEvent(): ?Event
    {
        $kernel = app(Kernel::class);
        $schedule = app(Schedule::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        foreach ($schedule->events() as $event) {
            if ($event->description === ExpireStaleUsageReservations::class) {
                return $event;
            }
        }

        return null;
    }

    public function test_expire_stale_usage_reservations_is_registered_in_the_schedule(): void
    {
        $event = $this->registerScheduleAndFindExpireEvent();

        $this->assertNotNull($event, 'ExpireStaleUsageReservations must be registered in the schedule.');
    }

    public function test_expire_stale_usage_reservations_runs_every_five_minutes(): void
    {
        $event = $this->registerScheduleAndFindExpireEvent();

        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
