<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunitySnoozeExpired;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerSweepExpiredSnoozesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_expired_snoozed_opportunity_becomes_open(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    public function test_snoozed_until_is_cleared(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        $this->assertNull($opportunity->fresh()->snoozed_until);
    }

    public function test_no_unrelated_opportunity_field_changes(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business, [
            'freshness' => OpportunityFreshness::Stale->value,
            'occurrence_number' => 3,
        ]);
        $originalFreshness = $opportunity->freshness;
        $originalOccurrenceNumber = $opportunity->occurrence_number;
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $originalEvidence = $opportunity->evidence;
        $originalPriorityScore = $opportunity->priority_score;
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalLastConfirmedAt = $opportunity->last_confirmed_at;
        $originalCompletedAt = $opportunity->completed_at;
        $originalDismissedAt = $opportunity->dismissed_at;
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        $updated = $opportunity->fresh();
        $this->assertSame($originalFreshness, $updated->freshness);
        $this->assertSame($originalOccurrenceNumber, $updated->occurrence_number);
        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
        $this->assertSame($originalEvidence, $updated->evidence);
        $this->assertSame($originalPriorityScore, $updated->priority_score);
        $this->assertTrue($originalFirstDetectedAt->equalTo($updated->first_detected_at));
        $this->assertTrue($originalLastConfirmedAt->equalTo($updated->last_confirmed_at));
        $this->assertNull($originalCompletedAt);
        $this->assertNull($updated->completed_at);
        $this->assertNull($originalDismissedAt);
        $this->assertNull($updated->dismissed_at);
    }

    public function test_exact_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('snoozed', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::System, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);
        $this->assertSame('snooze_expired', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_exact_opportunity_snooze_expired_scalar_payload(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        Event::assertDispatched(OpportunitySnoozeExpired::class, function (OpportunitySnoozeExpired $event) use ($opportunity, $business) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id;
        });
    }

    public function test_event_is_dispatched_only_after_a_genuine_commit(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->sweepExpiredSnoozes(10);

        Event::assertDispatched(OpportunitySnoozeExpired::class, 1);
    }

    public function test_second_sweep_is_idempotent(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $firstCount = $manager->sweepExpiredSnoozes(10);
        $secondCount = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(1, $firstCount);
        $this->assertSame(0, $secondCount);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertDispatched(OpportunitySnoozeExpired::class, 1);
    }

    public function test_return_value_equals_rows_actually_reopened(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(3, $count);
    }

    public function test_batch_limit_is_honored(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);
        $this->expiredSnoozedOpportunity($business);
        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(2);

        $this->assertSame(2, $count);
        $this->assertSame(1, Opportunity::where('business_id', $business->id)->where('status', OpportunityStatus::Snoozed->value)->count());
    }

    public function test_future_snooze_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business, ['snoozed_until' => now()->addDay()]);
        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(0, $count);
        $updated = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::Snoozed, $updated->status);
        $this->assertNotNull($updated->snoozed_until);
    }

    public function test_null_snoozed_until_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business, ['snoozed_until' => null]);
        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(0, $count);
        $this->assertSame(OpportunityStatus::Snoozed, $opportunity->fresh()->status);
    }

    public function test_non_snoozed_status_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(0, $count);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    public function test_missing_locked_row_is_skipped_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $missingId = $opportunity->id;

        $real = app(OpportunityRepository::class);

        $repository = new class($real, $missingId) implements OpportunityRepository {
            public function __construct(
                private readonly OpportunityRepository $real,
                private readonly int $missingId,
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

            public function findByFingerprintForUpdate(string $fingerprint): ?Opportunity
            {
                return $this->real->findByFingerprintForUpdate($fingerprint);
            }

            public function findOwnedForUpdate(int $id, int $businessId): ?Opportunity
            {
                if ($id === $this->missingId) {
                    return null;
                }

                return $this->real->findOwnedForUpdate($id, $businessId);
            }

            public function findOwned(int $id, int $businessId): ?Opportunity
            {
                return $this->real->findOwned($id, $businessId);
            }

            public function currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId): Collection
            {
                return $this->real->currentMissingFromRunForUpdate($businessId, $workerKey, $excludeRunId);
            }

            public function expiredSnoozesBatch(int $limit): Collection
            {
                return $this->real->expiredSnoozesBatch($limit);
            }

            public function paginateForCustomer(Business $business, array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForCustomer($business, $filters);
            }

            public function topForCustomer(Business $business, int $limit): Collection
            {
                return $this->real->topForCustomer($business, $limit);
            }

            public function paginateForAdmin(array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForAdmin($filters);
            }

            public function findForAdmin(int $opportunityId): ?Opportunity
            {
                return $this->real->findForAdmin($opportunityId);
            }

            public function create(array $attributes): Opportunity
            {
                return $this->real->create($attributes);
            }

            public function update(Opportunity $opportunity, array $attributes): Opportunity
            {
                return $this->real->update($opportunity, $attributes);
            }
        };

        $this->app->instance(OpportunityRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(0, $count);
        $this->assertSame(OpportunityStatus::Snoozed, $opportunity->fresh()->status);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    /**
     * A delegating OpportunityRepository (not a partial mock): every method
     * delegates to the real, fully initialized repository, except
     * findOwnedForUpdate() — which, only for the targeted row, mutates it
     * (reopens it directly, bypassing the manager) immediately before
     * delegating, simulating a concurrent explicit un-snooze that happened
     * between the unlocked candidate read and the row lock. The locked
     * re-check must then observe the mutated row and skip it.
     */
    public function test_race_re_check_skips_a_concurrently_reopened_row(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);
        $targetId = $opportunity->id;

        $real = app(OpportunityRepository::class);

        $repository = new class($real, $targetId) implements OpportunityRepository {
            public function __construct(
                private readonly OpportunityRepository $real,
                private readonly int $targetId,
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

            public function findByFingerprintForUpdate(string $fingerprint): ?Opportunity
            {
                return $this->real->findByFingerprintForUpdate($fingerprint);
            }

            public function findOwnedForUpdate(int $id, int $businessId): ?Opportunity
            {
                if ($id === $this->targetId) {
                    Opportunity::where('id', $id)->update([
                        'status' => OpportunityStatus::Open->value,
                        'snoozed_until' => null,
                    ]);
                }

                return $this->real->findOwnedForUpdate($id, $businessId);
            }

            public function findOwned(int $id, int $businessId): ?Opportunity
            {
                return $this->real->findOwned($id, $businessId);
            }

            public function currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId): Collection
            {
                return $this->real->currentMissingFromRunForUpdate($businessId, $workerKey, $excludeRunId);
            }

            public function expiredSnoozesBatch(int $limit): Collection
            {
                return $this->real->expiredSnoozesBatch($limit);
            }

            public function paginateForCustomer(Business $business, array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForCustomer($business, $filters);
            }

            public function topForCustomer(Business $business, int $limit): Collection
            {
                return $this->real->topForCustomer($business, $limit);
            }

            public function paginateForAdmin(array $filters): LengthAwarePaginator
            {
                return $this->real->paginateForAdmin($filters);
            }

            public function findForAdmin(int $opportunityId): ?Opportunity
            {
                return $this->real->findForAdmin($opportunityId);
            }

            public function create(array $attributes): Opportunity
            {
                return $this->real->create($attributes);
            }

            public function update(Opportunity $opportunity, array $attributes): Opportunity
            {
                return $this->real->update($opportunity, $attributes);
            }
        };

        $this->app->instance(OpportunityRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(0, $count);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $targetId)->count());
        Event::assertNotDispatched(OpportunitySnoozeExpired::class);
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method delegates to the real repository except create(), which
     * throws only for the first candidate's transition — proving that
     * row's transaction rolls back completely while the second, unrelated
     * candidate still reopens successfully in its own transaction.
     */
    public function test_forced_transition_failure_for_the_first_candidate_does_not_block_the_second(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $firstCandidate = $this->expiredSnoozedOpportunity($business, ['snoozed_until' => now()->subMinutes(10)]);
        $secondCandidate = $this->expiredSnoozedOpportunity($business, ['snoozed_until' => now()->subMinutes(5)]);

        $real = app(OpportunityTransitionRepository::class);

        $repository = new class($real, $firstCandidate->id) implements OpportunityTransitionRepository {
            public function __construct(
                private readonly OpportunityTransitionRepository $real,
                private readonly int $failingOpportunityId,
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

            public function forOpportunity(int $opportunityId): Collection
            {
                return $this->real->forOpportunity($opportunityId);
            }

            public function create(array $attributes): OpportunityTransition
            {
                if (($attributes['opportunity_id'] ?? null) === $this->failingOpportunityId) {
                    throw new RuntimeException('Forced rollback.');
                }

                return $this->real->create($attributes);
            }
        };

        $this->app->instance(OpportunityTransitionRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $count = $manager->sweepExpiredSnoozes(10);

        $this->assertSame(1, $count);

        $this->assertSame(OpportunityStatus::Snoozed, $firstCandidate->fresh()->status);
        $this->assertNotNull($firstCandidate->fresh()->snoozed_until);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $firstCandidate->id)->count());

        $this->assertSame(OpportunityStatus::Open, $secondCandidate->fresh()->status);
        $this->assertNull($secondCandidate->fresh()->snoozed_until);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $secondCandidate->id)->count());

        Event::assertDispatched(OpportunitySnoozeExpired::class, 1);
        Event::assertDispatched(OpportunitySnoozeExpired::class, function (OpportunitySnoozeExpired $event) use ($secondCandidate) {
            return $event->opportunityId === $secondCandidate->id;
        });
    }

    public function test_feature_disabled_throws_and_performs_no_mutation(): void
    {
        Event::fake([OpportunitySnoozeExpired::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->expiredSnoozedOpportunity($business);

        config()->set('opportunity.enabled', false);

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->sweepExpiredSnoozes(10);
        } finally {
            $updated = $opportunity->fresh();
            $this->assertSame(OpportunityStatus::Snoozed, $updated->status);
            $this->assertNotNull($updated->snoozed_until);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            Event::assertNotDispatched(OpportunitySnoozeExpired::class);
        }
    }

    private function expiredSnoozedOpportunity(Business $business, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Snoozed->value,
            'snoozed_until' => now()->subMinute(),
        ], $overrides));
    }
}
