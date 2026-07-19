<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\OpportunityRun;
use App\Repositories\Contracts\OpportunityRunRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerBeginRunTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_begin_run_creates_a_running_run_with_correct_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $run = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 3);

        $this->assertSame($business->id, $run->business_id);
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $run->worker_key);
        $this->assertSame(3, $run->producer_version);
        $this->assertSame(OpportunityRunStatus::Running, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->heartbeat_at);
        $this->assertNull($run->completed_at);
        $this->assertNull($run->abandoned_at);
        $this->assertNull($run->reason_code);
        $this->assertNull($run->safe_error_summary);
    }

    public function test_second_begin_run_with_a_healthy_run_throws_and_creates_nothing(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $first = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        try {
            $this->expectException(RunAlreadyActiveException::class);

            $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 2);
        } finally {
            $this->assertSame(1, OpportunityRun::where('business_id', $business->id)->count());
            $first->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $first->status);
            $this->assertNull($first->completed_at);
        }
    }

    public function test_same_business_different_worker_is_allowed_after_serialization(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        $advisorRun = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        $seoRun = $manager->beginRun($business, OpportunityWorkerKey::Seo, 1);

        $this->assertNotSame($advisorRun->id, $seoRun->id);
        $this->assertSame(OpportunityRunStatus::Running, $advisorRun->fresh()->status);
        $this->assertSame(OpportunityRunStatus::Running, $seoRun->status);
        $this->assertSame(2, OpportunityRun::where('business_id', $business->id)->count());
    }

    public function test_timed_out_run_is_marked_failed_and_abandoned_before_replacement_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        $stale = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);
        $manager = app(OpportunityManager::class);

        $replacement = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 2);

        $stale->refresh();
        $this->assertSame(OpportunityRunStatus::Failed, $stale->status);
        $this->assertNotNull($stale->abandoned_at);
        $this->assertNotNull($stale->completed_at);

        $this->assertSame(OpportunityRunStatus::Running, $replacement->status);
        $this->assertNotSame($stale->id, $replacement->id);
    }

    public function test_abandoned_run_has_exact_safe_failure_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $stale = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);
        $manager = app(OpportunityManager::class);

        $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        $stale->refresh();
        $this->assertSame('heartbeat_timeout', $stale->reason_code);
        $this->assertSame('This run stopped responding and was marked failed.', $stale->safe_error_summary);
    }

    public function test_abandonment_and_start_events_carry_correct_scalar_payloads(): void
    {
        Event::fake([OpportunityRunFailed::class, OpportunityRunStarted::class]);

        $business = $this->createBusinessForOpportunities();
        $stale = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);
        $manager = app(OpportunityManager::class);

        $replacement = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        Event::assertDispatched(OpportunityRunFailed::class, function (OpportunityRunFailed $event) use ($stale, $business) {
            return $event->runId === $stale->id
                && $event->businessId === $business->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value
                && $event->safeErrorSummary === 'This run stopped responding and was marked failed.';
        });

        Event::assertDispatched(OpportunityRunStarted::class, function (OpportunityRunStarted $event) use ($replacement, $business) {
            return $event->runId === $replacement->id
                && $event->businessId === $business->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value;
        });
    }

    public function test_healthy_run_rejection_dispatches_neither_event(): void
    {
        Event::fake([OpportunityRunFailed::class, OpportunityRunStarted::class]);

        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        Event::assertDispatched(OpportunityRunStarted::class, 1);

        try {
            $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 2);
        } catch (RunAlreadyActiveException) {
            // expected
        }

        Event::assertDispatched(OpportunityRunStarted::class, 1);
        Event::assertNotDispatched(OpportunityRunFailed::class);
    }

    /**
     * OpportunityRunFailed::dispatch() is called for the abandonment before
     * the replacement run is created in the same transaction. This forces
     * the replacement-creation step to fail afterward, proving
     * ShouldDispatchAfterCommit means neither event is ever actually
     * delivered once the whole transaction rolls back.
     */
    public function test_a_failed_transaction_dispatches_no_after_commit_event(): void
    {
        Event::fake([OpportunityRunFailed::class, OpportunityRunStarted::class]);

        $business = $this->createBusinessForOpportunities();
        $stale = $this->createOpportunityRun($business, ['heartbeat_at' => now()->subMinutes(45)]);

        // A delegating anonymous implementation, not a partial mock:
        // partialMock() bypasses the Eloquent repository's constructor,
        // leaving EloquentBaseRepository::$model null. findForUpdate()/
        // findRunningForUpdate()/update() delegate to the real, fully
        // initialized repository — so the timed-out run is genuinely locked
        // and updated to failed inside the transaction — while only
        // create() (replacement creation) is forced to throw, rolling back
        // that same transaction.
        $realRepository = app(OpportunityRunRepository::class);

        $repository = new class($realRepository) implements OpportunityRunRepository {
            public function __construct(
                private readonly OpportunityRunRepository $real
            ) {
            }

            public function query()
            {
                return $this->real->query();
            }

            public function search($query, $callback = null)
            {
                return $this->real->search($query, $callback);
            }

            public function select(array $columns = ['*'])
            {
                return $this->real->select($columns);
            }

            public function make(array $attributes = [])
            {
                return $this->real->make($attributes);
            }

            public function findForUpdate(int $id): ?OpportunityRun
            {
                return $this->real->findForUpdate($id);
            }

            public function findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityRun
            {
                return $this->real->findRunningForUpdate($businessId, $workerKey);
            }

            public function create(array $attributes): OpportunityRun
            {
                throw new RuntimeException('Forced rollback.');
            }

            public function update(OpportunityRun $run, array $attributes): OpportunityRun
            {
                return $this->real->update($run, $attributes);
            }
        };

        $this->app->instance(OpportunityRunRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        try {
            $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
            $this->fail('Expected the simulated repository failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Forced rollback.', $e->getMessage());
        }

        $stale->refresh();
        $this->assertSame(OpportunityRunStatus::Running, $stale->status);
        $this->assertNull($stale->abandoned_at);
        $this->assertNull($stale->completed_at);
        $this->assertNull($stale->reason_code);
        $this->assertNull($stale->safe_error_summary);

        $this->assertSame(1, OpportunityRun::where('business_id', $business->id)->count());

        Event::assertNotDispatched(OpportunityRunFailed::class);
        Event::assertNotDispatched(OpportunityRunStarted::class);
    }

    public function test_missing_locked_business_row_fails_safely_and_creates_no_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $businessId = $business->id;
        $business->delete();

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(RuntimeException::class);

            $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        } finally {
            $this->assertSame(0, OpportunityRun::where('business_id', $businessId)->count());
        }
    }

    /**
     * The RFC's abandonment comparison is strictly "older than" the cutoff
     * (§24.2) — a heartbeat exactly at the cutoff must still be treated as
     * healthy. Time is frozen so the fixture's heartbeat_at and the
     * manager's own now() resolve to the exact same instant.
     */
    public function test_heartbeat_exactly_at_the_timeout_cutoff_is_still_healthy(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $timeoutMinutes = config('opportunity.run_timeout_minutes', 30);
        $frozenNow = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');

        $this->travelTo($frozenNow);

        $existing = $this->createOpportunityRun($business, [
            'heartbeat_at' => $frozenNow->subMinutes($timeoutMinutes),
        ]);

        try {
            $this->expectException(RunAlreadyActiveException::class);

            $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
        } finally {
            $existing->refresh();
            $this->assertSame(OpportunityRunStatus::Running, $existing->status);
            $this->travelBack();
        }
    }

    public function test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned(): void
    {
        $business = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);
        $timeoutMinutes = config('opportunity.run_timeout_minutes', 30);
        $frozenNow = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');

        $this->travelTo($frozenNow);

        $stale = $this->createOpportunityRun($business, [
            'heartbeat_at' => $frozenNow->subMinutes($timeoutMinutes)->subSecond(),
        ]);

        $replacement = $manager->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);

        $stale->refresh();
        $this->assertSame(OpportunityRunStatus::Failed, $stale->status);
        $this->assertSame(OpportunityRunStatus::Running, $replacement->status);

        $this->travelBack();
    }
}
