<?php

namespace Tests\Feature\Opportunity;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Kernel::schedule() stays protected — production visibility is not
 * relaxed for testing. This invokes it via reflection against a fresh
 * Schedule instance, matching the exact call shape Laravel's own scheduler
 * bootstrap uses, then inspects the registered Event objects directly.
 */
class OpportunitySnoozeSweepScheduleTest extends TestCase
{
    private function registerScheduleAndFindSweepEvent(): ?Event
    {
        $kernel = app(Kernel::class);
        $schedule = app(Schedule::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command, 'opportunity:sweep-expired-snoozes')) {
                return $event;
            }
        }

        return null;
    }

    public function test_the_command_is_registered_in_the_schedule(): void
    {
        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertNotNull($event);
    }

    public function test_default_cadence_produces_every_15_minutes(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 15);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_runtime_override_of_5_produces_every_5_minutes(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 5);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_digit_only_string_override_is_accepted(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', '10');

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/10 * * * *', $event->expression);
    }

    public function test_zero_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 0);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_60_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 60);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_negative_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', -5);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_decimal_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 5.5);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_alphabetic_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', 'abc');

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_null_falls_back_to_15(): void
    {
        config()->set('opportunity.snooze_sweep_minutes', null);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_scheduler_registration_remains_present_when_disabled(): void
    {
        config()->set('opportunity.enabled', false);

        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertNotNull($event);
    }

    public function test_no_overlapping_or_unrelated_scheduler_options_are_added(): void
    {
        $event = $this->registerScheduleAndFindSweepEvent();

        $this->assertFalse($event->withoutOverlapping);
        $this->assertFalse($event->runInBackground);
    }
}
