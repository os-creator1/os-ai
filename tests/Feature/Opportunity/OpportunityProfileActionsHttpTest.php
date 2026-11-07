<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * add_email and add_description, end to end through the real customer routes and the real queued job:
 * open → configure → request approval → confirm → job runs → Business column persisted → verified completion, and a repeat
 * confirmation/execution cannot write again. Nothing here bypasses the approval or execution logic.
 */
class OpportunityProfileActionsHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const DESCRIPTION = 'We rent photo booths for weddings, birthdays and corporate events across the city, with on-site attendants and instant prints.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function actions(): array
    {
        return [
            'email' => ['add_email', 'email', 'hello@photobooths.example', 'Business email updated and verified.'],
            'description' => ['add_description', 'description', self::DESCRIPTION, 'Business description updated and verified.'],
        ];
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        foreach (['license' => 'test-license-key', 'custom_script' => ''] as $setting => $value) {
            if (! AppConfig::where('setting', $setting)->exists()) {
                AppConfig::create(['setting' => $setting, 'value' => $value]);
            }
        }

        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->actingAs($customer->user);

        return $business;
    }

    private function openOpportunity(Business $business, string $actionKey): Opportunity
    {
        $action = ['schema_version' => 1, 'action_key' => $actionKey, 'parameters' => [], 'approval_required' => true, 'completion_policy' => 'system_verified'];

        return $this->createOpportunity($business, [
            'status' => OpportunityStatus::Open->value,
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);
    }

    private function runQueuedJob(): void
    {
        $job = Queue::pushed(ExecuteOpportunityAction::class)->first();
        $this->assertNotNull($job, 'The confirmation queued the real execution job.');
        $job->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));
    }

    /**
     * @dataProvider actions
     */
    public function test_the_complete_customer_workflow_persists_the_approved_value_exactly_once(string $actionKey, string $column, string $value, string $summary): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->openOpportunity($business, $actionKey);
        $before = $business->fresh()->{$column};
        Queue::fake();

        // 1-2. Open the opportunity and configure a valid value over HTTP.
        $this->get(route('customer.opportunities.show', $opportunity->id))->assertOk()->assertSee('On your profile now');
        $this->post(route('customer.opportunities.configure-action', $opportunity->id), ['value' => $value])->assertRedirect();
        $this->assertSame($value, $opportunity->fresh()->recommended_action['parameters']['value']);
        $this->assertSame($before, $business->fresh()->{$column}, 'Configuring writes nothing.');

        // 3. Requesting approval still writes nothing.
        $this->post(route('customer.opportunities.request-approval', $opportunity->id))->assertRedirect();
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);
        $this->assertSame($before, $business->fresh()->{$column});
        Queue::assertNothingPushed();

        // 4. Explicit confirmation queues exactly one execution — still no write until the job runs.
        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id))->assertRedirect();
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame($before, $business->fresh()->{$column});
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
        $execution = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->sole();

        // 5-7. The real job runs: the exact value is persisted and the execution and opportunity complete.
        $this->runQueuedJob();
        $this->assertSame($value, $business->fresh()->{$column});
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
        $this->assertSame($summary, $execution->fresh()->safe_result_summary);
        $this->assertSame(OpportunityStatus::Completed, $opportunity->fresh()->status);
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->where('reason_code', 'execution_succeeded')->count());

        // The detail page now shows the verified profile value.
        $this->get(route('customer.opportunities.show', $opportunity->id))->assertOk()->assertSee($value);

        // 8. A duplicate confirmation or a redelivered job cannot repeat the write.
        $business->forceFill([$column => 'changed-elsewhere'])->save();
        $this->post(route('customer.opportunities.confirm-approval', $opportunity->id));
        $this->runQueuedJob();
        $this->assertSame('changed-elsewhere', $business->fresh()->{$column}, 'A second run did not write again.');
        $this->assertSame(1, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame(1, OpportunityTransition::where('opportunity_id', $opportunity->id)->where('reason_code', 'execution_succeeded')->count());
    }

    public function test_invalid_values_are_rejected_over_http_and_nothing_changes(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $email = $this->openOpportunity($business, 'add_email');
        $description = $this->openOpportunity($business, 'add_description');

        $this->post(route('customer.opportunities.configure-action', $email->id), ['value' => 'not-an-email'])->assertSessionHasErrors('value');
        $this->post(route('customer.opportunities.configure-action', $description->id), ['value' => 'Too short.'])->assertSessionHasErrors('value');

        $this->assertSame([], $email->fresh()->recommended_action['parameters']);
        $this->assertSame([], $description->fresh()->recommended_action['parameters']);
        $this->assertNull($business->fresh()->email);
    }

    public function test_another_customers_opportunity_cannot_be_configured_approved_or_run(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        $stranger = $this->openOpportunity($strangerBusiness, 'add_email');
        Queue::fake();

        $this->post(route('customer.opportunities.configure-action', $stranger->id), ['value' => 'hello@photobooths.example'])->assertNotFound();
        $this->post(route('customer.opportunities.request-approval', $stranger->id))->assertNotFound();
        $this->post(route('customer.opportunities.confirm-approval', $stranger->id))->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame([], $stranger->fresh()->recommended_action['parameters']);
        $this->assertNull($strangerBusiness->fresh()->email);
    }
}
