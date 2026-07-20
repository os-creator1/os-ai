<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionStarted;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityReopened;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Events\Opportunity\OpportunitySnoozed;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\Exceptions\InvalidOpportunityExecutionStateException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
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
 * RFC-002 Phase 4B.2C.4 note: registry-metadata failure scenarios (incorrect
 * handler_identifier/verifier_identifier, mutates_business_data=false,
 * approval_required=false, an unsupported completion_policy in the trusted
 * registry) are intentionally omitted, matching every prior Opportunity
 * Engine test file's precedent — OpportunityActionRegistry is closed,
 * `final`, and all-static; there is no test seam to substitute alternate
 * registry metadata without modifying production registry data.
 */
class OpportunityManagerConfirmApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
        Queue::fake([ExecuteOpportunityAction::class]);
    }

    private const KNOWN_EVENTS = [
        OpportunityApprovalRequested::class,
        OpportunityBecameCurrent::class,
        OpportunityCompleted::class,
        OpportunityCreated::class,
        OpportunityDismissed::class,
        OpportunityExecutionFailed::class,
        OpportunityExecutionStarted::class,
        OpportunityExecutionSucceeded::class,
        OpportunityMarkedStale::class,
        OpportunityReaffirmed::class,
        OpportunityReopened::class,
        OpportunityRunFailed::class,
        OpportunityRunStarted::class,
        OpportunityRunSucceeded::class,
        OpportunitySnoozed::class,
    ];

    public function test_confirm_approval_while_disabled_throws_and_creates_nothing(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $originalPhone = $business->phone;

        config()->set('opportunity.enabled', false);

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->confirmApproval($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->status);
            $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame($originalPhone, $business->fresh()->phone);

            Event::assertNotDispatched(OpportunityExecutionStarted::class);
            Queue::assertNothingPushed();
        }
    }

    public function test_awaiting_approval_creates_a_pending_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->status);
    }

    public function test_opportunity_becomes_in_progress(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
    }

    public function test_exact_execution_row_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame($opportunity->id, $execution->opportunity_id);
        $this->assertSame('add_phone', $execution->action_key);
        $this->assertSame($opportunity->recommended_action_hash, $execution->recommended_action_hash);
        $this->assertSame(1, $execution->action_schema_version);
        $this->assertSame(1, $execution->occurrence_number);
        $this->assertSame($business->customer->user_id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
        $this->assertSame(OpportunityCompletionPolicy::SystemVerified, $execution->completion_policy);
    }

    public function test_first_attempt_number_is_1(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame(1, $execution->attempt_number);
    }

    public function test_exact_idempotency_key_formula(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $expectedKey = hash('sha256', $opportunity->id . ':' . $opportunity->occurrence_number . ':' . $opportunity->recommended_action_hash . ':1');

        $this->assertSame($expectedKey, $execution->idempotency_key);
    }

    public function test_configured_phone_value_is_not_copied_into_any_summary_field(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertNull($execution->safe_result_summary);
        $this->assertNull($execution->safe_error_summary);
    }

    public function test_exact_workflow_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('awaiting_approval', $transition->from_status);
        $this->assertSame('in_progress', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('customer_confirmed_approval', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_opportunity_execution_started_exact_scalar_payload(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, function (OpportunityExecutionStarted $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->actionExecutionId === $execution->id
                && $event->actionKey === 'add_phone';
        });
    }

    public function test_execute_opportunity_action_is_dispatched_with_the_execution_id(): void
    {
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        Queue::assertPushed(ExecuteOpportunityAction::class, function (ExecuteOpportunityAction $job) use ($execution) {
            return $job->executionId === $execution->id;
        });
    }

    public function test_event_and_job_are_dispatched_only_after_a_genuine_commit(): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, 1);
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method except create() delegates to the real, fully initialized
     * repository; create() is forced to throw after the execution row and
     * Opportunity status have already been written within the same
     * transaction, rolling the whole thing back before the event/job are
     * ever dispatched.
     */
    public function test_rollback_dispatches_neither_event_nor_job(): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);

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
            $manager->confirmApproval($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        foreach (self::KNOWN_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    public function test_rollback_creates_no_execution_or_transition_and_leaves_awaiting_approval_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);

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

        try {
            $manager->confirmApproval($opportunity, $business->customer);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_duplicate_call_from_in_progress_returns_the_matching_pending_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = app(OpportunityManager::class);

        $returned = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame($execution->id, $returned->id);
    }

    public function test_duplicate_call_with_matching_running_execution_returns_it(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Running);
        $manager = app(OpportunityManager::class);

        $returned = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame($execution->id, $returned->id);
    }

    public function test_duplicate_call_creates_no_second_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_duplicate_call_creates_no_second_transition(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_duplicate_call_emits_no_second_event(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        Event::assertNotDispatched(OpportunityExecutionStarted::class);
    }

    public function test_duplicate_call_dispatches_no_second_job(): void
    {
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        Queue::assertNothingPushed();
    }

    public function test_in_progress_with_no_active_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business, ['status' => OpportunityStatus::InProgress->value]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_in_progress_with_mismatched_action_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_in_progress_with_mismatched_action_hash_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'recommended_action_hash' => hash('sha256', 'stale-execution-hash'),
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_in_progress_with_mismatched_schema_version_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'action_schema_version' => 2,
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_in_progress_with_mismatched_occurrence_number_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'occurrence_number' => 2,
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_in_progress_with_mismatched_completion_policy_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested->value,
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, InvalidOpportunityExecutionStateException::class);
    }

    public function test_unconfigured_empty_parameter_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business, [
            'recommended_action' => [
                'schema_version' => 1,
                'action_key' => 'add_phone',
                'parameters' => [],
                'approval_required' => true,
                'completion_policy' => 'system_verified',
            ],
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_malformed_configured_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business, [
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_corrupted_recommended_action_hash_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business, [
            'recommended_action_hash' => hash('sha256', 'corrupted'),
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_unsupported_action_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['action_key'] = 'add_email';
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        $opportunity = $this->awaitingApprovalOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ]);

        $this->assertConfirmRejectsAtomically($opportunity, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_another_customers_opportunity_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();

        $this->assertConfirmRejectsAtomically($opportunity, $strangerBusiness, AuthorizationException::class);
    }

    public function test_spoofed_supplied_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->confirmApproval($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(AuthorizationException::class);

        $manager->confirmApproval($opportunity, $business->customer);
    }

    public function test_open_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Open);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    public function test_completed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    public function test_no_business_mutation_occurs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $originalPhone = $business->phone;
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame($originalPhone, $business->fresh()->phone);
    }

    public function test_no_terminal_success_or_failure_event_is_emitted(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $manager->confirmApproval($opportunity, $business->customer);

        Event::assertNotDispatched(OpportunityExecutionSucceeded::class);
        Event::assertNotDispatched(OpportunityExecutionFailed::class);
        Event::assertNotDispatched(OpportunityCompleted::class);
    }

    public function test_created_execution_stores_only_approved_action_metadata_and_customer_initiator_identity(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business);
        $manager = app(OpportunityManager::class);

        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $this->assertSame('add_phone', $execution->action_key);
        $this->assertSame($opportunity->recommended_action_hash, $execution->recommended_action_hash);
        $this->assertSame($opportunity->action_schema_version, $execution->action_schema_version);
        $this->assertSame($opportunity->occurrence_number, $execution->occurrence_number);
        $this->assertSame($business->customer->user_id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
        $this->assertNull($execution->started_at);
        $this->assertNull($execution->completed_at);
        $this->assertNull($execution->safe_result_summary);
        $this->assertNull($execution->safe_error_summary);
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

    private function awaitingApprovalOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $recommendedAction = $overrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $overrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        return $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::AwaitingApproval->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $overrides));
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function inProgressOpportunityWithExecution(
        Business $business,
        OpportunityActionExecutionStatus $executionStatus,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $recommendedAction = $opportunityOverrides['recommended_action'] ?? $this->validAddPhoneAction();
        $hash = $opportunityOverrides['recommended_action_hash'] ?? (new OpportunityActionHash())->compute($recommendedAction);

        $opportunity = $this->createOpportunity($business, array_merge([
            'status' => OpportunityStatus::InProgress->value,
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
        ], $opportunityOverrides));

        $user = $this->createUser();

        $execution = $this->createOpportunityActionExecution($opportunity, $user, array_merge([
            'action_key' => 'add_phone',
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => $executionStatus->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function assertConfirmRejectsAtomically(Opportunity $opportunity, Business $business, string $exceptionClass): void
    {
        $originalStatus = $opportunity->status;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->confirmApproval($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($originalStatus, $opportunity->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->awaitingApprovalOpportunity($business, ['status' => $status->value]);

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityStateException::class);

            $manager->confirmApproval($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
