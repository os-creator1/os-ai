<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Events\Opportunity\OpportunityDismissed;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerDismissTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    public function test_open_opportunity_becomes_dismissed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->dismiss($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Dismissed, $updated->status);
        $this->assertNotNull($updated->dismissed_at);
    }

    public function test_awaiting_approval_opportunity_becomes_dismissed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::AwaitingApproval->value]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->dismiss($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Dismissed, $updated->status);
        $this->assertNotNull($updated->dismissed_at);
    }

    public function test_dismissed_at_uses_the_same_captured_operation_timestamp(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $frozenNow = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');
        $this->travelTo($frozenNow);

        try {
            $updated = $manager->dismiss($opportunity, $business->customer);

            $this->assertTrue($frozenNow->equalTo($updated->dismissed_at));
        } finally {
            $this->travelBack();
        }
    }

    public function test_freshness_and_unrelated_fields_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, [
            'status' => OpportunityStatus::Open->value,
            'freshness' => OpportunityFreshness::Stale->value,
            'occurrence_number' => 3,
            'stale_at' => now()->subDay(),
        ]);
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalLastConfirmedAt = $opportunity->last_confirmed_at;
        $originalLastConfirmedRunId = $opportunity->last_confirmed_run_id;
        $originalRecommendedAction = $opportunity->recommended_action;
        $originalRecommendedActionHash = $opportunity->recommended_action_hash;
        $originalActionSchemaVersion = $opportunity->action_schema_version;
        $originalEvidence = $opportunity->evidence;
        $originalPriorityScore = $opportunity->priority_score;
        $originalStaleAt = $opportunity->stale_at;
        $manager = app(OpportunityManager::class);

        $updated = $manager->dismiss($opportunity, $business->customer);

        $this->assertSame(OpportunityFreshness::Stale, $updated->freshness);
        $this->assertSame(3, $updated->occurrence_number);
        $this->assertNull($updated->snoozed_until);
        $this->assertNull($updated->completed_at);
        $this->assertTrue($originalStaleAt->equalTo($updated->stale_at));
        $this->assertTrue($originalFirstDetectedAt->equalTo($updated->first_detected_at));
        $this->assertTrue($originalLastConfirmedAt->equalTo($updated->last_confirmed_at));
        $this->assertSame($originalLastConfirmedRunId, $updated->last_confirmed_run_id);
        $this->assertSame($originalRecommendedAction, $updated->recommended_action);
        $this->assertSame($originalRecommendedActionHash, $updated->recommended_action_hash);
        $this->assertSame($originalActionSchemaVersion, $updated->action_schema_version);
        $this->assertSame($originalEvidence, $updated->evidence);
        $this->assertSame($originalPriorityScore, $updated->priority_score);
    }

    public function test_exact_transition_fields_from_open(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $manager->dismiss($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('dismissed', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);
        $this->assertSame('customer_dismissed', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_exact_transition_fields_from_awaiting_approval(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::AwaitingApproval->value]);
        $manager = app(OpportunityManager::class);

        $manager->dismiss($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame('awaiting_approval', $transition->from_status);
        $this->assertSame('dismissed', $transition->to_status);
        $this->assertSame('customer_dismissed', $transition->reason_code);
    }

    public function test_event_carries_the_correct_scalar_payload(): void
    {
        Event::fake([OpportunityDismissed::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $manager->dismiss($opportunity, $business->customer);

        Event::assertDispatched(OpportunityDismissed::class, function (OpportunityDismissed $event) use ($opportunity, $business) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->previousStatus === 'open';
        });
    }

    public function test_event_is_delivered_after_a_genuine_commit(): void
    {
        Event::fake([OpportunityDismissed::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $manager->dismiss($opportunity, $business->customer);

        Event::assertDispatched(OpportunityDismissed::class, 1);
    }

    /**
     * A delegating OpportunityRepository (not a partial mock): every method
     * except update() delegates to the real, fully initialized repository;
     * update() is forced to throw, rolling back the whole transaction
     * before OpportunityDismissed is ever dispatched.
     */
    public function test_rolled_back_transaction_delivers_no_event(): void
    {
        Event::fake([OpportunityDismissed::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);

        $real = app(OpportunityRepository::class);

        $repository = new class($real) implements OpportunityRepository {
            public function __construct(
                private readonly OpportunityRepository $real
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
                throw new RuntimeException('Forced rollback.');
            }
        };

        $this->app->instance(OpportunityRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $caught = null;

        try {
            $manager->dismiss($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertNull($opportunity->dismissed_at);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        Event::assertNotDispatched(OpportunityDismissed::class);
    }

    public function test_another_customers_opportunity_is_rejected_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);

        $strangerBusiness = $this->createBusinessForOpportunities();
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(AuthorizationException::class);

            $manager->dismiss($opportunity, $strangerBusiness->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }

    public function test_a_mismatched_supplied_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);

        $strangerBusiness = $this->createBusinessForOpportunities();

        // Tamper with the in-memory object's business_id — the persisted
        // row's true business_id is unchanged, so findOwnedForUpdate() must
        // find nothing, and ownership must not be bypassable this way.
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->dismiss($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_is_rejected_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->dismiss($opportunity, $business->customer);
    }

    public function test_in_progress_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::InProgress);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_completed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    public function test_dismissed_rejects_rather_than_silently_succeeding(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_no_action_execution_row_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $manager = app(OpportunityManager::class);

        $manager->dismiss($opportunity, $business->customer);

        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => $status->value]);
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityStateException::class);

            $manager->dismiss($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
