<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityFreshness;
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
use App\Library\Opportunity\Exceptions\OpportunityApprovalRequiredException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityEvidenceValidator;
use App\Library\Opportunity\OpportunityFingerprint;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Opportunity\OpportunityScorer;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use App\Repositories\Contracts\OpportunityRunCandidateRepository;
use App\Repositories\Contracts\OpportunityRunRepository;
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
 * RFC-002 Milestone 4 beginTrustedAction() note: no approval_required=false
 * production action exists yet (RFC-002 §30), so every success/duplicate/
 * capability scenario here uses an invented `test_trusted_action` key,
 * simulated entirely through the two protected test seams
 * (lookupTrustedActionDefinition()/trustedActionExecutorSupports()) via a
 * delegating OpportunityManager subclass — never a fake production registry
 * entry or executor handler. The real OpportunityActionExecutor is only
 * ever consulted through its own real, unmodified `supports()` method in
 * OpportunityActionExecutorTest; it is never invoked here, since
 * ExecuteOpportunityAction is queued (Queue::fake()) and never actually run.
 */
class OpportunityManagerBeginTrustedActionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const TEST_ACTION_KEY = 'test_trusted_action';

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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
        Queue::fake([ExecuteOpportunityAction::class]);
    }

    // ---------------------------------------------------------------
    // Success path
    // ---------------------------------------------------------------

    public function test_open_current_valid_trusted_action_creates_one_pending_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['freshness' => OpportunityFreshness::Current->value]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->status);
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_open_stale_valid_trusted_action_also_creates_one_pending_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['freshness' => OpportunityFreshness::Stale->value]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->status);
    }

    public function test_first_attempt_number_is_1(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame(1, $execution->attempt_number);
    }

    public function test_exact_idempotency_key_formula(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $expectedKey = hash('sha256', $opportunity->id . ':' . $opportunity->occurrence_number . ':' . $opportunity->recommended_action_hash . ':1');

        $this->assertSame($expectedKey, $execution->idempotency_key);
    }

    public function test_exact_execution_snapshot(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame($opportunity->id, $execution->opportunity_id);
        $this->assertSame(self::TEST_ACTION_KEY, $execution->action_key);
        $this->assertSame($opportunity->recommended_action_hash, $execution->recommended_action_hash);
        $this->assertSame(1, $execution->action_schema_version);
        $this->assertSame(1, $execution->occurrence_number);
        $this->assertSame(1, $execution->attempt_number);
        $this->assertSame($business->customer->user_id, $execution->initiated_by_user_id);
        $this->assertSame('customer', $execution->initiated_by_type);
        $this->assertSame(OpportunityCompletionPolicy::SystemVerified, $execution->completion_policy);
        $this->assertNull($execution->started_at);
        $this->assertNull($execution->completed_at);
        $this->assertNull($execution->safe_result_summary);
        $this->assertNull($execution->safe_error_summary);
    }

    public function test_opportunity_changes_only_open_to_in_progress(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $originalRecommendedAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $originalOccurrence = $opportunity->occurrence_number;
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $updated = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::InProgress, $updated->status);
        $this->assertSame($originalOccurrence, $updated->occurrence_number);
        $this->assertNull($updated->completed_at);
        $this->assertNull($updated->snoozed_until);
        $this->assertNull($updated->dismissed_at);
        $this->assertSame($originalHash, $updated->recommended_action_hash);
        $this->assertSame(
            CanonicalJson::encode($originalRecommendedAction),
            CanonicalJson::encode($updated->recommended_action),
        );
    }

    public function test_freshness_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['freshness' => OpportunityFreshness::Stale->value]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame(OpportunityFreshness::Stale, $opportunity->fresh()->freshness);
    }

    public function test_exact_workflow_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('open', $transition->from_status);
        $this->assertSame('in_progress', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::Customer, $transition->actor_type);
        $this->assertSame($business->customer->user_id, $transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('customer_began_trusted_action', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_opportunity_execution_started_exact_scalar_payload(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, function (OpportunityExecutionStarted $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $business->customer->user_id
                && $event->actionExecutionId === $execution->id
                && $event->actionKey === self::TEST_ACTION_KEY;
        });
    }

    public function test_event_is_dispatched_only_after_commit(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        Event::assertDispatched(OpportunityExecutionStarted::class, 1);
    }

    public function test_execute_opportunity_action_is_dispatched_after_commit_with_the_execution_id(): void
    {
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $execution = $manager->beginTrustedAction($opportunity, $business->customer);

        Queue::assertPushed(ExecuteOpportunityAction::class, function (ExecuteOpportunityAction $job) use ($execution) {
            return $job->executionId === $execution->id;
        });
    }

    public function test_no_opportunity_approval_requested_event(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        Event::assertNotDispatched(OpportunityApprovalRequested::class);
    }

    public function test_no_awaiting_approval_state(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertNotSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Duplicate in_progress requests
    // ---------------------------------------------------------------

    public function test_duplicate_call_while_pending_returns_existing_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressTrustedOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $returned = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame($execution->id, $returned->id);
    }

    public function test_duplicate_call_while_running_returns_existing_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressTrustedOpportunityWithExecution($business, OpportunityActionExecutionStatus::Running);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $returned = $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame($execution->id, $returned->id);
    }

    public function test_duplicate_call_creates_no_second_execution_transition_event_or_job(): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressTrustedOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertNotDispatched(OpportunityExecutionStarted::class);
        Queue::assertNothingPushed();
    }

    public function test_in_progress_with_no_active_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['status' => OpportunityStatus::InProgress->value]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        $manager->beginTrustedAction($opportunity, $business->customer);
    }

    public function test_in_progress_with_mismatched_active_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressTrustedOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'action_key' => 'some_other_action',
        ]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        try {
            $manager->beginTrustedAction($opportunity, $business->customer);
        } finally {
            $unchanged = $execution->fresh();
            $this->assertSame($execution->status, $unchanged->status);
            $this->assertSame($execution->action_key, $unchanged->action_key);
        }
    }

    public function test_open_with_active_pending_execution_rejects(): void
    {
        $this->assertOpenWithActiveExecutionRejects(OpportunityActionExecutionStatus::Pending);
    }

    public function test_open_with_active_running_execution_rejects(): void
    {
        $this->assertOpenWithActiveExecutionRejects(OpportunityActionExecutionStatus::Running);
    }

    // ---------------------------------------------------------------
    // Starting-status rejections
    // ---------------------------------------------------------------

    public function test_awaiting_approval_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::AwaitingApproval);
    }

    public function test_completed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Completed);
    }

    public function test_snoozed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Snoozed);
    }

    public function test_dismissed_rejects(): void
    {
        $this->assertStatusRejects(OpportunityStatus::Dismissed);
    }

    // ---------------------------------------------------------------
    // Trusted-action eligibility rejections
    // ---------------------------------------------------------------

    public function test_action_requiring_approval_throws_approval_required_exception(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validTrustedRecommendedAction();
        $recommendedAction['approval_required'] = true;
        $hash = (new OpportunityActionHash())->compute($recommendedAction);
        $opportunity = $this->openTrustedOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ]);
        $definition = array_merge($this->validTrustedActionDefinition(), ['approval_required' => true]);
        $manager = $this->trustedManager($definition);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityApprovalRequiredException::class);
    }

    public function test_mutates_business_data_true_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $definition = array_merge($this->validTrustedActionDefinition(), ['mutates_business_data' => true]);
        $manager = $this->trustedManager($definition);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_customer_attested_completion_policy_rejects(): void
    {
        $this->assertCompletionPolicyMismatchRejects(OpportunityCompletionPolicy::CustomerAttested);
    }

    public function test_external_verified_completion_policy_rejects(): void
    {
        $this->assertCompletionPolicyMismatchRejects(OpportunityCompletionPolicy::ExternalVerified);
    }

    public function test_unsupported_executor_capability_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition(), false);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_handler_identifier_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $definition = array_merge($this->validTrustedActionDefinition(), ['handler_identifier' => null]);
        $manager = $this->trustedManager($definition);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_verifier_identifier_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $definition = array_merge($this->validTrustedActionDefinition(), ['verifier_identifier' => null]);
        $manager = $this->trustedManager($definition);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_malformed_recommended_action_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, [
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_registry_definition_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager(null);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['action_schema_version' => 2]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_tampered_hash_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, [
            'recommended_action_hash' => hash('sha256', 'tampered'),
        ]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    public function test_invalid_parameters_reject(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validTrustedRecommendedAction('   ');
        $hash = (new OpportunityActionHash())->compute($recommendedAction);
        $opportunity = $this->openTrustedOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    // ---------------------------------------------------------------
    // Ownership / disabled / rollback
    // ---------------------------------------------------------------

    public function test_another_customers_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->expectException(AuthorizationException::class);

        $manager->beginTrustedAction($opportunity, $strangerBusiness->customer);
    }

    public function test_spoofed_business_id_cannot_bypass_ownership(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $strangerBusiness = $this->createBusinessForOpportunities();
        $opportunity->business_id = $strangerBusiness->id;
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->expectException(AuthorizationException::class);

        $manager->beginTrustedAction($opportunity, $strangerBusiness->customer);
    }

    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $opportunity->delete();
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->expectException(AuthorizationException::class);

        $manager->beginTrustedAction($opportunity, $business->customer);
    }

    public function test_begin_trusted_action_while_disabled_throws_and_creates_nothing(): void
    {
        Event::fake(self::KNOWN_EVENTS);

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        config()->set('opportunity.enabled', false);

        try {
            $this->expectException(OpportunityEngineDisabledException::class);

            $manager->beginTrustedAction($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

            Event::assertNotDispatched(OpportunityExecutionStarted::class);
            Queue::assertNothingPushed();
        }
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock),
     * bound before the trusted-action manager is built so the manager's own
     * container-resolved dependency picks it up: every method except
     * create() delegates to the real repository; create() is forced to
     * throw after the execution row and Opportunity status have already
     * been written within the same transaction.
     */
    public function test_forced_transition_failure_rolls_back_execution_and_opportunity_mutation(): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);

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

        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $caught = null;

        try {
            $manager->beginTrustedAction($opportunity, $business->customer);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        foreach (self::KNOWN_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    public function test_no_business_mutation_occurs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $originalPhone = $business->phone;
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertSame($originalPhone, $business->fresh()->phone);
    }

    public function test_completed_at_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $manager->beginTrustedAction($opportunity, $business->customer);

        $this->assertNull($opportunity->fresh()->completed_at);
    }

    public function test_rejection_leaves_existing_execution_rows_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressTrustedOpportunityWithExecution($business, OpportunityActionExecutionStatus::Pending, [], [
            'action_key' => 'some_other_action',
        ]);
        $originalAttributes = $execution->getAttributes();
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        try {
            $manager->beginTrustedAction($opportunity, $business->customer);
        } catch (InvalidOpportunityExecutionStateException) {
            // expected
        }

        $originalForComparison = $originalAttributes;
        $refreshedForComparison = $execution->fresh()->getAttributes();
        ksort($originalForComparison);
        ksort($refreshedForComparison);

        $this->assertSame($originalForComparison, $refreshedForComparison);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validTrustedActionDefinition(): array
    {
        return [
            'schema_version' => 1,
            'mutates_business_data' => false,
            'approval_required' => false,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [
                'value' => [
                    'required' => true,
                    'type' => 'string',
                    'max_length' => 50,
                    'allow_blank' => false,
                ],
            ],
            'handler_identifier' => 'test.trusted_handler',
            'verifier_identifier' => 'test.trusted_verifier',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validTrustedRecommendedAction(mixed $value = 'trusted-value'): array
    {
        return [
            'schema_version' => 1,
            'action_key' => self::TEST_ACTION_KEY,
            'parameters' => ['value' => $value],
            'approval_required' => false,
            'completion_policy' => 'system_verified',
        ];
    }

    private function openTrustedOpportunity(Business $business, array $overrides = []): Opportunity
    {
        $recommendedAction = $overrides['recommended_action'] ?? $this->validTrustedRecommendedAction();
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
    private function inProgressTrustedOpportunityWithExecution(
        Business $business,
        OpportunityActionExecutionStatus $executionStatus,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $recommendedAction = $opportunityOverrides['recommended_action'] ?? $this->validTrustedRecommendedAction();
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
            'action_key' => self::TEST_ACTION_KEY,
            'recommended_action_hash' => $hash,
            'action_schema_version' => 1,
            'occurrence_number' => 1,
            'status' => $executionStatus->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    /**
     * A delegating OpportunityManager subclass — every method delegates to
     * the real implementation except the two protected trusted-action test
     * seams, which are overridden to simulate a registry-owned
     * approval_required=false action and a chosen executor-capability
     * result, without adding a production registry entry or handler.
     */
    private function trustedManager(?array $definition, bool $supportsExecution = true): OpportunityManager
    {
        return new class(
            app(BusinessRepository::class),
            app(OpportunityRunRepository::class),
            app(OpportunityRunCandidateRepository::class),
            app(CustomerOnboardingRepository::class),
            app(OpportunityFingerprint::class),
            app(OpportunityActionHash::class),
            app(OpportunityEvidenceValidator::class),
            app(OpportunityScorer::class),
            app(OpportunityRepository::class),
            app(OpportunityTransitionRepository::class),
            app(OpportunityActionExecutionRepository::class),
            app(OpportunityActionExecutor::class),
            $definition,
            $supportsExecution,
        ) extends OpportunityManager {
            public function __construct(
                BusinessRepository $businessRepository,
                OpportunityRunRepository $runRepository,
                OpportunityRunCandidateRepository $candidateRepository,
                CustomerOnboardingRepository $onboardingRepository,
                OpportunityFingerprint $fingerprint,
                OpportunityActionHash $actionHash,
                OpportunityEvidenceValidator $evidenceValidator,
                OpportunityScorer $scorer,
                OpportunityRepository $opportunityRepository,
                OpportunityTransitionRepository $transitionRepository,
                OpportunityActionExecutionRepository $actionExecutionRepository,
                OpportunityActionExecutor $opportunityActionExecutor,
                private readonly ?array $testDefinition,
                private readonly bool $testSupportsExecution,
            ) {
                parent::__construct(
                    $businessRepository,
                    $runRepository,
                    $candidateRepository,
                    $onboardingRepository,
                    $fingerprint,
                    $actionHash,
                    $evidenceValidator,
                    $scorer,
                    $opportunityRepository,
                    $transitionRepository,
                    $actionExecutionRepository,
                    $opportunityActionExecutor,
                );
            }

            protected function lookupTrustedActionDefinition(string $actionKey): ?array
            {
                return $this->testDefinition;
            }

            protected function trustedActionExecutorSupports(string $actionKey, array $actionDefinition): bool
            {
                return $this->testSupportsExecution;
            }
        };
    }

    private function assertOpenWithActiveExecutionRejects(OpportunityActionExecutionStatus $executionStatus): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'action_key' => self::TEST_ACTION_KEY,
            'status' => $executionStatus->value,
        ]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, InvalidOpportunityExecutionStateException::class, 1);
    }

    private function assertCompletionPolicyMismatchRejects(OpportunityCompletionPolicy $policy): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validTrustedRecommendedAction();
        $recommendedAction['completion_policy'] = $policy->value;
        $hash = (new OpportunityActionHash())->compute($recommendedAction);
        $opportunity = $this->openTrustedOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ]);
        $definition = array_merge($this->validTrustedActionDefinition(), ['completion_policy' => $policy]);
        $manager = $this->trustedManager($definition);

        $this->assertBeginTrustedActionRejectsFromOpen($opportunity, $business, $manager, OpportunityActionNotExecutableException::class);
    }

    private function assertStatusRejects(OpportunityStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->openTrustedOpportunity($business, ['status' => $status->value]);
        $manager = $this->trustedManager($this->validTrustedActionDefinition());

        try {
            $this->expectException(InvalidOpportunityStateException::class);

            $manager->beginTrustedAction($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertSame(0, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }

    private function assertBeginTrustedActionRejectsFromOpen(
        Opportunity $opportunity,
        Business $business,
        OpportunityManager $manager,
        string $exceptionClass,
        int $expectedPreExistingExecutions = 0
    ): void {
        try {
            $this->expectException($exceptionClass);

            $manager->beginTrustedAction($opportunity, $business->customer);
        } finally {
            $opportunity->refresh();
            $this->assertSame(OpportunityStatus::Open, $opportunity->status);
            $this->assertSame($expectedPreExistingExecutions, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
