<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Opportunity\BusinessAdvisorOpportunityProducer;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * Plan entitlement is enforced on the Business Advisor queue at generation
 * AND read time. The Google Business Profile URL recommendation belongs to
 * the GBP module (Growth/Agency); a CRM/Core Business never sees it as a
 * required move, however it was generated, while CRM-included
 * recommendations stay. Persisted rows are hidden, never deleted.
 */
class OpportunityPlanEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const GBP_ACTION = ['action_key' => 'add_gbp_url', 'parameters' => [], 'completion_policy' => 'customer_attested'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_core_business_never_lists_or_opens_the_gbp_recommendation_but_keeps_crm_ones(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $gbp = $this->gbpOpportunity($business);
        $phone = $this->createOpportunity($business, ['priority_score' => 10]);

        $this->get(route('customer.opportunities.index'))
            ->assertOk()
            ->assertDontSee(route('customer.opportunities.show', $gbp->id), false)
            ->assertSee(route('customer.opportunities.show', $phone->id), false);

        $this->get(route('customer.opportunities.show', $gbp->id))->assertNotFound();
        $this->get(route('customer.opportunities.execution-status', $gbp->id))->assertNotFound();
        $this->post(route('customer.opportunities.dismiss', $gbp->id))->assertNotFound();
        $this->get(route('customer.opportunities.show', $phone->id))->assertOk();
    }

    public function test_core_home_next_best_move_skips_the_higher_priority_gbp_row_and_counts_only_eligible_rows(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $this->gbpOpportunity($business);
        $phone = $this->createOpportunity($business, ['priority_score' => 10]);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertDontSee('Add your Google Business Profile URL');
        $response->assertSee(route('customer.opportunities.show', $phone->id), false);

        $top = app(OpportunityRepository::class)->topForCustomer($business, 20);
        $this->assertSame([$phone->id], $top->pluck('id')->all());
    }

    public function test_core_home_with_only_a_gbp_row_has_nothing_that_needs_the_customer(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $this->gbpOpportunity($business);

        $this->get(route('user.home'))
            ->assertOk()
            ->assertDontSee('Add your Google Business Profile URL');

        $this->assertCount(0, app(OpportunityRepository::class)->topForCustomer($business, 20));
    }

    public function test_growth_business_still_gets_the_gbp_recommendation_with_a_real_action_and_destination(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Growth);
        $gbp = $this->gbpOpportunity($business);

        $this->get(route('customer.opportunities.index'))
            ->assertOk()
            ->assertSee(route('customer.opportunities.show', $gbp->id), false);

        $this->get(route('customer.opportunities.show', $gbp->id))
            ->assertOk()
            ->assertSee('Add your Google Business Profile URL')
            ->assertSee('This is a recommendation only')
            ->assertSee('href="' . route('customer.business.edit') . '"', false);
    }

    public function test_downgrade_hides_a_previously_generated_row_without_deleting_it_and_upgrade_restores_it(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Growth);
        $gbp = $this->gbpOpportunity($business);

        $this->get(route('customer.opportunities.show', $gbp->id))->assertOk();

        $this->changePlan($business, WorkspacePlanTier::Core);

        $this->get(route('customer.opportunities.show', $gbp->id))->assertNotFound();
        $this->assertCount(0, app(OpportunityRepository::class)->topForCustomer($business->fresh(), 20));
        $this->assertDatabaseHas('opportunities', ['id' => $gbp->id, 'status' => 'open', 'freshness' => 'current']);

        $this->changePlan($business, WorkspacePlanTier::Growth);

        $this->get(route('customer.opportunities.show', $gbp->id))->assertOk();
    }

    public function test_another_tenants_opportunity_is_not_found_on_any_plan(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Growth);
        $other = $this->createBusinessForOpportunities();
        $foreign = $this->createOpportunity($other);

        $this->get(route('customer.opportunities.show', $foreign->id))->assertNotFound();
        $this->get(route('customer.opportunities.index'))
            ->assertOk()
            ->assertDontSee(route('customer.opportunities.show', $foreign->id), false);

        $ownGbp = $this->gbpOpportunity($business);
        $this->changePlan($business, WorkspacePlanTier::Core);
        $this->get(route('customer.opportunities.show', $ownGbp->id))->assertNotFound();
    }

    public function test_producer_does_not_generate_gbp_for_core_but_still_generates_crm_recommendations(): void
    {
        $business = $this->createBusinessForOpportunities();
        $business->update(['phone' => null, 'google_business_profile_url' => null]);

        $this->assertContains('missing_gbp_url', $this->producedTypes($business->fresh()));

        $this->changePlan($business, WorkspacePlanTier::Core);

        $types = $this->producedTypes($business->fresh());
        $this->assertNotContains('missing_gbp_url', $types);
        $this->assertContains('missing_phone', $types);
    }

    public function test_snapshot_findings_omit_gbp_for_core_and_keep_it_for_growth(): void
    {
        $business = $this->createBusinessForOpportunities();
        $business->update(['google_business_profile_url' => null]);

        $titles = fn () => array_column(app(InitialBusinessSnapshotBuilder::class)->build($business->fresh())['findings'], 'fingerprint');

        $this->assertContains("business:{$business->id}:missing_gbp_url", $titles());

        $this->changePlan($business, WorkspacePlanTier::Core);

        $this->assertNotContains("business:{$business->id}:missing_gbp_url", $titles());
    }

    public function test_action_labels_never_fall_back_to_the_placeholder(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);

        $email = $this->createOpportunity($business, [
            'type' => 'missing_email',
            'recommended_action' => ['action_key' => 'add_email', 'parameters' => [], 'completion_policy' => 'customer_attested'],
        ]);
        $this->get(route('customer.opportunities.show', $email->id))
            ->assertOk()
            ->assertSee('Add your public business email')
            ->assertSee('href="' . route('customer.business.edit') . '"', false);

        $unknown = $this->createOpportunity($business, [
            'recommended_action' => ['action_key' => 'something_unmapped', 'parameters' => [], 'completion_policy' => 'customer_attested'],
        ]);
        $this->get(route('customer.opportunities.show', $unknown->id))
            ->assertOk()
            ->assertSee('There is no action to take for this recommendation yet.')
            ->assertDontSee('<p class="mb-1">Recommended action</p>', false);

        $none = $this->createOpportunity($business, ['recommended_action' => null]);
        $this->get(route('customer.opportunities.show', $none->id))
            ->assertOk()
            ->assertDontSee('<p class="mb-1">Recommended action</p>', false);
    }

    private function gbpOpportunity(Business $business): Opportunity
    {
        return $this->createOpportunity($business, [
            'type' => 'missing_gbp_url',
            'title' => 'Add your Google Business Profile URL',
            'priority_score' => 90,
            'evidence' => ['gbp_url_blank' => true],
            'recommended_action' => self::GBP_ACTION,
        ]);
    }

    /** @return array<int, string> */
    private function producedTypes(Business $business): array
    {
        return array_map(
            fn ($candidate) => $candidate->type,
            iterator_to_array(app(BusinessAdvisorOpportunityProducer::class)->produce($business), false),
        );
    }

    private function changePlan(Business $business, WorkspacePlanTier $tier): void
    {
        $admin = User::query()->where('is_admin', true)->firstOrFail();

        app(EntitlementManager::class)->changePlan($business->workspace()->first(), $tier, (int) $admin->id, 'Plan eligibility test.');
    }

    private function actingAsCustomerWithBusiness(WorkspacePlanTier $tier): Business
    {
        $this->ensureRequiredAppConfigRowsExist();

        $business = app(BusinessRepository::class)->updateStatus($this->createBusinessForOpportunities(), BusinessStatus::Active);

        if ($tier !== WorkspacePlanTier::Growth) {
            $this->changePlan($business, $tier);
        }

        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        // Keep Home on the recommendation move rather than the calendar setup journey.
        $location = \App\Models\BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $typeId = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => $location->id, 'name' => 'Consultation', 'duration_minutes' => 30, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('booking_type_staff')->insert(['booking_type_id' => $typeId, 'staff_user_id' => (int) $business->customer_id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('staff_availability_rules')->insert([
            'business_location_id' => $location->id, 'staff_user_id' => (int) $business->customer_id, 'day_of_week' => 1,
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $business;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }
}
