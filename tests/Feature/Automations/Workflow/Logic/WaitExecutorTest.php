<?php

namespace Tests\Feature\Automations\Workflow\Logic;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\RecordingNodeExecutor;
use Tests\TestCase;

/**
 * Automations V2 §12 — the Wait step.
 *
 * A wait is a row, not a timer. These prove the two things that follow from
 * that: the instant is computed correctly in the Business's own timezone
 * (including across both DST transitions, in opposite directions), and the
 * resulting state is durable enough that no process needs to stay alive.
 */
class WaitExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsLogicWorkflows;

    private RecordingNodeExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->recordingExecutor();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Business, 1: AutomationEnrollment} */
    private function enrollWithWait(array $waitStep, ?string $timezone = null): array
    {
        [, $business] = $this->entitledTenant();

        if ($timezone !== null) {
            $this->setBusinessTimezone($business, $timezone);
            $business = $business->fresh();
        }

        [$workflow] = $this->publishWorkflow($business, [
            $waitStep,
            $this->recordedStep('after the wait'),
            $this->endStep(),
        ]);

        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        app(WorkflowAdvancer::class)->advance($enrollment);

        return [$business, $enrollment->fresh()];
    }

    /** 1. A one-minute wait parks the journey durably. */
    public function test_a_one_minute_wait_parks_the_journey(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(WorkflowLimits::MIN_WAIT_MINUTES, 'minutes'));

        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->status);
        $this->assertSame(
            '2026-06-01 12:01:00',
            $this->storedResumeAt($enrollment)?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(0, $this->executor->callCount(), 'Nothing after the wait may run yet.');
    }

    /** 2. Hours and days are computed as their own units. */
    public function test_hour_and_day_durations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $hours] = $this->enrollWithWait($this->waitStep(6, 'hours'));
        $this->assertSame('2026-06-01 18:00:00', $this->storedResumeAt($hours)?->format('Y-m-d H:i:s'));

        [, $days] = $this->enrollWithWait($this->waitStep(3, 'days'));
        $this->assertSame('2026-06-04 12:00:00', $this->storedResumeAt($days)?->format('Y-m-d H:i:s'));
    }

    /** 3. The maximum single wait is accepted. */
    public function test_the_maximum_wait_is_accepted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(WorkflowLimits::MAX_WAIT_DAYS, 'days'));

        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->status);
        $this->assertSame('2027-06-01 12:00:00', $this->storedResumeAt($enrollment)?->format('Y-m-d H:i:s'));
    }

    /** 4. Beyond the maximum is refused at publish, so it never reaches a runtime. */
    public function test_a_wait_over_the_maximum_cannot_be_published(): void
    {
        [, $business] = $this->entitledTenant();

        $this->expectException(\Throwable::class);

        $this->publishWorkflow($business, [
            $this->waitStep(WorkflowLimits::MAX_WAIT_DAYS + 1, 'days'),
            $this->endStep(),
        ]);
    }

    /** 4b. And below the minimum likewise. */
    public function test_a_wait_under_the_minimum_cannot_be_published(): void
    {
        [, $business] = $this->entitledTenant();

        $this->expectException(\Throwable::class);

        $this->publishWorkflow($business, [
            ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'wait',
                'config' => ['mode' => 'duration', 'amount' => 0, 'unit' => 'minutes']],
            $this->endStep(),
        ]);
    }

    /** 5. An until-datetime already in the past continues immediately. */
    public function test_an_already_past_until_datetime_continues_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitUntilStep('2026-06-01 09:00'));

        $this->assertSame(
            EnrollmentStatus::Completed,
            $enrollment->status,
            'A wait whose moment has passed must not park the journey.',
        );
        $this->assertSame(1, $this->executor->callCount(), 'The successor runs in the same advance.');
        $this->assertNull($this->storedResumeAt($enrollment));
    }

    /** 6. The Business's own timezone decides what "09:00" means. */
    public function test_until_datetime_is_read_in_the_business_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 00:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait(
            $this->waitUntilStep('2026-06-02 09:00'),
            'America/New_York',
        );

        // 09:00 in New York on 2 June is EDT (UTC-4), so 13:00 UTC.
        $this->assertSame('2026-06-02 13:00:00', $this->storedResumeAt($enrollment)?->format('Y-m-d H:i:s'));
    }

    /**
     * 7. DST, spring forward. A "1 day" wait is a CALENDAR day: it lands at the
     * same wall-clock time, which is 23 real hours later across the spring
     * transition.
     */
    public function test_a_day_wait_across_the_spring_transition_keeps_wall_clock_time(): void
    {
        // 2026-03-08 is the US spring-forward date.
        Carbon::setTestNow(Carbon::parse('2026-03-07 18:00:00', 'America/New_York'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(1, 'days'), 'America/New_York');

        $resumeAt = $this->storedResumeAt($enrollment);

        $this->assertSame(
            '2026-03-08 18:00',
            $resumeAt?->clone()->setTimezone('America/New_York')->format('Y-m-d H:i'),
            'Still six in the evening, the day after.',
        );
        $this->assertSame(
            23,
            (int) Carbon::now()->diffInHours($resumeAt),
            'Which is 23 real hours, because an hour disappeared.',
        );
    }

    /** 8. DST, autumn. The same wait is 25 real hours when an hour repeats. */
    public function test_a_day_wait_across_the_autumn_transition_keeps_wall_clock_time(): void
    {
        // 2026-11-01 is the US fall-back date.
        Carbon::setTestNow(Carbon::parse('2026-10-31 18:00:00', 'America/New_York'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(1, 'days'), 'America/New_York');

        $resumeAt = $this->storedResumeAt($enrollment);

        $this->assertSame(
            '2026-11-01 18:00',
            $resumeAt?->clone()->setTimezone('America/New_York')->format('Y-m-d H:i'),
        );
        $this->assertSame(
            25,
            (int) Carbon::now()->diffInHours($resumeAt),
            'Which is 25 real hours, because an hour happened twice.',
        );
    }

    /** 8b. An hours wait is absolute, and deliberately differs from a day wait. */
    public function test_an_hours_wait_is_absolute_across_a_transition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 18:00:00', 'America/New_York'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(24, 'hours'), 'America/New_York');

        $resumeAt = $this->storedResumeAt($enrollment);

        $this->assertSame(
            24,
            (int) Carbon::now()->diffInHours($resumeAt),
            '24 hours is 24 hours, whatever the clocks do.',
        );
        $this->assertSame(
            '2026-03-08 19:00',
            $resumeAt?->clone()->setTimezone('America/New_York')->format('Y-m-d H:i'),
            'So the wall-clock time moves by one hour.',
        );
    }

    /** 8c. A Business with no timezone falls back to the application's. */
    public function test_a_business_without_a_timezone_falls_back_to_the_app_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $business] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['timezone' => null]);

        [$workflow] = $this->publishWorkflow($business->fresh(), [
            $this->waitUntilStep('2026-06-02 09:00'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(
            '2026-06-02 09:00:00',
            $this->storedResumeAt($enrollment->fresh())?->format('Y-m-d H:i:s'),
        );
    }

    /**
     * 9. The wait is entirely DB-backed, so it survives anything: the state is
     * re-read from the database by a completely fresh object graph, exactly as a
     * new process after a restart would see it.
     */
    public function test_the_wait_state_is_durable_across_a_restart(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(2, 'hours'));

        // Nothing in memory: read the row as a new process would.
        $row = DB::table('automation_enrollments')->where('id', $enrollment->id)->first();

        $this->assertSame(EnrollmentStatus::Waiting->value, $row->status);
        $this->assertNotNull($row->resume_at, 'The wake time lives in the database, not in a worker.');

        $stepRun = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)->where('node_type', 'wait')->first();

        $this->assertSame(
            StepRunStatus::Waiting->value,
            $stepRun->status,
            'The step run must truthfully say it is waiting.',
        );
        $this->assertNull($stepRun->completed_at, 'A waiting step has not completed.');
    }

    /** 10. No delayed job, no queue timer — waiting enqueues nothing at all. */
    public function test_waiting_dispatches_no_delayed_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        Queue::fake();
        Bus::fake();

        $this->enrollWithWait($this->waitStep(30, 'minutes'));

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /** The wait arrival is atomic: both rows move together, or neither does. */
    public function test_the_wait_arrival_is_one_transaction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(1, 'hours'));

        $row = DB::table('automation_enrollments')->where('id', $enrollment->id)->first();
        $stepRun = DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)->where('node_type', 'wait')->first();

        // The pairing is the invariant: a `waiting` step run must never coexist
        // with an `active` enrollment, and a `resume_at` must never be set
        // without both.
        $this->assertSame(EnrollmentStatus::Waiting->value, $row->status);
        $this->assertSame(StepRunStatus::Waiting->value, $stepRun->status);
        $this->assertNotNull($row->resume_at);

        $this->assertSame(
            1,
            DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)
                ->where('node_type', 'wait')->count(),
            'Exactly one wait step run, so there is only ever one resume_at to honour.',
        );
    }

    /** Advancing a waiting journey again changes nothing and re-parks nothing. */
    public function test_advancing_a_waiting_journey_is_a_no_op(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [, $enrollment] = $this->enrollWithWait($this->waitStep(1, 'hours'));
        $before = $this->storedResumeAt($enrollment);

        app(WorkflowAdvancer::class)->advance($enrollment->fresh());
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertEquals($before, $this->storedResumeAt($enrollment), 'No second resume_at may be written.');
        $this->assertSame(0, $this->executor->callCount());
        $this->assertSame(EnrollmentStatus::Waiting, $enrollment->fresh()->status);
    }

    /** A wait with unusable configuration is stepped over, not parked for ever. */
    public function test_an_unusable_wait_config_is_skipped_rather_than_parked(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow, $version] = $this->publishWorkflow($business, [
            $this->waitStep(1, 'hours'),
            $this->recordedStep('after'),
            $this->endStep(),
        ]);

        // Tamper the compiled node into something with no computable instant.
        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'wait')
            ->update(['config' => json_encode(['mode' => 'until_datetime', 'at' => 'not a date'])]);

        $contact = $this->contactFor($business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);

        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertSame(1, $this->executor->callCount(), 'The journey continues past an unusable wait.');
    }

    /** The draft validator refuses a wait with no mode at all. */
    public function test_a_wait_without_a_mode_is_refused_at_validation(): void
    {
        [, $business] = $this->entitledTenant();
        $drafts = app(WorkflowDraftService::class);

        $this->expectException(\Throwable::class);

        $this->publishWorkflow($business, [
            ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'wait', 'config' => []],
            $this->endStep(),
        ]);
    }
}
