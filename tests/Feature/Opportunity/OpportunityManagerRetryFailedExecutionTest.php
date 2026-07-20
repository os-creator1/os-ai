<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Events\Opportunity\OpportunityExecutionStarted;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\Exceptions\OpportunityExecutionRetryNotAvailableException;
use App\Library\Opportunity\OpportunityActionHash;
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

/**
 * RFC-002 note: registry-metadata failure scenarios (incorrect
 * handler_identifier/verifier_identifier, mutates_business_data=false) are
 * intentionally omitted, matching every prior Opportunity Engine test
 * file's precedent — OpportunityActionRegistry is closed, `final`, and
 * all-static; there is no test seam to substitute alternate registry
 * metadata without modifying production registry data.
 */
class OpportunityManagerRetryFailedExecutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
        Queue::fake([ExecuteOpportunityAction::class]);
    }

    public function test_first_explicit_retry_creates_one_new_pending_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->status);
        $this->assertSame(2, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_failed_execution_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        $refreshed = $failedExecution->fresh();
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $refreshed->status);
        $this->assertSame(1, $refreshed->attempt_number);
    }

    public function test_new_attempt_number_is_the_next_repository_calculated_attempt(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame(2, $execution->attempt_number);
    }

    public function test_new_idempotency_key_uses_the_existing_exact_formula(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        $expectedKey = hash('sha256', $opportunity->id . ':' . $opportunity->occurrence_number . ':' . $opportunity->recommended_action_hash . ':2');

        $this->assertSame($expectedKey, $execution->idempotency_key);
    }

    public function test_new_execution_copies_the_live_trusted_action_identity_and_snapshot(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame($opportunity->id, $execution->opportunity_id);
        $this->assertSame('add_phone', $execution->action_key);
        $this->assertSame($opportunity->recommended_action_hash, $execution->recommended_action_hash);
        $this->assertSame($opportunity->action_schema_version, $execution->action_schema_version);
        $this->assertSame($opportunity->occurrence_number, $execution->occurrence_number);
        $this->assertSame($business->customer->user_id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
        $this->assertSame(OpportunityCompletionPolicy::SystemVerified, $execution->completion_policy);
        $this->assertNull($execution->started_at);
        $this->assertNull($execution->completed_at);
        $this->assertNull($execution->safe_result_summary);
        $this->assertNull($execution->safe_error_summary);
    }

    public function test_opportunity_changes_only_from_open_to_in_progress(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $originalFreshness = $opportunity->freshness;
        $originalRecommendedAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $originalSchemaVersion = $opportunity->action_schema_version;
        $originalOccurrenceNumber = $opportunity->occurrence_number;
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        $updated = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::InProgress, $updated->status);
        $this->assertSame($originalFreshness, $updated->freshness);
        $this->assertSame(
            CanonicalJson::encode($originalRecommendedAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
        $this->assertSame($originalSchemaVersion, $updated->action_schema_version);
        $this->assertSame($originalOccurrenceNumber, $updated->occurrence_number);
        $this->assertNull($updated->snoozed_until);
        $this->assertNull($updated->dismissed_at);
        $this->assertNull($updated->completed_at);
    }

    public function test_exact_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('in_progress', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('customer_retried_execution', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_exact_opportunity_execution_started_payload(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);

        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, function (OpportunityExecutionStarted $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->actionExecutionId === $execution->id
                && $event->actionKey === 'add_phone';
        });
    }

    public function test_event_is_delivered_only_after_commit(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);

        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, 1);
    }

    public function test_execute_opportunity_action_is_dispatched_after_commit(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->retryFailedExecution($opportunity, $business->customer);

        Queue::assertPushed(ExecuteOpportunityAction::class, function (ExecuteOpportunityAction $job) use ($execution) {
            return $job->executionId === $execution->id;
        });
    }

    public function test_immediate_duplicate_while_pending_returns_the_existing_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->retryFailedExecution($opportunity, $business->customer);
        $second = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame($first->id, $second->id);
    }

    public function test_duplicate_while_execution_is_running_returns_the_existing_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $first = $manager->retryFailedExecution($opportunity, $business->customer);
        $first->update(['status' => OpportunityActionExecutionStatus::Running->value]);

        $second = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame($first->id, $second->id);
    }

    public function test_duplicate_creates_no_second_transition_event_or_job(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);

        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);
        $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertDispatched(OpportunityExecutionStarted::class, 1);
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
    }

    public function test_retry_attempt_can_fail_and_then_be_retried_again_as_attempt_n_plus_1(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $secondAttempt = $manager->retryFailedExecution($opportunity, $business->customer);

        $manager->recordExecutionResult($secondAttempt, false, 'Business update could not be verified.');

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);

        $thirdAttempt = $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame(3, $thirdAttempt->attempt_number);
        $this->assertNotSame($secondAttempt->id, $thirdAttempt->id);
    }

    public function test_repeated_failed_retries_remain_unbounded_and_monotonic(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $attemptNumbers = [1];

        for ($i = 0; $i < 3; $i++) {
            $attempt = $manager->retryFailedExecution($opportunity, $business->customer);
            $attemptNumbers[] = $attempt->attempt_number;

            $manager->recordExecutionResult($attempt, false, 'Business update could not be verified.');
        }

        $this->assertSame([1, 2, 3, 4], $attemptNumbers);
    }

    public function test_completed_opportunity_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    public function test_awaiting_approval_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::AwaitingApproval);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_open_with_active_pending_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'attempt_number' => 2,
            'idempotency_key' => hash('sha256', 'inconsistent-pending'),
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_open_with_active_running_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'status' => OpportunityActionExecutionStatus::Running->value,
            'attempt_number' => 2,
            'idempotency_key' => hash('sha256', 'inconsistent-running'),
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_no_matching_failed_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->attestableOpenOpportunity($business);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_failed_execution_from_an_older_occurrence_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'occurrence_number' => 2,
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_failed_execution_with_another_action_hash_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'recommended_action_hash' => hash('sha256', 'stale-failed-execution-hash'),
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_failed_execution_with_another_schema_version_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'action_schema_version' => 2,
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_failed_execution_with_another_action_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }

    public function test_malformed_recommended_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_tampered_recommended_action_hash_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [
            'recommended_action_hash' => hash('sha256', 'tampered'),
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_non_system_verified_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['completion_policy'] = 'customer_attested';

        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [
            'recommended_action' => $recommendedAction,
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_non_executable_registry_action_configuration_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['action_key'] = 'add_email';
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        [$opportunity] = $this->openOpportunityWithFailedExecution($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ], [
            'action_key' => 'add_email',
        ]);

        $this->assertRetryRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_another_customers_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $strangerBusiness = $this->createBusinessForOpportunities();

        $this->assertRetryRejectsAtomically($opportunity, $strangerBusiness, AuthorizationException::class);
    }

    public function test_spoofed_supplied_business_id_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->retryFailedExecution($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->retryFailedExecution($opportunity, $business->customer);
    }

    public function test_feature_disabled_throws_before_any_query_or_write_side_effect(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business);

        config()->set('opportunity.enabled', false);

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->retryFailedExecution($opportunity, $business->customer);
        } finally {
            $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
            $this->assertSame(OpportunityActionExecutionStatus::Failed, $failedExecution->fresh()->status);
            $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            Event::assertNotDispatched(OpportunityExecutionStarted::class);
            Queue::assertNothingPushed();
        }
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method except create() delegates to the real, fully initialized
     * repository; create() is forced to throw after the new execution row
     * and Opportunity status have already been written within the same
     * transaction, rolling the whole thing back before the event/job are
     * ever dispatched.
     */
    public function test_forced_transition_failure_rolls_everything_back(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business);

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
            $manager->retryFailedExecution($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $failedExecution->fresh()->status);
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertNotDispatched(OpportunityExecutionStarted::class);
        Queue::assertNothingPushed();
    }

    public function test_no_business_mutation_occurs(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $originalPhone = $business->phone;
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertSame($originalPhone, $business->fresh()->phone);
    }

    public function test_no_completed_at_mutation_occurs(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertNull($opportunity->fresh()->completed_at);
    }

    public function test_no_existing_execution_row_is_modified(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business);
        $originalUpdatedAt = $failedExecution->updated_at;
        $manager = app(OpportunityManager::class);

        $manager->retryFailedExecution($opportunity, $business->customer);

        $this->assertTrue($originalUpdatedAt->equalTo($failedExecution->fresh()->updated_at));
    }

    /**
     * @return array<string, mixed>
     */
    private function validAddPhoneAction(mixed $value = '+15551234567'): array
    {
        return [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => $value],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
    }

    private function attestableOpenOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $recommendedAction = $overrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $overrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $overrides));
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function openOpportunityWithFailedExecution(
        Business $business,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $opportunity = $this->attestableOpenOpportunity($business, $opportunityOverrides);

        $user = $this->createUser();

        $failedExecution = $this->createOpportunityActionExecution($opportunity, $user, array_merge([
            'action_key' => 'add_phone',
            'recommended_action_hash' => $opportunity->recommended_action_hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'attempt_number' => 1,
            'status' => OpportunityActionExecutionStatus::Failed->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $failedExecution];
    }

    private function assertRetryRejectsAtomically(Opportunity $opportunity, Business $business, string $exceptionClass = OpportunityExecutionRetryNotAvailableException::class): void
    {
        $originalStatus = $opportunity->status;
        $originalTransitionCount = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        $originalExecutionCount = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->retryFailedExecution($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($originalStatus, $opportunity->status);
            $this->assertSame($originalTransitionCount, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame($originalExecutionCount, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        }
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->openOpportunityWithFailedExecution($business, ['status' => $status->value]);

        $this->assertRetryRejectsAtomically($opportunity, $business);
    }
}
