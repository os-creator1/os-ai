<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 5 Slice 1 — admin authorization scaffold and read-only
 * Opportunity index only. No detail page, no run/candidate inspection, and
 * no admin mutation exists yet.
 */
class AdminOpportunityControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        config()->set('opportunity.enabled', true);
    }

    public function test_guest_cannot_access_the_index(): void
    {
        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_the_index(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    /**
     * Direct regression guard, mirroring AdminBusinessControllerTest's own
     * identical case: a non-admin account (users.is_admin = false) must be
     * blocked even when its session happens to carry the exact permission
     * strings the feature-level check looks for.
     */
    public function test_non_admin_is_blocked_even_with_matching_permission_strings_in_session(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business);
        $this->withSession(['permissions' => collect(['access backend', 'view opportunities'])]);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_without_backend_access_is_blocked(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_view_opportunities_permission_is_blocked(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.opportunities.index'))->assertUnauthorized();
    }

    public function test_admin_with_view_opportunities_can_access_the_index(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Add your business phone number']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.index'))
            ->assertOk()
            ->assertSee('Add your business phone number');
    }

    public function test_feature_disabled_returns_404_for_an_otherwise_authorized_admin(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);
        config()->set('opportunity.enabled', false);

        $this->get(route('admin.opportunities.index'))->assertNotFound();
    }

    public function test_authorized_admin_sees_opportunities_belonging_to_different_customers(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Business A Opportunity']);
        $this->createOpportunity($businessB, ['title' => 'Business B Opportunity']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee('Business A Opportunity');
        $response->assertSee('Business B Opportunity');
    }

    /**
     * OpportunityRepository::paginateForAdmin() does not eager-load the
     * business/customer relationship (see the completion report), so this
     * slice renders the already-loaded, native business_id column as the
     * interim, N+1-safe ownership indicator rather than a resolved
     * business/customer name. This proves that indicator renders correctly
     * and distinctly per row without crashing across differing tenants.
     */
    public function test_opportunity_business_id_is_rendered_safely_as_the_interim_ownership_indicator(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Owned By A']);
        $this->createOpportunity($businessB, ['title' => 'Owned By B']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee((string) $businessA->id);
        $response->assertSee((string) $businessB->id);
    }

    public function test_status_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Open Fixture', 'status' => OpportunityStatus::Open->value]);
        $this->createOpportunity($business, ['title' => 'Dismissed Fixture', 'status' => OpportunityStatus::Dismissed->value]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'open']));

        $response->assertOk();
        $response->assertSee('Open Fixture');
        $response->assertDontSee('Dismissed Fixture');
    }

    public function test_freshness_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Current Fixture', 'freshness' => 'current']);
        $this->createOpportunity($business, ['title' => 'Stale Fixture', 'freshness' => 'stale']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['freshness' => 'stale']));

        $response->assertOk();
        $response->assertSee('Stale Fixture');
        $response->assertDontSee('Current Fixture');
    }

    public function test_worker_key_filter_is_applied(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunity($business, ['title' => 'Business Advisor Fixture', 'worker_key' => 'business_advisor']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['worker_key' => 'business_advisor']));

        $response->assertOk();
        $response->assertSee('Business Advisor Fixture');
    }

    public function test_business_id_filter_is_applied(): void
    {
        $businessA = $this->createBusinessForOpportunities();
        $businessB = $this->createBusinessForOpportunities();
        $this->createOpportunity($businessA, ['title' => 'Matching Business Fixture']);
        $this->createOpportunity($businessB, ['title' => 'Other Business Fixture']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['business_id' => $businessA->id]));

        $response->assertOk();
        $response->assertSee('Matching Business Fixture');
        $response->assertDontSee('Other Business Fixture');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'not-a-real-status']));

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
    }

    public function test_invalid_business_id_filter_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['business_id' => 'not-an-integer']));

        $response->assertRedirect();
        $response->assertSessionHasErrors('business_id');
    }

    public function test_pagination_is_deterministic_and_preserves_filters(): void
    {
        $business = $this->createBusinessForOpportunities();

        for ($i = 0; $i < 101; $i++) {
            $this->createOpportunity($business, [
                'title' => 'Bulk Opportunity ' . $i,
                'status' => OpportunityStatus::Open->value,
                'fingerprint' => hash('sha256', 'admin-bulk-' . $i),
            ]);
        }

        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index', ['status' => 'open']));
        $body = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('status=open', $body);
    }

    public function test_get_performs_no_mutation_and_dispatches_nothing(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business, ['status' => OpportunityStatus::Open->value]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $statusBefore = $opportunity->status;
        $freshnessBefore = $opportunity->freshness;
        $priorityScoreBefore = $opportunity->priority_score;
        $occurrenceNumberBefore = $opportunity->occurrence_number;
        $updatedAtBefore = $opportunity->updated_at;
        $transitionCountBefore = OpportunityTransition::where('opportunity_id', $opportunity->id)->count();
        $executionCountBefore = OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count();

        Queue::fake();

        $this->get(route('admin.opportunities.index'))->assertOk();

        $fresh = $opportunity->fresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertSame($freshnessBefore, $fresh->freshness);
        $this->assertSame($priorityScoreBefore, $fresh->priority_score);
        $this->assertSame($occurrenceNumberBefore, $fresh->occurrence_number);
        $this->assertTrue($updatedAtBefore->equalTo($fresh->updated_at));
        $this->assertSame($transitionCountBefore, OpportunityTransition::where('opportunity_id', $opportunity->id)->count());
        $this->assertSame($executionCountBefore, OpportunityActionExecution::where('opportunity_id', $opportunity->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_the_page_does_not_expose_raw_internal_identifiers(): void
    {
        $business = $this->createBusinessForOpportunities();
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
        $key = hash('sha256', 'do-not-leak-this-idempotency-key');
        $this->createOpportunityActionExecution($opportunity, $user, ['idempotency_key' => $key]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertDontSee($opportunity->recommended_action_hash);
        $response->assertDontSee($key);
        $response->assertDontSee('+15551234567');
        $response->assertDontSee('"action_key"', false);
    }

    public function test_no_admin_detail_or_mutation_route_exists_yet(): void
    {
        $this->assertFalse(Route::has('admin.opportunities.show'));
        $this->assertFalse(Route::has('admin.opportunities.snooze'));
        $this->assertFalse(Route::has('admin.opportunities.dismiss'));
        $this->assertFalse(Route::has('admin.opportunities.reopen'));
        $this->assertFalse(Route::has('admin.opportunities.runs.index'));
        $this->assertFalse(Route::has('admin.opportunities.runs.show'));
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Both the admin route group (ValidProduct) and the customer route
     * group render the shared layout, which unconditionally reads the
     * 'license' and 'custom_script' app_config rows (mirrors
     * AdminBusinessControllerTest's own identical helper).
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
