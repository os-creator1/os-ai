<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Events\Opportunity\OpportunityApprovalRequested;
use App\Events\Opportunity\OpportunityBecameCurrent;
use App\Events\Opportunity\OpportunityCreated;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityMarkedStale;
use App\Events\Opportunity\OpportunityReaffirmed;
use App\Events\Opportunity\OpportunityReopened;
use App\Events\Opportunity\OpportunityRunFailed;
use App\Events\Opportunity\OpportunityRunStarted;
use App\Events\Opportunity\OpportunityRunSucceeded;
use App\Events\Opportunity\OpportunitySnoozed;
use App\Library\Business\BusinessManager;
use App\Library\Business\UrlNormalizer;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityActionVerificationException;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Phase 4B.2C.1 note: registry-metadata failure scenarios (incorrect
 * handler_identifier, incorrect verifier_identifier, mutates_business_data
 * =false, approval_required=false, an unsupported completion_policy in the
 * trusted registry) are intentionally omitted. OpportunityActionRegistry is
 * a closed, `final`, all-static, source-controlled class (RFC-002 §13.1);
 * even though OpportunityActionExecutor accepts it as a constructor
 * dependency, its `get()` lookup is called via late static binding on the
 * class itself, which cannot be substituted (the class cannot be
 * subclassed or mocked). There is no seam to alter registry metadata
 * without either modifying production registry data or adding a new
 * indirection layer, both out of scope for this phase.
 *
 * "Missing owning Business" is also omitted: opportunities.business_id is a
 * required, cascading foreign key, so an Opportunity can never legitimately
 * exist without a resolvable Business row. Only the "missing owning
 * Customer" ownership failure (an orphaned business.customer_id) is
 * realistically testable.
 */
class OpportunityActionExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const KNOWN_OPPORTUNITY_EVENTS = [
        OpportunityApprovalRequested::class,
        OpportunityBecameCurrent::class,
        OpportunityCreated::class,
        OpportunityDismissed::class,
        OpportunityMarkedStale::class,
        OpportunityReaffirmed::class,
        OpportunityReopened::class,
        OpportunityRunFailed::class,
        OpportunityRunStarted::class,
        OpportunityRunSucceeded::class,
        OpportunitySnoozed::class,
    ];

    public function test_configured_add_phone_updates_business_phone_through_real_business_manager(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertSame('+15551234567', $business->fresh()->phone);
    }

    public function test_the_exact_configured_phone_string_is_preserved(): void
    {
        $business = $this->createBusinessForOpportunities();
        $rawValue = ' +1 555 999 8888 ';
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, $rawValue);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertSame($rawValue, $business->fresh()->phone);
    }

    public function test_successful_execution_returns_the_exact_fixed_safe_summary(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $summary = $executor->execute($opportunity, $execution);

        $this->assertSame('Business phone updated and verified.', $summary);
    }

    public function test_opportunity_remains_in_progress(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
    }

    public function test_execution_remains_running(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertSame(OpportunityActionExecutionStatus::Running, $execution->fresh()->status);
    }

    public function test_no_opportunity_transition_is_created(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_no_event_or_job_is_dispatched(): void
    {
        Event::fake(self::KNOWN_OPPORTUNITY_EVENTS);
        Queue::fake();

        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        foreach (self::KNOWN_OPPORTUNITY_EVENTS as $eventClass) {
            Event::assertNotDispatched($eventClass);
        }

        Queue::assertNothingPushed();
    }

    public function test_unsupported_action_key_rejects_before_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['action_key'] = 'add_email';

        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [
            'recommended_action' => $recommendedAction,
        ], [
            'action_key' => 'add_email',
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_opportunity_not_in_progress_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [
            'status' => OpportunityStatus::Open->value,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_execution_not_running_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [], [
            'status' => OpportunityActionExecutionStatus::Pending->value,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_execution_belonging_to_another_opportunity_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity] = $this->inProgressOpportunityWithExecution($business);
        [, $otherExecution] = $this->inProgressOpportunityWithExecution($business);

        $this->assertRejectsWithoutMutation($opportunity, $otherExecution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_action_hash_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [
            'recommended_action_hash' => hash('sha256', 'does-not-match'),
        ], [
            'recommended_action_hash' => hash('sha256', 'does-not-match'),
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_execution_recommended_action_hash_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [], [
            'recommended_action_hash' => hash('sha256', 'stale-execution-hash'),
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_schema_version_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [], [
            'action_schema_version' => 2,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_occurrence_number_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [], [
            'occurrence_number' => 2,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_execution_completion_policy_mismatch_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [], [
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested->value,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_missing_parameters_value_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['parameters'] = ['other' => 'x'];
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ], [
            'recommended_action_hash' => $hash,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_blank_value_rejects(): void
    {
        $this->assertMalformedValueRejects('   ');
    }

    public function test_non_string_value_rejects(): void
    {
        $this->assertMalformedValueRejects(5551234567);
    }

    public function test_value_longer_than_50_characters_rejects(): void
    {
        $this->assertMalformedValueRejects(str_repeat('1', 51));
    }

    public function test_unexpected_parameter_key_rejects(): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction();
        $recommendedAction['parameters']['label'] = 'mobile';
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, '+15551234567', [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ], [
            'recommended_action_hash' => $hash,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    public function test_supports_returns_true_for_the_exact_add_phone_capability_mapping(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = OpportunityActionRegistry::get('add_phone');

        $this->assertTrue($executor->supports('add_phone', $definition));
    }

    public function test_supports_returns_false_for_another_action_key(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = OpportunityActionRegistry::get('add_phone');

        $this->assertFalse($executor->supports('add_email', $definition));
    }

    public function test_supports_returns_false_for_another_handler_identifier(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = array_merge(OpportunityActionRegistry::get('add_phone'), [
            'handler_identifier' => 'business.update_email',
        ]);

        $this->assertFalse($executor->supports('add_phone', $definition));
    }

    public function test_supports_returns_false_for_another_verifier_identifier(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = array_merge(OpportunityActionRegistry::get('add_phone'), [
            'verifier_identifier' => 'business.email_matches_parameter',
        ]);

        $this->assertFalse($executor->supports('add_phone', $definition));
    }

    public function test_supports_returns_false_for_another_completion_policy(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = array_merge(OpportunityActionRegistry::get('add_phone'), [
            'completion_policy' => OpportunityCompletionPolicy::CustomerAttested,
        ]);

        $this->assertFalse($executor->supports('add_phone', $definition));
    }

    public function test_supports_result_is_unaffected_by_approval_required(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = array_merge(OpportunityActionRegistry::get('add_phone'), [
            'approval_required' => false,
        ]);

        $this->assertTrue($executor->supports('add_phone', $definition));
    }

    public function test_supports_result_is_unaffected_by_mutates_business_data(): void
    {
        $executor = app(OpportunityActionExecutor::class);
        $definition = array_merge(OpportunityActionRegistry::get('add_phone'), [
            'mutates_business_data' => false,
        ]);

        $this->assertTrue($executor->supports('add_phone', $definition));
    }

    public function test_supports_performs_no_business_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        $originalPhone = $business->phone;
        $executor = app(OpportunityActionExecutor::class);
        $definition = OpportunityActionRegistry::get('add_phone');

        $executor->supports('add_phone', $definition);

        $this->assertSame($originalPhone, $business->fresh()->phone);
    }

    public function test_missing_owning_customer_rejects_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);

        // businesses.customer_id has a real foreign key to users.id, so this
        // must be an existing User with no matching Customer row (rather
        // than an arbitrary nonexistent id) to produce a genuine orphan
        // without violating that constraint.
        $orphanUser = $this->createUser();
        $business->customer_id = $orphanUser->id;
        $business->save();

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    /**
     * A delegating BusinessManager subclass (not a full mock): every call
     * goes to the real, fully initialized BusinessManager, except the
     * supplied `phone` is swapped for a value the caller never configured
     * — proving the executor's post-mutation DB re-read genuinely detects
     * a mismatch rather than trusting BusinessManager's return value.
     */
    public function test_verification_failure_throws_opportunity_action_verification_exception(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);

        $doubleBusinessManager = new class(
            app(BusinessRepository::class),
            app(BusinessLocationRepository::class),
            app(BusinessServiceRepository::class),
            app(UrlNormalizer::class),
        ) extends BusinessManager {
            public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
            {
                return parent::updateBusiness($customer, $business, ['phone' => 'unexpected-unconfigured-value']);
            }
        };

        $this->app->instance(BusinessManager::class, $doubleBusinessManager);

        $executor = app(OpportunityActionExecutor::class);

        $this->expectException(OpportunityActionVerificationException::class);

        $executor->execute($opportunity, $execution);
    }

    public function test_verification_failure_does_not_change_opportunity_or_execution_state(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);

        $doubleBusinessManager = new class(
            app(BusinessRepository::class),
            app(BusinessLocationRepository::class),
            app(BusinessServiceRepository::class),
            app(UrlNormalizer::class),
        ) extends BusinessManager {
            public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
            {
                return parent::updateBusiness($customer, $business, ['phone' => 'unexpected-unconfigured-value']);
            }
        };

        $this->app->instance(BusinessManager::class, $doubleBusinessManager);

        $executor = app(OpportunityActionExecutor::class);

        try {
            $executor->execute($opportunity, $execution);
        } catch (OpportunityActionVerificationException) {
            // expected
        }

        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame(OpportunityActionExecutionStatus::Running, $execution->fresh()->status);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_the_executor_does_not_directly_persist_opportunity_or_execution_changes(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business);
        $originalOpportunityUpdatedAt = $opportunity->updated_at;
        $originalExecutionUpdatedAt = $execution->updated_at;
        $executor = app(OpportunityActionExecutor::class);

        $executor->execute($opportunity, $execution);

        $this->assertTrue($originalOpportunityUpdatedAt->equalTo($opportunity->fresh()->updated_at));
        $this->assertTrue($originalExecutionUpdatedAt->equalTo($execution->fresh()->updated_at));
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
    private function inProgressOpportunityWithExecution(
        Business $business,
        mixed $value = '+15551234567',
        array $opportunityOverrides = [],
        array $executionOverrides = []
    ): array {
        $recommendedAction = $opportunityOverrides['recommended_action'] ?? $this->validAddPhoneAction($value);
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
            'status' => OpportunityActionExecutionStatus::Running->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function assertMalformedValueRejects(mixed $value): void
    {
        $business = $this->createBusinessForOpportunities();
        $recommendedAction = $this->validAddPhoneAction($value);
        $hash = (new OpportunityActionHash())->compute($recommendedAction);

        [$opportunity, $execution] = $this->inProgressOpportunityWithExecution($business, $value, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => $hash,
        ], [
            'recommended_action_hash' => $hash,
        ]);

        $this->assertRejectsWithoutMutation($opportunity, $execution, $business, OpportunityActionNotExecutableException::class);
    }

    private function assertRejectsWithoutMutation(Opportunity $opportunity, OpportunityActionExecution $execution, Business $business, string $exceptionClass): void
    {
        $originalPhone = $business->phone;
        $executor = app(OpportunityActionExecutor::class);

        try {
            $this->expectException($exceptionClass);

            $executor->execute($opportunity, $execution);
        } finally {
            $this->assertSame($originalPhone, $business->fresh()->phone);
            $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        }
    }
}
