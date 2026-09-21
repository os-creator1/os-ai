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
use App\Library\Opportunity\Exceptions\OpportunityRetryRequiresReapprovalException;
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

    public function test_failed_mutating_action_requires_fresh_approval_without_any_new_effect(): void
    {
        Event::fake([OpportunityExecutionStarted::class]);
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $failedExecution] = $this->openOpportunityWithFailedExecution($business);
        $originalPhone = $business->phone;
        $originalUpdatedAt = $failedExecution->updated_at;

        try {
            app(OpportunityManager::class)->retryFailedExecution($opportunity, $business->customer);
            $this->fail('A failed mutating action must not reuse its old approval.');
        } catch (OpportunityRetryRequiresReapprovalException $exception) {
            $this->assertSame('add_phone', $exception->actionKey());
        }

        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $this->assertNull($opportunity->fresh()->completed_at);
        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $failedExecution->fresh()->status);
        $this->assertTrue($originalUpdatedAt->equalTo($failedExecution->fresh()->updated_at));
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        Event::assertNotDispatched(OpportunityExecutionStarted::class);
        Queue::assertNothingPushed();
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
