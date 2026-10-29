<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
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
 * Optional profile suggestions (Facebook / Instagram) are kept out of the
 * actionable queue and Home, shown in a clearly labelled optional section, and
 * the customer detail page is a plain "Recommendation" — with all backend
 * behaviour (dismiss, history, entitlement, tenancy) intact.
 */
class RecommendationsUxTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const FB_TITLE = 'Add your Facebook page';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_core_with_only_optional_suggestions_gets_a_caught_up_home_and_no_actionable_count(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');
        $this->suggestion($business, 'missing_instagram_url', 'add_instagram_url', 'Add your Instagram profile URL');

        $this->assertCount(0, app(OpportunityRepository::class)->topForCustomer($business, 20));

        $home = $this->get(route('user.home'))->assertOk();
        $home->assertDontSee('Add your Facebook page URL');
        $home->assertDontSee('Add your Instagram profile URL');
        $home->assertDontSee('data-move-kind="opportunity"', false);
    }

    public function test_operational_recommendation_wins_home_over_a_higher_priority_optional_one(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL', ['priority_score' => 99]);
        $phone = $this->createOpportunity($business, ['priority_score' => 10]);

        $top = app(OpportunityRepository::class)->topForCustomer($business, 20);
        $this->assertSame([$phone->id], $top->pluck('id')->all());

        $this->get(route('user.home'))->assertOk()
            ->assertSee(route('customer.opportunities.show', $phone->id), false)
            ->assertDontSee('Add your Facebook page URL');
    }

    public function test_list_separates_optional_improvements_from_recommendations(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $fb = $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');
        $phone = $this->createOpportunity($business);

        $page = $this->get(route('customer.opportunities.index'))->assertOk();
        $html = $page->getContent();

        $page->assertSee('Optional improvements');
        $optionalAt = strpos($html, 'id="optional-improvements"');
        $this->assertNotFalse($optionalAt);
        $this->assertLessThan($optionalAt, strpos($html, route('customer.opportunities.show', $phone->id)));
        $this->assertGreaterThan($optionalAt, strpos($html, route('customer.opportunities.show', $fb->id)));

        $paginated = app(OpportunityRepository::class)->paginateForCustomer($business, ['suggestions' => 'exclude']);
        $this->assertSame([$phone->id], collect($paginated->items())->pluck('id')->all());
    }

    public function test_business_profile_points_to_optional_improvements_only_when_there_are_some(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);

        $this->get(route('customer.business.edit'))->assertOk()->assertDontSee('optional-suggestions', false);

        $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');

        $this->get(route('customer.business.edit'))->assertOk()
            ->assertSee('1 optional improvement is available')
            ->assertSee(route('customer.opportunities.index'), false);
    }

    public function test_detail_page_is_a_clean_recommendation_with_one_cta_for_an_optional_suggestion(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $fb = $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');

        $page = $this->get(route('customer.opportunities.show', $fb->id))->assertOk();

        $page->assertSee('<title>', false);
        $page->assertSee(self::FB_TITLE);
        $page->assertSee('Make it easier for customers to find and recognize your business by linking your Facebook page.');
        $page->assertSee('Optional improvement');
        $page->assertSee('this does not affect your CRM setup');
        $page->assertSee('Open business profile');
        $page->assertSee('Back to recommendations');
        $page->assertSee('Dismiss recommendation');
        $this->assertSame(1, substr_count($page->getContent(), 'data-role="primary-cta"'));

        foreach (['Opportunity details', 'Back to opportunities', 'Freshness', 'Last scored', 'First detected', 'Completion policy', 'System verified', 'Supporting evidence', 'Latest execution', 'Request approval', 'Confirm and start'] as $technical) {
            $page->assertDontSee($technical);
        }
    }

    public function test_dismiss_removes_it_from_the_lists_but_preserves_the_record(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $fb = $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');

        $this->post(route('customer.opportunities.dismiss', $fb->id))->assertRedirect();

        $this->assertDatabaseHas('opportunities', ['id' => $fb->id, 'status' => 'dismissed']);
        $this->assertNotNull($fb->fresh()->dismissed_at);
        $this->assertDatabaseHas('opportunity_transitions', ['opportunity_id' => $fb->id]);

        $this->get(route('customer.business.edit'))->assertOk()->assertDontSee('optional-suggestions', false);
        $this->get(route('customer.opportunities.show', $fb->id))->assertOk()->assertSee('Reopen recommendation')->assertDontSee('Dismiss recommendation');
    }

    public function test_crm_sidebar_opportunities_still_points_to_the_sales_pipeline(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $crmUrl = route('customer.workspaces.businesses.crm.board', [$business->workspace->uid, $business->uid]);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString($crmUrl, $html);
        $this->assertStringNotContainsString('href="' . route('customer.opportunities.index') . '"', $this->sidebar($html));
    }

    public function test_growth_keeps_gbp_as_an_actionable_recommendation_and_optional_ones_stay_optional(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Growth);
        $gbp = $this->createOpportunity($business, ['type' => 'missing_gbp_url', 'priority_score' => 40, 'recommended_action' => ['action_key' => 'add_gbp_url', 'parameters' => [], 'completion_policy' => 'customer_attested']]);
        $this->suggestion($business, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL', ['priority_score' => 99]);

        $this->assertSame([$gbp->id], app(OpportunityRepository::class)->topForCustomer($business, 20)->pluck('id')->all());

        $this->changePlan($business, WorkspacePlanTier::Core);
        $this->assertCount(0, app(OpportunityRepository::class)->topForCustomer($business->fresh(), 20));
    }

    public function test_another_tenants_optional_suggestion_is_not_found_and_not_counted(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $other = $this->createBusinessForOpportunities();
        $foreign = $this->suggestion($other, 'missing_facebook_url', 'add_facebook_url', 'Add your Facebook page URL');

        $this->get(route('customer.opportunities.show', $foreign->id))->assertNotFound();
        $this->assertSame(0, app(OpportunityRepository::class)->optionalSuggestionCountForCustomer($business));
    }

    public function test_executable_add_phone_flow_is_unchanged(): void
    {
        $business = $this->actingAsCustomerWithBusiness(WorkspacePlanTier::Core);
        $phone = $this->createOpportunity($business, ['recommended_action' => ['schema_version' => 1, 'action_key' => 'add_phone', 'parameters' => [], 'approval_required' => true, 'completion_policy' => 'system_verified']]);

        $page = $this->get(route('customer.opportunities.show', $phone->id))->assertOk();
        $page->assertSee('Save phone number');
        $page->assertDontSee('Optional improvement');
        $page->assertDontSee('data-role="primary-cta"', false);
    }

    private function sidebar(string $html): string
    {
        $start = strpos($html, 'main-menu');
        $end = $start === false ? false : strpos($html, '</nav>', $start);

        return $start === false ? $html : substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function suggestion(Business $business, string $type, string $action, string $title, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'type' => $type,
            'title' => $title,
            'priority_score' => 20,
            'evidence' => [str_replace('missing_', '', $type) . '_blank' => true],
            'recommended_action' => ['action_key' => $action, 'parameters' => [], 'completion_policy' => 'customer_attested'],
        ], $overrides));
    }

    private function changePlan(Business $business, WorkspacePlanTier $tier): void
    {
        $admin = User::query()->where('is_admin', true)->firstOrFail();

        app(EntitlementManager::class)->changePlan($business->workspace()->first(), $tier, (int) $admin->id, 'UX test.');
    }

    private function actingAsCustomerWithBusiness(WorkspacePlanTier $tier): Business
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();
        in_array('license', $existing, true) || AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        in_array('custom_script', $existing, true) || AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        in_array('customer_permissions', $existing, true) || AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));

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
}
