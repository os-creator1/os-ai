<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\InvalidOpportunityExecutionStateException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class OpportunityManagerRecordExecutionResultTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const TERMINAL_EVENTS = [
        OpportunityExecutionSucceeded::class,
        OpportunityCompleted::class,
        OpportunityExecutionFailed::class,
    ];

    public function test_successful_running_execution_completes_the_opportunity(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame(OpportunityStatus::Completed, $updated->status);
    }

    public function test_successful_execution_status_becomes_succeeded(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
    }

    public function test_success_summary_is_persisted_exactly(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame('Business phone updated and verified.', $execution->fresh()->safe_result_summary);
    }

    public function test_success_uses_one_timestamp_for_execution_and_opportunity_completed_at(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $refreshedExecution = $execution->fresh();
        $refreshedOpportunity = $opportunity->fresh();

        $this->assertNotNull($refreshedExecution->completed_at);
        $this->assertNotNull($refreshedOpportunity->completed_at);
        $this->assertTrue($refreshedExecution->completed_at->equalTo($refreshedOpportunity->completed_at));
    }

    public function test_exact_success_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('in_progress', $transition->from_status);
        $this->assertSame('completed', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::System, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('execution_succeeded', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_opportunity_execution_succeeded_scalar_payload(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        Event::assertDispatched(OpportunityExecutionSucceeded::class, function (OpportunityExecutionSucceeded $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $execution->initiated_by_user_id
                && $event->actionExecutionId === $execution->id
                && $event->actionKey === 'add_phone';
        });
    }

    public function test_opportunity_completed_scalar_payload(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        Event::assertDispatched(OpportunityCompleted::class, function (OpportunityCompleted $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $execution->initiated_by_user_id
                && $event->actionExecutionId === $execution->id
                && $event->completionPolicy === 'system_verified';
        });
    }

    public function test_both_success_events_are_delivered_after_a_genuine_commit(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        Event::assertDispatched(OpportunityExecutionSucceeded::class, 1);
        Event::assertDispatched(OpportunityCompleted::class, 1);
    }

    public function test_failure_returns_opportunity_to_open(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $this->assertSame(OpportunityStatus::Open, $updated->status);
        $this->assertNull($updated->completed_at);
    }

    public function test_failed_execution_status_becomes_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
    }

    public function test_allowlisted_failure_summary_is_persisted_exactly(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $this->assertSame('Business update could not be verified.', $execution->fresh()->safe_error_summary);
    }

    public function test_failure_clears_safe_result_summary(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'safe_result_summary' => 'stale prior summary',
        ]);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $this->assertNull($execution->fresh()->safe_result_summary);
    }

    public function test_exact_failure_transition_fields(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('in_progress', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::System, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('execution_failed', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_opportunity_execution_failed_scalar_payload(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        Event::assertDispatched(OpportunityExecutionFailed::class, function (OpportunityExecutionFailed $event) use ($opportunity, $business, $execution) {
            return $event->opportunityId === $opportunity->id
                && $event->businessId === $business->id
                && $event->actorUserId === $execution->initiated_by_user_id
                && $event->actionExecutionId === $execution->id
                && $event->actionKey === 'add_phone'
                && $event->safeErrorSummary === 'Business update could not be verified.';
        });
    }

    public function test_failure_event_is_delivered_after_a_genuine_commit(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        Event::assertDispatched(OpportunityExecutionFailed::class, 1);
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method except create() delegates to the real, fully initialized
     * repository; create() is forced to throw after both the execution and
     * Opportunity updates have already been issued within the same
     * transaction, rolling the whole thing back before any terminal event
     * is ever dispatched.
     */
    public function test_rollback_dispatches_no_success_or_failure_event(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);

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
            $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $opportunity->refresh();
        $execution->refresh();
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->status);
        $this->assertSame(OpportunityActionExecutionStatus::Running, $execution->status);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        foreach (self::TERMINAL_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }
    }

    public function test_blank_success_summary_rejects_atomically(): void
    {
        $this->assertRejectsAtomically(true, '   ');
    }

    public function test_null_success_summary_rejects_atomically(): void
    {
        $this->assertRejectsAtomically(true, null);
    }

    public function test_success_summary_over_255_characters_rejects(): void
    {
        $this->assertRejectsAtomically(true, str_repeat('a', 256));
    }

    public function test_non_allowlisted_failure_summary_rejects(): void
    {
        $this->assertRejectsAtomically(false, 'Something went wrong.');
    }

    public function test_raw_exception_style_failure_text_rejects(): void
    {
        $this->assertRejectsAtomically(false, 'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'opportunities\' doesn\'t exist');
    }

    public function test_execution_not_running_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_opportunity_not_in_progress_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [
            'status' => OpportunityStatus::Open->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    /**
     * $executionB's real, persisted opportunity_id already points to its own
     * Opportunity B, so simply passing it alongside a different Opportunity
     * would never exercise the locked-row cross-check at all (the manager
     * always re-locks using the execution's own opportunity_id first). To
     * genuinely test "locked execution.opportunity_id equals locked
     * Opportunity.id", the supplied model itself must be stale/untrusted:
     * its in-memory opportunity_id is mutated to point at Opportunity A
     * (never saved), so the manager locks Opportunity A first but then
     * locks execution B's real row — whose real, persisted opportunity_id
     * still points to Opportunity B — producing a genuine mismatch.
     */
    public function test_stale_supplied_execution_opportunity_id_mismatch_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunityA, $executionA] = $this->inProgressOpportunityWithRunningExecution($business);
        [$opportunityB, $executionB] = $this->inProgressOpportunityWithRunningExecution($business);

        $executionB->opportunity_id = $opportunityA->id;
        $executionB->unsetRelation('opportunity');

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityExecutionStateException::class);

            $manager->recordExecutionResult($executionB, true, 'Business phone updated and verified.');
        } finally {
            $opportunityA->refresh();
            $opportunityB->refresh();
            $executionA->refresh();
            $executionB->refresh();

            $this->assertSame(OpportunityStatus::InProgress, $opportunityA->status);
            $this->assertSame(OpportunityStatus::InProgress, $opportunityB->status);
            $this->assertSame(OpportunityActionExecutionStatus::Running, $executionA->status);
            $this->assertSame(OpportunityActionExecutionStatus::Running, $executionB->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunityA->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunityB->id)->count());
        }
    }

    public function test_action_key_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_recommended_action_hash_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'recommended_action_hash' => hash('sha256', 'stale-execution-hash'),
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_action_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'action_schema_version' => 2,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_occurrence_number_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'occurrence_number' => 2,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_non_system_verified_completion_policy_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_missing_execution_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $execution->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');
    }

    /**
     * opportunity_action_executions.opportunity_id cascades on delete, so an
     * execution can never realistically outlive its Opportunity — deleting
     * the Opportunity removes both rows together, exercising the same
     * "no associated Opportunity" guard from the other side.
     */
    public function test_missing_opportunity_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');
    }

    public function test_freshness_and_configured_action_remain_unchanged_on_success(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [
            'freshness' => OpportunityFreshness::Stale->value,
        ]);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame(OpportunityFreshness::Stale, $updated->freshness);
        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
    }

    public function test_freshness_and_configured_action_remain_unchanged_on_failure(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [
            'freshness' => OpportunityFreshness::Stale->value,
        ]);
        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, false, 'Business update could not be verified.');

        $this->assertSame(OpportunityFreshness::Stale, $updated->freshness);
        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);
    }

    public function test_no_business_mutation_occurs(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $originalPhone = $business->phone;
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame($originalPhone, $business->fresh()->phone);
    }

    public function test_no_new_execution_row_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_no_job_is_dispatched(): void
    {
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        Queue::assertNothingPushed();
    }

    public function test_failure_may_be_recorded_from_pending(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        $this->assertInstanceOf(Opportunity::class, $updated);
    }

    public function test_pending_failure_sets_execution_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
    }

    public function test_pending_failure_returns_opportunity_to_open(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        $this->assertSame(OpportunityStatus::Open, $updated->status);
        $this->assertNull($updated->completed_at);
    }

    public function test_pending_failure_creates_the_exact_execution_failed_transition(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('in_progress', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::System, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('execution_failed', $transition->reason_code);
        $this->assertNull($transition->safe_note);
    }

    public function test_pending_failure_dispatches_opportunity_execution_failed_after_commit(): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);
        $manager = app(OpportunityManager::class);

        $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        Event::assertDispatched(OpportunityExecutionFailed::class, 1);
        Event::assertNotDispatched(OpportunityExecutionSucceeded::class);
        Event::assertNotDispatched(OpportunityCompleted::class);
    }

    public function test_success_from_pending_still_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_running_success_behavior_remains_unchanged(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, true, 'Business phone updated and verified.');

        $this->assertSame(OpportunityStatus::Completed, $updated->status);
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
    }

    public function test_terminal_succeeded_execution_still_rejects_in_record_execution_result(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Succeeded->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, false, 'Opportunity action state no longer matched the approved action.');
    }

    public function test_terminal_failed_execution_still_rejects_in_record_execution_result(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Failed->value,
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, false, 'Opportunity action state no longer matched the approved action.');
    }

    public function test_pending_action_key_mismatch_may_be_recorded_failed_with_state_mismatch_summary(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'action_key' => 'add_email',
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_pending_hash_mismatch_may_be_recorded_failed_with_state_mismatch_summary(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'recommended_action_hash' => hash('sha256', 'stale-execution-hash'),
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_pending_schema_version_mismatch_may_be_recorded_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'action_schema_version' => 2,
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_pending_occurrence_number_mismatch_may_be_recorded_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'occurrence_number' => 2,
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_pending_completion_policy_mismatch_may_be_recorded_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested->value,
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_malformed_live_recommended_action_may_be_recorded_failed(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [
            'recommended_action' => ['not_an_action_key' => true],
            'recommended_action_hash' => hash('sha256', 'malformed'),
        ], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_running_execution_mismatch_supports_state_mismatch_failure(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertMismatchFailureRecordedCorrectly($opportunity, $execution);
    }

    public function test_success_with_mismatch_still_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, true, 'Business phone updated and verified.');
    }

    public function test_mismatch_with_could_not_be_completed_summary_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'action_key' => 'add_email',
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, false, 'Opportunity action could not be completed.');
    }

    public function test_mismatch_with_could_not_be_verified_summary_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business, [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'action_key' => 'add_email',
        ]);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, false, 'Business update could not be verified.');
    }

    /**
     * Verifies the full accepted state-mismatch failure outcome: execution
     * failed, Opportunity back to open, the exact execution_failed
     * transition, OpportunityExecutionFailed dispatched, and the live
     * Opportunity's recommended_action/hash left completely untouched
     * (never overwritten with the execution's stale bound values).
     */
    private function assertMismatchFailureRecordedCorrectly(Opportunity $opportunity, OpportunityActionExecution $execution): void
    {
        Event::fake(self::TERMINAL_EVENTS);

        $originalAction = $opportunity->recommended_action;
        $originalHash = $opportunity->recommended_action_hash;
        $manager = app(OpportunityManager::class);

        $updated = $manager->recordExecutionResult($execution, false, 'Opportunity action state no longer matched the approved action.');

        $this->assertSame(OpportunityStatus::Open, $updated->status);
        $this->assertNull($updated->completed_at);
        $this->assertSame(
            CanonicalJson::encode($originalAction),
            CanonicalJson::encode($updated->recommended_action),
        );
        $this->assertSame($originalHash, $updated->recommended_action_hash);

        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertSame('Opportunity action state no longer matched the approved action.', $execution->fresh()->safe_error_summary);

        $transition = OpportunityTransition::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($transition);
        $this->assertSame(OpportunityTransitionCategory::Workflow, $transition->category);
        $this->assertSame('in_progress', $transition->from_status);
        $this->assertSame('open', $transition->to_status);
        $this->assertSame(OpportunityTransitionActorType::System, $transition->actor_type);
        $this->assertNull($transition->actor_user_id);
        $this->assertNull($transition->opportunity_run_id);
        $this->assertSame($execution->id, $transition->action_execution_id);
        $this->assertSame('execution_failed', $transition->reason_code);
        $this->assertNull($transition->safe_note);

        Event::assertDispatched(OpportunityExecutionFailed::class, 1);
        Event::assertNotDispatched(OpportunityExecutionSucceeded::class);
        Event::assertNotDispatched(OpportunityCompleted::class);
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function inProgressOpportunityWithRunningExecution(
        Business $business,
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $recommendedAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

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
            'status' => OpportunityActionExecutionStatus::Running->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function assertRejectsAtomically(bool $succeeded, mixed $safeSummary): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithRunningExecution($business);

        $this->assertRejectsAtomicallyFor($opportunity, $execution, $succeeded, $safeSummary);
    }

    private function assertRejectsAtomicallyFor(Opportunity $opportunity, OpportunityActionExecution $execution, bool $succeeded, mixed $safeSummary): void
    {
        $originalOpportunityStatus = $opportunity->status;
        $originalExecutionStatus = $execution->status;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityExecutionStateException::class);

            $manager->recordExecutionResult($execution, $succeeded, $safeSummary);
        } finally {
            $opportunity->refresh();
            $execution->refresh();
            $this->assertSame($originalOpportunityStatus, $opportunity->status);
            $this->assertSame($originalExecutionStatus, $execution->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
