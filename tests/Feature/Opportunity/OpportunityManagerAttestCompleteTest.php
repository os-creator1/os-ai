<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionStarted;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\OpportunityAttestationNotAvailableException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerAttestCompleteTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_open_current_customer_attested_becomes_completed(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Completed, $updated->status);
    }

    public function test_completed_at_is_set(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertNotNull($updated->completed_at);
    }

    public function test_only_status_and_completed_at_change(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'occurrence_number' => 2,
        ]);
        $originalSnoozedUntil = $opportunity->snoozed_until;
        $originalDismissedAt = $opportunity->dismissed_at;
        $originalFirstDetectedAt = $opportunity->first_detected_at;
        $originalLastConfirmedAt = $opportunity->last_confirmed_at;
        $originalLastConfirmedRunId = $opportunity->last_confirmed_run_id;
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertNull($originalSnoozedUntil);
        $this->assertNull($updated->snoozed_until);
        $this->assertNull($originalDismissedAt);
        $this->assertNull($updated->dismissed_at);
        $this->assertTrue($originalFirstDetectedAt->equalTo($updated->first_detected_at));
        $this->assertTrue($originalLastConfirmedAt->equalTo($updated->last_confirmed_at));
        $this->assertSame($originalLastConfirmedRunId, $updated->last_confirmed_run_id);
    }

    public function test_freshness_remains_current(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(OpportunityFreshness::Current, $updated->freshness);
    }

    public function test_occurrence_number_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, ['occurrence_number' => 4]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(4, $updated->occurrence_number);
    }

    public function test_action_hash_schema_evidence_and_score_fields_remain_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action_hash' => hash('sha256', 'attest-hash'),
            'action_schema_version' => 1,
        ]);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $originalSchemaVersion = $opportunity->action_schema_version;
        $originalEvidence = $opportunity->evidence;
        $originalPriorityScore = $opportunity->priority_score;
        $originalGoalRelevanceRank = $opportunity->goal_relevance_rank;
        $originalEvidenceFreshnessRank = $opportunity->evidence_freshness_rank;
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
        $this->assertSame($originalSchemaVersion, $updated->action_schema_version);
        $this->assertSame($originalEvidence, $updated->evidence);
        $this->assertSame($originalPriorityScore, $updated->priority_score);
        $this->assertSame($originalGoalRelevanceRank, $updated->goal_relevance_rank);
        $this->assertSame($originalEvidenceFreshnessRank, $updated->evidence_freshness_rank);
    }

    public function test_exact_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->attestComplete($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('completed', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertNull($transition->action_execution_id);
        $this->assertSame('customer_attested', $transition->reason_code);
    }

    public function test_exact_safe_note_text(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->attestComplete($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertSame('Marked complete by the customer; not independently verified.', $transition->safe_note);
    }

    public function test_exact_opportunity_completed_scalar_payload(): void
    {
        Event::fake([OpportunityCompleted::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->attestComplete($opportunity, $business->customer);

        Event::assertDispatched(OpportunityCompleted::class, function (OpportunityCompleted $event) use ($opportunity, $business) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->actionExecutionId === null
                && $event->completionPolicy === 'customer_attested';
        });
    }

    public function test_event_is_dispatched_only_after_a_genuine_commit(): void
    {
        Event::fake([OpportunityCompleted::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->attestComplete($opportunity, $business->customer);

        Event::assertDispatched(OpportunityCompleted::class, 1);
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method except create() delegates to the real, fully initialized
     * repository; create() is forced to throw after the Opportunity update
     * has already been issued within the same transaction, rolling the
     * whole thing back before the event is ever dispatched.
     */
    public function test_forced_transition_failure_rolls_back_and_delivers_no_event(): void
    {
        Event::fake([OpportunityCompleted::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);

        $real = app(OpportunityTransitionRepository::class);

        $repository = new class($real) implements OpportunityTransitionRepository {
            public function __construct(
                private readonly OpportunityTransitionRepository $real
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
                throw new RuntimeException('Forced rollback.');
            }
        };

        $this->app->instance(OpportunityTransitionRepository::class, $repository);

        $manager = app(OpportunityManager::class);

        $caught = null;

        try {
            $manager->attestComplete($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $opportunity->refresh();
        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertNull($opportunity->completed_at);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        Event::assertNotDispatched(OpportunityCompleted::class);
    }

    public function test_repeat_call_on_an_already_completed_customer_attested_opportunity_is_a_no_op(): void
    {
        Event::fake([OpportunityCompleted::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->attestComplete($opportunity, $business->customer);
        $second = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Completed, $second->status);
        $this->assertTrue($first->completed_at->equalTo($second->completed_at));
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertDispatched(OpportunityCompleted::class, 1);
    }

    public function test_completed_system_verified_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'status' => OpportunityStatus::Completed->value,
            'completed_at' => now(),
            'recommended_action' => $this->attestableRecommendedAction('system_verified'),
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_completed_external_verified_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'status' => OpportunityStatus::Completed->value,
            'completed_at' => now(),
            'recommended_action' => $this->attestableRecommendedAction('external_verified'),
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_awaiting_approval_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::AwaitingApproval);
    }

    public function test_in_progress_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::InProgress);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_open_but_stale_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'freshness' => OpportunityFreshness::Stale->value,
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_open_current_system_verified_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action' => $this->attestableRecommendedAction('system_verified'),
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_open_current_external_verified_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action' => $this->attestableRecommendedAction('external_verified'),
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_missing_recommended_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action' => null,
            'recommended_action_hash' => null,
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_malformed_recommended_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action' => ['not_a_policy_bearing_shape' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_missing_completion_policy_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->attestableRecommendedAction();
        unset($recommendedAction['completion_policy']);

        $opportunity = $this->attestableOpportunity($business, [
            'recommended_action' => $recommendedAction,
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_pending_active_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_running_active_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Running->value,
        ]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }

    public function test_terminal_failed_execution_does_not_block_attestation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Completed, $updated->status);
    }

    public function test_terminal_succeeded_execution_does_not_block_attestation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::Completed, $updated->status);
    }

    public function test_no_execution_row_business_mutation_job_or_execution_event(): void
    {
        Queue::fake();
        Event::fake([
            OpportunityExecutionStarted::class,
            OpportunityExecutionSucceeded::class,
            OpportunityExecutionFailed::class,
            OpportunityCompleted::class,
        ]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $originalPhone = $business->phone;
        $manager = app(OpportunityManager::class);

        $manager->attestComplete($opportunity, $business->customer);

        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($originalPhone, $business->fresh()->phone);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(OpportunityExecutionStarted::class);
        Event::assertNotDispatched(OpportunityExecutionSucceeded::class);
        Event::assertNotDispatched(OpportunityExecutionFailed::class);
        Event::assertDispatched(OpportunityCompleted::class, 1);
    }

    public function test_another_customers_opportunity_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();

        $this->assertAttestRejectsAtomically($opportunity, $strangerBusiness, AuthorizationException::class);
    }

    public function test_spoofed_supplied_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->attestComplete($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->attestComplete($opportunity, $business->customer);
    }

    public function test_feature_disabled_throws_before_any_write_or_query_side_effect(): void
    {
        Event::fake([OpportunityCompleted::class]);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business);

        config()->set('opportunity.enabled', false);

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->attestComplete($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertNull($opportunity->completed_at);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            Event::assertNotDispatched(OpportunityCompleted::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attestableRecommendedAction(string $policy = 'customer_attested'): array
    {
        return [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => [],
            'approval_required' => true,
            'completion_policy' => $policy,
        ];
    }

    private function attestableOpportunity(Business $business, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'freshness' => OpportunityFreshness::Current->value,
            'recommended_action' => $this->attestableRecommendedAction(),
        ], $overrides));
    }

    private function assertAttestRejectsAtomically(Opportunity $opportunity, Business $business, string $exceptionClass = OpportunityAttestationNotAvailableException::class): void
    {
        $originalStatus = $opportunity->status;
        $originalCompletedAt = $opportunity->completed_at;
        $originalTransitionCount = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        $originalExecutionCount = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->attestComplete($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($originalStatus, $opportunity->status);

            if ($originalCompletedAt === null) {
                $this->assertNull($opportunity->completed_at);
            } else {
                $this->assertTrue($originalCompletedAt->equalTo($opportunity->completed_at));
            }

            $this->assertSame($originalTransitionCount, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame($originalExecutionCount, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        }
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpportunity($business, ['status' => $status->value]);

        $this->assertAttestRejectsAtomically($opportunity, $business);
    }
}
