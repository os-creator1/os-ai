<?php

namespace Tests\Feature\Opportunity;

use App\DTO\Opportunity\OpportunityExecutionAttempt;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityReopened;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Events\Opportunity\OpportunitySnoozed;
use App\Library\Opportunity\Exceptions\InvalidOpportunityExecutionStateException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Phase 4B.2C.3A note: a registry-metadata failure scenario
 * (incorrect handler_identifier/verifier_identifier, mutates_business_data
 * =false, an unsupported completion_policy in the trusted registry) is
 * intentionally omitted, matching OpportunityActionExecutorTest and
 * OpportunityManagerRequestApprovalTest's own precedent — OpportunityAction
 * Registry is closed, `final`, and all-static, and beginExecutionAttempt()
 * calls it the same way those do; there is no test seam to substitute
 * alternate registry metadata without modifying production registry data.
 */
class OpportunityManagerBeginExecutionAttemptTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const KNOWN_EVENTS = [
        OpportunityApprovalRequested::class,
        OpportunityBecameCurrent::class,
        OpportunityCompleted::class,
        OpportunityCreated::class,
        OpportunityDismissed::class,
        OpportunityExecutionFailed::class,
        OpportunityExecutionSucceeded::class,
        OpportunityMarkedStale::class,
        OpportunityReaffirmed::class,
        OpportunityReopened::class,
        OpportunityRunFailed::class,
        OpportunityRunStarted::class,
        OpportunityRunSucceeded::class,
        OpportunitySnoozed::class,
    ];

    public function test_valid_pending_execution_becomes_running(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->beginExecutionAttempt($execution);

        $this->assertSame(OpportunityActionExecutionStatus::Running, $execution->fresh()->status);
    }

    public function test_returned_dto_contains_the_correct_opportunity_and_running_execution(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $manager = app(OpportunityManager::class);

        $attempt = $manager->beginExecutionAttempt($execution);

        $this->assertInstanceOf(OpportunityExecutionAttempt::class, $attempt);
        $this->assertSame($opportunity->id, $attempt->opportunity->id);
        $this->assertSame($execution->id, $attempt->execution->id);
        $this->assertSame(OpportunityActionExecutionStatus::Running, $attempt->execution->status);
    }

    public function test_only_execution_status_changes(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->beginExecutionAttempt($execution);

        $refreshed = $execution->fresh();
        $this->assertNull($refreshed->started_at);
        $this->assertNull($refreshed->completed_at);
        $this->assertNull($refreshed->safe_result_summary);
        $this->assertNull($refreshed->safe_error_summary);
        $this->assertSame($execution->action_key, $refreshed->action_key);
        $this->assertSame($execution->recommended_action_hash, $refreshed->recommended_action_hash);
        $this->assertSame($execution->occurrence_number, $refreshed->occurrence_number);
    }

    public function test_opportunity_remains_in_progress(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->beginExecutionAttempt($execution);

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
    }

    public function test_no_transition_event_or_job_is_created(): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $manager = app(OpportunityManager::class);

        $manager->beginExecutionAttempt($execution);

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        foreach (self::KNOWN_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    public function test_running_redelivery_returns_null_without_writes(): void
    {
        $this->assertRedeliveryReturnsNull(OpportunityActionExecutionStatus::Running);
    }

    public function test_succeeded_redelivery_returns_null_without_writes(): void
    {
        $this->assertRedeliveryReturnsNull(OpportunityActionExecutionStatus::Succeeded);
    }

    public function test_failed_redelivery_returns_null_without_writes(): void
    {
        $this->assertRedeliveryReturnsNull(OpportunityActionExecutionStatus::Failed);
    }

    public function test_missing_execution_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $execution->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        $manager->beginExecutionAttempt($execution);
    }

    /**
     * opportunity_action_executions.opportunity_id cascades on delete, so
     * an execution can never realistically outlive its Opportunity —
     * deleting the Opportunity removes both rows together.
     */
    public function test_missing_opportunity_rejects_where_realistically_testable(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $opportunity->delete();

        $manager = app(OpportunityManager::class);

        $this->expectException(InvalidOpportunityExecutionStateException::class);

        $manager->beginExecutionAttempt($execution);
    }

    /**
     * $executionB's real, persisted opportunity_id already points to its
     * own Opportunity B, so its in-memory opportunity_id is mutated
     * (unsaved) to point at Opportunity A, simulating a stale/untrusted
     * supplied model — the manager locks Opportunity A first but then
     * locks execution B's real row, whose real, persisted opportunity_id
     * still points to Opportunity B, producing a genuine mismatch.
     */
    public function test_stale_supplied_execution_opportunity_id_mismatch_rejects_atomically(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunityA, $executionA] = $this->pendingOpportunityWithExecution($business);
        [$opportunityB, $executionB] = $this->pendingOpportunityWithExecution($business);

        $executionB->opportunity_id = $opportunityA->id;
        $executionB->unsetRelation('opportunity');

        $manager = app(OpportunityManager::class);

        try {
            $this->expectException(InvalidOpportunityExecutionStateException::class);

            $manager->beginExecutionAttempt($executionB);
        } finally {
            $opportunityA->refresh();
            $opportunityB->refresh();
            $executionA->refresh();
            $executionB->refresh();

            $this->assertSame(OpportunityStatus::InProgress, $opportunityA->status);
            $this->assertSame(OpportunityStatus::InProgress, $opportunityB->status);
            $this->assertSame(OpportunityActionExecutionStatus::Pending, $executionA->status);
            $this->assertSame(OpportunityActionExecutionStatus::Pending, $executionB->status);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunityA->id)->count());
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunityB->id)->count());
        }
    }

    public function test_opportunity_not_in_progress_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [
            'status' => OpportunityStatus::Open->value,
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_action_key_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'action_key' => 'add_email',
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_action_hash_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'recommended_action_hash' => hash('sha256', 'stale-execution-hash'),
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'action_schema_version' => 2,
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_occurrence_number_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'occurrence_number' => 2,
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_completion_policy_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested->value,
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_malformed_configured_parameters_reject(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['parameters'] = [];
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ], [
            'recommended_action_hash' => $hash,
        ]);

        $this->assertPendingRejectsAtomically($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
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

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function pendingOpportunityWithExecution(
        Business $business,
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
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function assertRedeliveryReturnsNull(OpportunityActionExecutionStatus $status): void
    {
        Event::fake(self::KNOWN_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'status' => $status->value,
        ]);
        $manager = app(OpportunityManager::class);

        $result = $manager->beginExecutionAttempt($execution);

        $this->assertNull($result);
        $this->assertSame($status, $execution->fresh()->status);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        foreach (self::KNOWN_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    private function assertPendingRejectsAtomically(Opportunity $opportunity, OpportunityActionExecution $execution, Business $business, string $exceptionClass): void
    {
        $originalOpportunityStatus = $opportunity->status;
        $originalPhone = $business->phone;
        $manager = app(OpportunityManager::class);

        try {
            $this->expectException($exceptionClass);

            $manager->beginExecutionAttempt($execution);
        } finally {
            $opportunity->refresh();
            $execution->refresh();
            $this->assertSame($originalOpportunityStatus, $opportunity->status);
            $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->status);
            $this->assertSame($originalPhone, $business->fresh()->phone);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
