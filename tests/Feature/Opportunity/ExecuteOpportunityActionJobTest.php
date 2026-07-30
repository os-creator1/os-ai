<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Business\BusinessManager;
use App\Library\Business\UrlNormalizer;
use App\Library\Workspace\WorkspaceManager;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class ExecuteOpportunityActionJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_handle_while_disabled_is_a_safe_no_op(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $originalPhone = $business->phone;

        config()->set('opportunity.enabled', false);
        Log::spy();
        Event::fake([OpportunityExecutionSucceeded::class, OpportunityExecutionFailed::class, OpportunityCompleted::class]);

        $job = new ExecuteOpportunityAction($execution->id);
        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());

        Log::shouldHaveReceived('info')->once();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        Event::assertNotDispatched(OpportunityExecutionSucceeded::class);
        Event::assertNotDispatched(OpportunityExecutionFailed::class);
        Event::assertNotDispatched(OpportunityCompleted::class);
    }

    public function test_missing_execution_is_a_safe_no_op(): void
    {
        $job = new ExecuteOpportunityAction(999999999);

        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame(0, OpportunityTransition::count());
    }

    public function test_redelivery_of_a_running_execution_is_a_safe_no_op(): void
    {
        $this->assertRedeliveryIsNoOp(OpportunityActionExecutionStatus::Running);
    }

    public function test_redelivery_of_a_succeeded_execution_is_a_safe_no_op(): void
    {
        $this->assertRedeliveryIsNoOp(OpportunityActionExecutionStatus::Succeeded);
    }

    public function test_redelivery_of_a_failed_execution_is_a_safe_no_op(): void
    {
        $this->assertRedeliveryIsNoOp(OpportunityActionExecutionStatus::Failed);
    }

    public function test_valid_pending_execution_succeeds_end_to_end(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $job = new ExecuteOpportunityAction($execution->id);

        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame('+15551234567', $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
        $this->assertSame('Business phone updated and verified.', $execution->fresh()->safe_result_summary);
        $this->assertSame(OpportunityStatus::Completed, $opportunity->fresh()->status);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_pre_invocation_mismatch_is_recorded_as_a_state_mismatch_failure(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'action_key' => 'add_email',
        ]);
        $originalPhone = $business->phone;
        $job = new ExecuteOpportunityAction($execution->id);

        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertSame('Opportunity action state no longer matched the approved action.', $execution->fresh()->safe_error_summary);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    /**
     * A delegating BusinessManager subclass (not a full mock): the real
     * BusinessManager::updateBusiness() genuinely runs and writes a phone
     * value the caller never configured, proving the executor's
     * verification step detects the mismatch — and that the nested
     * transaction/savepoint around executor->execute() genuinely rolls
     * that already-applied write back before the failure is recorded.
     */
    public function test_verification_failure_is_recorded_and_rolls_back_the_mutation(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $originalPhone = $business->phone;

        $doubleBusinessManager = new class(
            app(BusinessRepository::class),
            app(BusinessLocationRepository::class),
            app(BusinessServiceRepository::class),
            app(UrlNormalizer::class),
            app(WorkspaceManager::class),
        ) extends BusinessManager {
            public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
            {
                return parent::updateBusiness($customer, $business, ['phone' => 'unexpected-unconfigured-value']);
            }
        };

        $this->app->instance(BusinessManager::class, $doubleBusinessManager);

        $manager = app(OpportunityManager::class);
        $executor = app(OpportunityActionExecutor::class);
        $job = new ExecuteOpportunityAction($execution->id);

        $job->handle($manager, $executor);

        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertSame('Business update could not be verified.', $execution->fresh()->safe_error_summary);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    public function test_generic_handler_exception_is_recorded_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $originalPhone = $business->phone;

        $doubleBusinessManager = new class(
            app(BusinessRepository::class),
            app(BusinessLocationRepository::class),
            app(BusinessServiceRepository::class),
            app(UrlNormalizer::class),
            app(WorkspaceManager::class),
        ) extends BusinessManager {
            public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
            {
                throw new RuntimeException('Simulated infrastructure failure.');
            }
        };

        $this->app->instance(BusinessManager::class, $doubleBusinessManager);

        $manager = app(OpportunityManager::class);
        $executor = app(OpportunityActionExecutor::class);
        $job = new ExecuteOpportunityAction($execution->id);

        $job->handle($manager, $executor);

        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertSame('Opportunity action could not be completed.', $execution->fresh()->safe_error_summary);
        $this->assertSame(OpportunityStatus::Open, $opportunity->fresh()->status);
    }

    /**
     * A delegating OpportunityTransitionRepository (not a partial mock):
     * every method except create() delegates to the real repository;
     * create() is forced to throw once the terminal write is attempted
     * after a genuinely successful Business mutation, proving the outer
     * transaction rolls back the pending→running transition and the
     * already-applied mutation together, and that the job rethrows for
     * the queue's retry mechanism.
     */
    public function test_terminal_persistence_failure_rolls_back_the_entire_attempt_and_rethrows(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business);
        $originalPhone = $business->phone;

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
        $executor = app(OpportunityActionExecutor::class);
        $job = new ExecuteOpportunityAction($execution->id);

        $caught = null;

        try {
            $job->handle($manager, $executor);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Forced rollback.', $caught->getMessage());

        $this->assertSame(OpportunityActionExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }

    /**
     * @return array{0: Opportunity, 1: OpportunityActionExecution}
     */
    private function pendingOpportunityWithExecution(
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
            'status' => OpportunityActionExecutionStatus::Pending->value,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ], $executionOverrides));

        return [$opportunity, $execution];
    }

    private function assertRedeliveryIsNoOp(OpportunityActionExecutionStatus $status): void
    {
        $business = $this->createBusinessForOpportunities();
        [$opportunity, $execution] = $this->pendingOpportunityWithExecution($business, [], [
            'status' => $status->value,
        ]);
        $originalPhone = $business->phone;
        $job = new ExecuteOpportunityAction($execution->id);

        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame($status, $execution->fresh()->status);
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame($originalPhone, $business->fresh()->phone);
        $this->assertSame(0, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
    }
}
