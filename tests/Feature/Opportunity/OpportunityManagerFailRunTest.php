<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Library\Opportunity\Exceptions\RunAlreadySucceededException;
use App\Library\Opportunity\Exceptions\RunNotFoundException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use App\Models\OpportunityRunCandidate;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerFailRunTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_running_run_becomes_failed_with_exact_supplied_safe_summary(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'The producer job failed unexpectedly.');

        $run->refresh();
        $this->assertSame(OpportunityRunStatus::Failed, $run->status);
        $this->assertSame('The producer job failed unexpectedly.', $run->safe_error_summary);
    }

    public function test_completed_at_is_set_and_unrelated_run_fields_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, [
            'producer_version' => 7,
            'heartbeat_at' => now()->subMinutes(2),
        ]);
        $originalHeartbeat = $run->heartbeat_at;
        $originalStartedAt = $run->started_at;
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Explicit failure.');

        $run->refresh();
        $this->assertNotNull($run->completed_at);
        $this->assertSame($business->id, $run->business_id);
        $this->assertSame(OpportunityWorkerKey::BusinessAdvisor, $run->worker_key);
        $this->assertSame(7, $run->producer_version);
        $this->assertTrue($originalStartedAt->equalTo($run->started_at));
        $this->assertTrue($originalHeartbeat->equalTo($run->heartbeat_at));
        $this->assertNull($run->abandoned_at);
        $this->assertNull($run->reason_code);
    }

    public function test_staged_candidates_remain_intact(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $candidate = $this->createOpportunityRunCandidate($run);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Explicit failure.');

        $candidate->refresh();
        $this->assertNotNull($candidate->id);
        $this->assertSame($run->id, $candidate->opportunity_run_id);
        $this->assertSame(1, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
    }

    public function test_no_live_opportunity_or_transition_row_is_written_or_modified(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Explicit failure.');

        $this->assertSame(0, Opportunity::where('business_id', $business->id)->count());
        $this->assertSame(0, OpportunityTransition::query()->count());
    }

    public function test_missing_run_throws_run_not_found_exception(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $run->delete();
        $manager = app(OpportunityManager::class);

        $this->expectException(RunNotFoundException::class);

        $manager->failRun($run, 'Explicit failure.');
    }

    public function test_already_failed_run_is_an_idempotent_no_op_preserving_original_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Original failure summary.');
        $run->refresh();
        $originalCompletedAt = $run->completed_at;

        $manager->failRun($run, 'A different, later summary that must not overwrite the original.');

        $run->refresh();
        $this->assertSame('Original failure summary.', $run->safe_error_summary);
        $this->assertTrue($originalCompletedAt->equalTo($run->completed_at));
        $this->assertNull($run->abandoned_at);
        $this->assertNull($run->reason_code);
    }

    public function test_already_failed_no_op_dispatches_no_duplicate_event(): void
    {
        Event::fake([OpportunityRunFailed::class]);

        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Original failure summary.');
        Event::assertDispatched(OpportunityRunFailed::class, 1);

        $manager->failRun($run, 'A second call that must not dispatch again.');

        Event::assertDispatched(OpportunityRunFailed::class, 1);
    }

    public function test_succeeded_run_throws_and_remains_succeeded(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Succeeded->value]);
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(RunAlreadySucceededException::class);

            $manager->failRun($run, 'Should not be applied.');
        } finally {
            $run->refresh();
            $this->assertSame(OpportunityRunStatus::Succeeded, $run->status);
            $this->assertNull($run->safe_error_summary);
        }
    }

    public function test_successful_explicit_failure_dispatches_correct_scalar_payloads(): void
    {
        Event::fake([OpportunityRunFailed::class]);

        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $manager = app(OpportunityManager::class);

        $manager->failRun($run, 'Explicit failure summary.');

        Event::assertDispatched(OpportunityRunFailed::class, function (OpportunityRunFailed $event) use ($run, $business) {
            return $event->runId === $run->id
                && $event->businessId === $business->id
                && $event->workerKey === OpportunityWorkerKey::BusinessAdvisor->value
                && $event->safeErrorSummary === 'Explicit failure summary.';
        });
    }

    /**
     * A delegating anonymous implementation, not a partial mock, matching
     * the pattern already established for beginRun(): findForUpdate()
     * delegates to the real, fully initialized repository (so the run is
     * genuinely locked and its status genuinely inspected), while update()
     * is forced to throw, rolling back the whole transaction before
     * OpportunityRunFailed is ever dispatched.
     */
    public function test_a_rolled_back_transaction_delivers_no_after_commit_event(): void
    {
        Event::fake([OpportunityRunFailed::class]);

        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);

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

            public function paginateForBusiness(int $businessId, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
            {
                return $this->real->paginateForBusiness($businessId, $perPage);
            }

            public function findForAdmin(int $runId): ?OpportunityRun
            {
                return $this->real->findForAdmin($runId);
            }

            public function create(array $attributes): OpportunityRun
            {
                return $this->real->create($attributes);
            }

            public function update(OpportunityRun $run, array $attributes): OpportunityRun
            {
                throw new RuntimeException('Forced rollback.');
            }
        };

        $this->app->instance(OpportunityRunRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        try {
            $manager->failRun($run, 'Explicit failure.');
            $this->fail('Expected the simulated repository failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Forced rollback.', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame(OpportunityRunStatus::Running, $run->status);
        $this->assertNull($run->completed_at);
        $this->assertNull($run->safe_error_summary);

        Event::assertNotDispatched(OpportunityRunFailed::class);
    }
}
