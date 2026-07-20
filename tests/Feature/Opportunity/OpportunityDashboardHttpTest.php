<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 4 customer HTTP Slice 1B — the dashboard top-5
 * Opportunity panel only. No mutation, navigation, or polling behavior is
 * exercised here.
 */
class OpportunityDashboardHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_enabled_customer_with_business_sees_the_panel(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Add your business phone number']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('Opportunities');
        $response->assertSee('Add your business phone number');
    }

    public function test_panel_contains_at_most_exactly_5_rows_when_more_eligible_rows_exist(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        for ($i = 0; $i < 8; $i++) {
            $this->createOpportunity($business, [
                'title' => 'Panel Row ' . $i,
                'fingerprint' => hash('sha256', 'panel-limit-' . $i),
                'first_detected_at' => now()->addSeconds($i),
            ]);
        }

        $response = $this->get(route('user.home'));
        $response->assertOk();

        foreach (range(0, 4) as $i) {
            $response->assertSee('Panel Row ' . $i);
        }

        foreach (range(5, 7) as $i) {
            $response->assertDontSee('Panel Row ' . $i);
        }
    }

    public function test_ordering_matches_top_for_customer_result_order(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Low Priority Panel Item', 'priority_score' => 10]);
        $this->createOpportunity($business, ['title' => 'High Priority Panel Item', 'priority_score' => 90]);

        $response = $this->get(route('user.home'));
        $body = $response->getContent();

        $response->assertOk();
        $highPosition = strpos($body, 'High Priority Panel Item');
        $lowPosition = strpos($body, 'Low Priority Panel Item');

        $this->assertNotFalse($highPosition);
        $this->assertNotFalse($lowPosition);
        $this->assertLessThan($lowPosition, $highPosition);
    }

    public function test_stale_opportunities_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Stale Panel Item', 'freshness' => 'stale']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Stale Panel Item');
    }

    public function test_snoozed_opportunities_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Snoozed Panel Item', 'status' => 'snoozed']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Snoozed Panel Item');
    }

    public function test_completed_opportunities_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Completed Panel Item', 'status' => 'completed']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Completed Panel Item');
    }

    public function test_dismissed_opportunities_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Dismissed Panel Item', 'status' => 'dismissed']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Dismissed Panel Item');
    }

    public function test_open_opportunities_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Open Panel Item', 'status' => 'open']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('Open Panel Item');
    }

    public function test_awaiting_approval_opportunities_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Awaiting Approval Panel Item', 'status' => 'awaiting_approval']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('Awaiting Approval Panel Item');
    }

    public function test_in_progress_opportunities_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'In Progress Panel Item', 'status' => 'in_progress']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('In Progress Panel Item');
    }

    public function test_another_tenants_opportunities_never_appear(): void
    {
        $this->actingAsCustomerWithBusiness();
        $strangerBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunity($strangerBusiness, ['title' => 'Stranger Panel Item']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Stranger Panel Item');
    }

    public function test_each_row_links_to_the_owned_detail_route(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $opportunity = $this->createOpportunity($business, ['title' => 'Linked Panel Item']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee(route('customer.opportunities.show', $opportunity->id), false);
    }

    public function test_view_all_opportunities_links_to_the_queue(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee(route('customer.opportunities.index'), false);
    }

    public function test_no_eligible_opportunities_renders_the_neutral_empty_state(): void
    {
        $this->actingAsCustomerWithBusiness();

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('No opportunities are available right now.');
    }

    public function test_disabled_flag_hides_the_panel_while_dashboard_remains_200(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Should Be Hidden Panel Item']);
        config()->set('opportunity.enabled', false);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Should Be Hidden Panel Item');
        $response->assertDontSee(route('customer.opportunities.index'), false);
    }

    /**
     * Container-bound Mockery contract mocks — the only technique that
     * proves the controller branch was never reached, rather than inferring
     * it from absent titles (which a query that ran but matched nothing
     * would also satisfy).
     */
    public function test_disabled_flag_calls_neither_repository(): void
    {
        $this->actingAsCustomerWithBusiness();
        config()->set('opportunity.enabled', false);

        $businessRepository = Mockery::mock(BusinessRepository::class);
        $businessRepository->shouldNotReceive('findPrimaryByCustomer');
        $this->app->instance(BusinessRepository::class, $businessRepository);

        $opportunityRepository = Mockery::mock(OpportunityRepository::class);
        $opportunityRepository->shouldNotReceive('topForCustomer');
        $this->app->instance(OpportunityRepository::class, $opportunityRepository);

        $response = $this->get(route('user.home'));

        $response->assertOk();
    }

    public function test_customer_without_a_business_receives_dashboard_200_with_panel_absent(): void
    {
        $this->actingAsCustomerWithoutBusiness();

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee(route('customer.opportunities.index'), false);
    }

    public function test_opportunity_titles_are_escaped(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => '<script>alert(1)</script>']);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_action_keys_hashes_and_execution_ids_do_not_appear(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $recommendedAction = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => OpportunityCompletionPolicy::SystemVerified->value,
        ];
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => $recommendedAction,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($recommendedAction),
            'action_schema_version' => 1,
        ]);
        $user = $this->createUser();
        $this->createOpportunityActionExecution($opportunity, $user, [
            'idempotency_key' => hash('sha256', 'dashboard-panel-execution'),
            'status' => OpportunityActionExecutionStatus::Running->value,
        ]);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee($opportunity->recommended_action_hash);
        $response->assertDontSee('add_phone');
        $response->assertDontSee(hash('sha256', 'dashboard-panel-execution'));
    }

    public function test_existing_dashboard_content_remains_present_and_unaffected(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('id="sms-reports"', false);
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        $this->ensureRequiredAppConfigRowsExist();

        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
    }

    private function actingAsCustomerWithoutBusiness(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    /**
     * Seeds only the app_config rows the customer HTTP render path actually
     * reads (mirrors BusinessOnboardingHttpTest's own identical helper).
     */
    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
