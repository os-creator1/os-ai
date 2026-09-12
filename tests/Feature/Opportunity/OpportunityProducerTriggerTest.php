<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer;
use App\Library\Opportunity\OpportunityProducerTrigger;
use App\Listeners\Opportunity\TriggerBusinessAdvisorProducer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * COO C-1 — the event-driven automatic producer trigger: contract §7.3
 * proofs T-C1-1 (burst debounce), T-C1-3 (active and failed runs) and the
 * event half of T-C1-4 (disabled/enabled truth).
 *
 * No test sleeps. The debounce window is proved with a controlled clock, and
 * the concurrency claim with the same atomic statement two racing processes
 * would each execute.
 */
class OpportunityProducerTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private function enableEngine(): void
    {
        config(['opportunity.enabled' => true]);
    }

    private function trigger(): OpportunityProducerTrigger
    {
        // Resolved fresh, so no state can be carried between calls in memory.
        return app(OpportunityProducerTrigger::class);
    }

    /**
     * The fixture creates a DRAFT Business (EloquentBusinessRepository forces
     * that on creation), and the trigger deliberately produces only for active
     * ones — a draft is not reachable in the customer shell, so a
     * recommendation for it would be invisible.
     */
    private function activeBusinessForOpportunities(): \App\Models\Business
    {
        $business = $this->createBusinessForOpportunities();

        DB::table('businesses')->where('id', $business->id)->update([
            'status' => BusinessStatus::Active->value,
            'activated_at' => now(),
        ]);

        return $business->fresh();
    }

    private function dispatchRowFor(int $businessId): ?object
    {
        return DB::table('opportunity_producer_dispatches')
            ->where('business_id', $businessId)
            ->where('worker_key', OpportunityWorkerKey::BusinessAdvisor->value)
            ->first();
    }

    // -----------------------------------------------------------------
    // T-C1-1 — a burst produces one run per debounce window
    // -----------------------------------------------------------------

    public function test_t_c1_1_a_burst_of_relevant_edits_dispatches_one_producer_run_per_window(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $start = CarbonImmutable::parse('2026-09-16 09:00:00');

        // Eight edits inside one 15-minute window, the way a customer filling
        // in their profile actually saves.
        $dispatched = 0;

        foreach (range(0, 7) as $minute) {
            if ($this->trigger()->triggerFromChange($business->id, $start->addMinutes($minute))) {
                $dispatched++;
            }
        }

        $this->assertSame(1, $dispatched, 'A burst inside one window may win the claim exactly once.');
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);

        // The next window is a new claim, so the trigger is not permanently
        // spent.
        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $start->addMinutes(16)));
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 2);
    }

    public function test_t_c1_1_the_debounce_window_is_the_configured_length(): void
    {
        Queue::fake();
        $this->enableEngine();
        config(['opportunity.trigger_debounce_minutes' => 60]);
        $business = $this->activeBusinessForOpportunities();
        $start = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $start));

        // Inside the hour: refused, whatever the minute.
        $this->assertFalse($this->trigger()->triggerFromChange($business->id, $start->addMinutes(59)));

        // Immediately after it: allowed again.
        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $start->addMinutes(61)));
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 2);
    }

    public function test_t_c1_1_the_claim_is_atomic_so_two_workers_cannot_both_win(): void
    {
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');
        $windowStart = $now->subMinutes(15);

        // The exact statement two concurrent processes each run. Separate
        // repository instances: the arbiter is the row, never object state.
        $first = app(OpportunityProducerDispatchRepository::class)
            ->claimDebounceWindow($business->id, OpportunityWorkerKey::BusinessAdvisor, $windowStart, $now);
        $second = app(OpportunityProducerDispatchRepository::class)
            ->claimDebounceWindow($business->id, OpportunityWorkerKey::BusinessAdvisor, $windowStart, $now);

        $this->assertTrue($first);
        $this->assertFalse($second, 'Exactly one caller may hold a window.');
        $this->assertSame(1, DB::table('opportunity_producer_dispatches')->count(), 'The racing insert produces one row.');
    }

    public function test_t_c1_1_two_businesses_do_not_share_a_window(): void
    {
        Queue::fake();
        $this->enableEngine();
        $one = $this->activeBusinessForOpportunities();
        $two = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->assertTrue($this->trigger()->triggerFromChange($one->id, $now));
        $this->assertTrue($this->trigger()->triggerFromChange($two->id, $now), 'Debounce is per Business, never global.');

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 2);
        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $one->id,
        );
        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $two->id,
        );
    }

    // -----------------------------------------------------------------
    // The events, and only the material ones
    // -----------------------------------------------------------------

    public function test_each_canonical_business_event_reaches_the_trigger(): void
    {
        Queue::fake();
        $this->enableEngine();
        $listener = app(TriggerBusinessAdvisorProducer::class);

        // One Business per event, so each assertion proves the WIRING rather
        // than tripping over the shared debounce window.
        $updated = $this->activeBusinessForOpportunities();
        $located = $this->activeBusinessForOpportunities();
        $serviced = $this->activeBusinessForOpportunities();
        $onboarded = $this->activeBusinessForOpportunities();

        $listener->handleBusinessUpdated(new BusinessUpdated($updated->id, ['phone']));
        $listener->handleBusinessPrimaryLocationUpdated(new BusinessPrimaryLocationUpdated($located->id, 1));
        $listener->handleBusinessServicesSynced(new BusinessServicesSynced($serviced->id, [1, 2]));

        $onboarding = CustomerOnboarding::create([
            'customer_id' => $onboarded->customer->user_id,
            'business_id' => $onboarded->id,
            'is_required' => true,
        ]);
        $listener->handleCustomerOnboardingCompleted(new CustomerOnboardingCompleted($onboarding->id, $onboarded->customer->user_id));

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 4);

        foreach ([$updated, $located, $serviced, $onboarded] as $business) {
            Queue::assertPushed(
                RunBusinessAdvisorOpportunityProducer::class,
                fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $business->id,
            );
        }
    }

    public function test_a_zero_debounce_window_falls_back_to_the_default_rather_than_disabling_it(): void
    {
        Queue::fake();
        $this->enableEngine();
        config(['opportunity.trigger_debounce_minutes' => 0]);
        $business = $this->activeBusinessForOpportunities();
        $start = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $start));
        $this->assertFalse(
            $this->trigger()->triggerFromChange($business->id, $start->addMinutes(5)),
            'A misconfigured window must not become no window at all.'
        );

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
    }

    public function test_the_four_events_are_registered_for_the_listener(): void
    {
        foreach ([
            BusinessUpdated::class,
            BusinessPrimaryLocationUpdated::class,
            BusinessServicesSynced::class,
            CustomerOnboardingCompleted::class,
        ] as $event) {
            $this->assertTrue(
                Event::hasListeners($event),
                "{$event} must be wired, or the automatic path depends on a manual dispatch."
            );
        }
    }

    public function test_an_edit_the_advisor_cannot_read_triggers_nothing(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();

        app(TriggerBusinessAdvisorProducer::class)
            ->handleBusinessUpdated(new BusinessUpdated($business->id, ['timezone', 'currency_code', 'name']));

        Queue::assertNothingPushed();
        $this->assertNull($this->dispatchRowFor($business->id), 'An immaterial edit writes no coordination state either.');
    }

    public function test_an_onboarding_without_a_business_triggers_nothing(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();

        $onboarding = CustomerOnboarding::create([
            'customer_id' => $business->customer->user_id,
            'business_id' => null,
            'is_required' => true,
        ]);

        app(TriggerBusinessAdvisorProducer::class)
            ->handleCustomerOnboardingCompleted(new CustomerOnboardingCompleted($onboarding->id, $business->customer->user_id));

        Queue::assertNothingPushed();
    }

    public function test_an_inactive_business_is_never_triggered(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->assertFalse($this->trigger()->triggerFromChange($business->id));
        Queue::assertNothingPushed();
    }

    public function test_a_deleted_business_is_never_triggered(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $missingId = $business->id + 9_999;

        $this->assertFalse($this->trigger()->triggerFromChange($missingId));
        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // T-C1-3 — a healthy run coalesces; a failed one does not block
    // -----------------------------------------------------------------

    public function test_t_c1_3_a_healthy_active_run_coalesces_the_trigger(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Running->value,
            'started_at' => $now->subMinutes(2),
            'heartbeat_at' => $now->subMinutes(1),
        ]);

        $this->assertFalse($this->trigger()->triggerFromChange($business->id, $now));
        Queue::assertNothingPushed();

        // And the refusal did not burn the window: once that run is finished,
        // the next real change still gets a prompt run.
        $this->assertNull($this->dispatchRowFor($business->id)?->last_dispatched_at);
    }

    public function test_t_c1_3_a_run_whose_heartbeat_died_does_not_coalesce_the_trigger(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');

        // Still marked running, but its heartbeat is older than the timeout:
        // beginRun() treats it as abandoned, so the trigger must not defer to
        // it (the same §24.2 cutoff, read without the lock).
        $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Running->value,
            'started_at' => $now->subHours(4),
            'heartbeat_at' => $now->subHours(4),
        ]);

        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $now));
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
    }

    public function test_t_c1_3_a_failed_run_never_blocks_the_next_legitimate_trigger(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Failed->value,
            'started_at' => $now->subMinutes(5),
            'heartbeat_at' => $now->subMinutes(5),
            'completed_at' => $now->subMinutes(4),
            'safe_error_summary' => 'We could not refresh your opportunities right now. Please try again later.',
        ]);

        $this->assertTrue($this->trigger()->triggerFromChange($business->id, $now));
        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
    }

    public function test_t_c1_3_a_healthy_run_for_another_business_does_not_coalesce_this_one(): void
    {
        Queue::fake();
        $this->enableEngine();
        $busy = $this->activeBusinessForOpportunities();
        $quiet = $this->activeBusinessForOpportunities();
        $now = CarbonImmutable::parse('2026-09-16 09:00:00');

        $this->createOpportunityRun($busy, [
            'status' => OpportunityRunStatus::Running->value,
            'started_at' => $now->subMinute(),
            'heartbeat_at' => $now,
        ]);

        $this->assertFalse($this->trigger()->triggerFromChange($busy->id, $now));
        $this->assertTrue($this->trigger()->triggerFromChange($quiet->id, $now));

        Queue::assertPushed(RunBusinessAdvisorOpportunityProducer::class, 1);
        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $quiet->id,
        );
    }

    // -----------------------------------------------------------------
    // T-C1-4 — the event path, disabled and enabled
    // -----------------------------------------------------------------

    public function test_t_c1_4_with_the_engine_disabled_the_event_path_writes_and_queues_nothing(): void
    {
        Queue::fake();
        config(['opportunity.enabled' => false]);
        $business = $this->activeBusinessForOpportunities();

        $listener = app(TriggerBusinessAdvisorProducer::class);

        foreach (range(1, 3) as $ignored) {
            $listener->handleBusinessUpdated(new BusinessUpdated($business->id, ['phone', 'email']));
            $listener->handleBusinessPrimaryLocationUpdated(new BusinessPrimaryLocationUpdated($business->id, 1));
            $listener->handleBusinessServicesSynced(new BusinessServicesSynced($business->id, [1]));
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('opportunity_runs')->count(), 'No run may exist.');
        $this->assertSame(0, DB::table('opportunities')->count(), 'No opportunity may exist.');
        $this->assertSame(
            0,
            DB::table('opportunity_producer_dispatches')->count(),
            'Not even coordination state may be written merely because a trigger fired.'
        );
    }

    public function test_t_c1_4_with_the_engine_enabled_the_event_path_populates_the_queue_with_no_manual_step(): void
    {
        Queue::fake();
        $this->enableEngine();
        $business = $this->activeBusinessForOpportunities();

        // Exactly what production does: dispatch the canonical domain event.
        // No tinker, no command, no Home visit, no AI.
        BusinessUpdated::dispatch($business->id, ['website_url']);

        Queue::assertPushed(
            RunBusinessAdvisorOpportunityProducer::class,
            fn (RunBusinessAdvisorOpportunityProducer $job) => $job->businessId === $business->id,
        );
    }

    public function test_the_engine_default_is_still_disabled(): void
    {
        // The rollout rule: C-1 builds the paths and proves them; only the
        // owner turns the engine on, through config/env.
        $this->assertFalse(
            (bool) config('opportunity.enabled'),
            'This slice must not change the shipped default.'
        );
    }
}
