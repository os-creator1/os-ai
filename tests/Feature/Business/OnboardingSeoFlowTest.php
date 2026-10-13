<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Enums\Website\WebsiteMode;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Library\Business\OnboardingManager;
use App\Library\Business\OnboardingServiceSuggestions;
use App\Library\Website\WebsiteModeManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\Website;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * The five-step onboarding (Business → Location → Services → Website → Finish)
 * and what it hands to the Website, SEO and Home modules: required vs optional
 * fields, conditional location modes, the services minimum, the three website
 * answers, legacy-row resolution, idempotency, ownership and entitlement.
 */
class OnboardingSeoFlowTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
    }

    // ── Step order ────────────────────────────────────────────────────────

    public function test_the_customer_facing_steps_are_exactly_business_location_services_website_finish(): void
    {
        $this->assertSame(
            ['business', 'location', 'services', 'website', 'finish'],
            array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::current()),
        );

        foreach ([OnboardingStep::Goals, OnboardingStep::Assets, OnboardingStep::Analysis, OnboardingStep::Results, OnboardingStep::Complete] as $legacy) {
            $this->assertTrue($legacy->isLegacy());
        }
    }

    public function test_a_new_customer_starts_at_business_and_sees_five_steps_without_the_removed_ones(): void
    {
        $this->actingAsHttpCustomer();

        $response = $this->get(route('customer.onboarding.show'))->assertOk();

        $response->assertSee('1. Business', false)->assertSee('5. Finish', false);
        $response->assertDontSee('Goals')->assertDontSee('Assets')->assertDontSee('Analysis')->assertDontSee('Results');
        $response->assertDontSee('profile completeness', false)->assertDontSee('Profile completeness', false);
        $response->assertSee('name="industry"', false);
        $response->assertDontSee('name="website_url"', false);
    }

    public function test_the_full_photobooth_service_area_flow_completes_and_lands_on_home(): void
    {
        Http::preventStrayRequests();
        Bus::fake();

        $customer = $this->actingAsHttpCustomer();

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $business = Business::where('customer_id', $customer->user_id)->firstOrFail();

        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $this->post(route('customer.onboarding.services.store'), [])->assertSessionHasErrors('services');

        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Photo Booth Rental', 'is_primary' => '1']],
            'suggested_services' => ['Glam photo booth'],
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'website']));

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->get(route('customer.onboarding.show', ['step' => 'finish']))
            ->assertOk()
            ->assertSee('Snap Booth Co')
            ->assertSee('Orange, CA, US')
            ->assertSee('Photo Booth Rental')
            ->assertSee('Glam photo booth')
            ->assertSee('Not set up yet')
            ->assertSee('Enter MotionGrove');

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->refresh()->status);
        $this->assertSame(OnboardingStatus::Completed, CustomerOnboarding::where('customer_id', $customer->user_id)->first()->status);
        $this->assertSame(1, $business->services()->where('is_primary', true)->count());
        $this->assertSame(2, $business->services()->count());
        $this->assertSame(0, Website::where('business_id', $business->id)->count(), 'Nothing is generated or published during onboarding.');
        Bus::assertNotDispatched(\App\Jobs\Business\BuildInitialBusinessSnapshot::class);
        Http::assertNothingSent();
    }

    // ── Business step ─────────────────────────────────────────────────────

    public function test_business_requires_name_and_industry_and_treats_the_rest_as_optional(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => '', 'industry' => '']))
            ->assertSessionHasErrors(['name', 'industry']);
        $this->assertSame(0, Business::count());

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'other']))
            ->assertSessionHasErrors('industry_other');

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes([
            'industry' => 'other',
            'industry_other' => 'Mobile mixology',
        ]))->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $business = Business::firstOrFail();
        $this->assertNull($business->email);
        $this->assertNull($business->phone);
        $this->assertNull($business->description);
        $this->assertSame('Mobile mixology', $business->industry_other);
    }

    public function test_the_business_step_never_writes_the_website_address_and_asks_no_goal_question(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $existing = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['website_url' => 'https://kept.example']));
        $onboarding = app(CustomerOnboardingRepository::class)->attachBusiness(
            app(CustomerOnboardingRepository::class)->startForCustomer($customer, true),
            $existing,
        );

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['website_url' => 'https://evil.example', 'primary_goals' => ['lead_generation']]));

        $this->assertSame('https://kept.example', $existing->refresh()->website_url);
        $this->assertNull($onboarding->refresh()->primary_goals);
    }

    public function test_going_back_shows_the_saved_business_details(): void
    {
        $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['description' => 'We bring the fun', 'email' => 'hello@snap.example']));

        $this->get(route('customer.onboarding.show', ['step' => 'business']))
            ->assertOk()
            ->assertSee('Snap Booth Co')
            ->assertSee('We bring the fun')
            ->assertSee('hello@snap.example');
    }

    public function test_a_validation_failure_keeps_what_the_customer_typed(): void
    {
        $this->actingAsHttpCustomer();

        $this->from(route('customer.onboarding.show', ['step' => 'business']))
            ->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Typed Name', 'industry' => 'nonsense']))
            ->assertSessionHasErrors('industry');

        $this->get(route('customer.onboarding.show', ['step' => 'business']))->assertSee('Typed Name');
    }

    // ── Location step ─────────────────────────────────────────────────────

    public function test_a_service_area_location_needs_only_city_region_and_country(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'service_area', 'country_code' => 'US'])
            ->assertSessionHasErrors(['city', 'region']);

        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation([
            'service_area_cities' => "Irvine\nAnaheim, Irvine\n",
            'service_radius_km' => '60',
        ]))->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $location = Business::where('customer_id', $customer->user_id)->first()->primaryLocation;
        $this->assertSame(BusinessServiceMode::ServiceArea, $location->service_mode);
        $this->assertNull($location->address_line_1);
        $this->assertNull($location->postal_code);
        $this->assertFalse($location->public_address, 'An address is never public unless the owner chooses it.');
        $this->assertSame(['Irvine', 'Anaheim'], $location->service_area_cities);
        $this->assertSame(60, $location->service_radius_km);
        $this->assertNull($location->latitude);
    }

    public function test_storefront_and_hybrid_require_a_street_address(): void
    {
        $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        foreach (['storefront', 'hybrid'] as $mode) {
            $this->post(route('customer.onboarding.location.store'), ['service_mode' => $mode, 'country_code' => 'US', 'city' => 'Austin', 'region' => 'TX'])
                ->assertSessionHasErrors('address_line_1');
        }

        $this->post(route('customer.onboarding.location.store'), [
            'service_mode' => 'hybrid', 'country_code' => 'US', 'city' => 'Austin', 'region' => 'TX', 'address_line_1' => '1 Main St',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));
    }

    public function test_an_online_only_business_needs_no_physical_place_but_still_has_one_primary_location(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'professional_services']));

        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'online', 'country_code' => 'GB'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(1, $business->locations()->where('is_primary', true)->count());
        $this->assertNull($business->primaryLocation->city);
    }

    public function test_repeating_the_location_step_updates_the_one_primary_location(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation(['city' => 'Irvine']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(1, $business->locations()->count());
        $this->assertSame('Irvine', $business->primaryLocation->city);
    }

    public function test_region_is_required_only_where_addresses_carry_one(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['country_code' => 'SG', 'timezone' => 'Asia/Singapore', 'currency_code' => 'SGD']));

        // A country with administrative regions still needs one for a service area ...
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'service_area', 'country_code' => 'US', 'city' => 'Orange'])
            ->assertSessionHasErrors('region');
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'service_area', 'country_code' => 'AU', 'city' => 'Sydney'])
            ->assertSessionHasErrors('region');

        // ... one without them continues on city + country.
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'service_area', 'country_code' => 'SG', 'city' => 'Singapore'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertNull($business->primaryLocation->region);
        $this->assertSame('SG', $business->primaryLocation->country_code);

        // A storefront still needs its street address and city everywhere, and a region where one applies.
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'storefront', 'country_code' => 'SG', 'city' => 'Singapore'])
            ->assertSessionHasErrors('address_line_1');
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'storefront', 'country_code' => 'SG', 'address_line_1' => '1 Raffles Pl'])
            ->assertSessionHasErrors('city');
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'storefront', 'country_code' => 'US', 'address_line_1' => '1 Main St', 'city' => 'Austin'])
            ->assertSessionHasErrors('region');
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'storefront', 'country_code' => 'SG', 'address_line_1' => '1 Raffles Pl', 'city' => 'Singapore'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_regionless_country_service_area_can_finish_onboarding(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['country_code' => 'SG', 'timezone' => 'Asia/Singapore', 'currency_code' => 'SGD']));
        $this->post(route('customer.onboarding.location.store'), ['service_mode' => 'service_area', 'country_code' => 'SG', 'city' => 'Singapore']);
        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => 'Photo Booth Rental']]]);
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);

        $this->get(route('customer.onboarding.show', ['step' => 'finish']))->assertOk()->assertSee('Singapore, SG')->assertSee('Enter MotionGrove');
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
        $this->assertSame(OnboardingStatus::Completed, CustomerOnboarding::where('customer_id', $customer->user_id)->first()->status);
    }

    public function test_changing_from_other_to_a_predefined_industry_clears_the_custom_niche(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'other', 'industry_other' => 'Mobile mixology']));
        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame('Mobile mixology', $business->industry_other);

        // The hidden field resubmits the stale value; the server discards it.
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'photo_booth_service', 'industry_other' => 'Mobile mixology']))
            ->assertSessionHasNoErrors();

        $business->refresh();
        $this->assertSame('photo_booth_service', $business->industry->value);
        $this->assertNull($business->industry_other);

        // Refresh and resume: nothing contradictory comes back.
        $html = $this->get(route('customer.onboarding.show', ['step' => 'business']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Mobile mixology', $html);
        $this->assertSame(1, Business::where('customer_id', $customer->user_id)->count());

        // And Other still works, and still requires its description.
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'other', 'industry_other' => '']))->assertSessionHasErrors('industry_other');
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['industry' => 'other', 'industry_other' => 'Mobile mixology']))->assertSessionHasNoErrors();
        $this->assertSame('Mobile mixology', $business->refresh()->industry_other);
    }

    public function test_completing_the_new_onboarding_stamps_the_readiness_policy_once_and_a_legacy_completion_gets_none(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);
        $this->assertNull($onboarding->refresh()->metadata['readiness_policy'] ?? null, 'Nothing is stamped before completion.');

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
        $this->post(route('customer.onboarding.complete'));

        $this->assertSame(OnboardingManager::READINESS_POLICY, $onboarding->refresh()->metadata['readiness_policy']);
        $this->assertSame('later', $onboarding->metadata['website']['choice'], 'Existing metadata is preserved.');
        $this->assertSame(
            OnboardingManager::READINESS_POLICY,
            app(\App\Library\Dashboard\DashboardStatusReader::class)->forBusiness($business->id)->readinessPolicy,
        );

        // A legacy completed onboarding (completed before the policy existed) is never retro-stamped by visiting.
        $legacyCustomer = $this->actingAsHttpCustomer();
        $legacyBusiness = $this->createBusinessWithWorkspace($legacyCustomer, $this->businessAttributes(['name' => 'Legacy Co']));
        $legacy = $this->attach($legacyCustomer, $legacyBusiness, OnboardingStep::Complete);
        $legacy->status = OnboardingStatus::Completed;
        $legacy->save();
        $this->get(route('customer.onboarding.show'))->assertOk();
        $this->post(route('customer.onboarding.complete'));
        $this->assertNull($legacy->refresh()->metadata['readiness_policy'] ?? null);
    }

    // ── Services step ─────────────────────────────────────────────────────

    public function test_services_are_required_and_the_old_skip_cannot_bypass_them(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());

        $this->post(route('customer.onboarding.services.store'), ['services' => []])->assertSessionHasErrors('services');
        $this->post(route('customer.onboarding.services.store'), [])->assertSessionHasErrors('services');

        $this->post(route('customer.onboarding.services.skip'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Services, app(OnboardingManager::class)->currentStep($onboarding));
        $this->assertNotContains('services', $onboarding->completed_steps ?? []);

        $this->get(route('customer.onboarding.show', ['step' => 'website']))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));
    }

    public function test_several_services_need_exactly_one_primary_and_a_single_service_becomes_primary(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());

        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => 'Open-air photo booth'], ['name' => '360 photo booth']]])
            ->assertSessionHasErrors('services');
        $this->post(route('customer.onboarding.services.store'), ['services' => [
            ['name' => 'Open-air photo booth', 'is_primary' => '1'], ['name' => '360 photo booth', 'is_primary' => '1'],
        ]])->assertSessionHasErrors('services');

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(0, $business->services()->count());

        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => 'Photo Booth Rental']]])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'website']));

        $this->assertTrue($business->services()->first()->is_primary);
    }

    public function test_suggestions_are_offered_only_for_photobooths_and_never_added_unselected(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());

        $page = $this->get(route('customer.onboarding.show', ['step' => 'services']))->assertOk();
        foreach (['Open-air photo booth', '360 photo booth', 'Glam photo booth', 'Mirror photo booth', 'Print photo booth', 'Audio guestbook'] as $name) {
            $page->assertSee($name);
        }
        $this->assertSame(0, Business::where('customer_id', $customer->user_id)->first()->services()->count());

        $this->assertSame([], OnboardingServiceSuggestions::for(\App\Enums\Business\BusinessIndustry::HomeServices));
    }

    public function test_retrying_the_services_step_never_duplicates_a_service(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());

        $payload = ['services' => [['name' => 'Photo Booth Rental', 'is_primary' => '1'], ['name' => 'Audio guestbook']]];
        $this->post(route('customer.onboarding.services.store'), $payload);
        // A stale or double-submitted form carries the same names with no ids.
        $this->post(route('customer.onboarding.services.store'), $payload);
        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => ' photo  booth rental ', 'is_primary' => '1']]]);

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(2, $business->services()->count());
        $this->assertSame(2, $business->services()->where('status', BusinessServiceStatus::Active)->count(), 'A stale partial submission does not drop other services.');
        $this->assertSame(1, $business->services()->where('is_primary', true)->count());
    }

    // ── Website step ──────────────────────────────────────────────────────

    public function test_website_build_records_the_intention_without_creating_or_publishing_anything(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'build'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->assertSame('build', $onboarding->refresh()->metadata['website']['choice']);
        $this->assertSame(WebsiteMode::Hosted, app(WebsiteModeManager::class)->resolve($business->refresh()));
        $this->assertSame(0, Website::where('business_id', $business->id)->count());
        $this->assertNull($business->website_url);
    }

    public function test_website_existing_requires_a_valid_public_address_and_saves_it_canonically(): void
    {
        Http::preventStrayRequests();
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing'])->assertSessionHasErrors('website_url');
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'not a url'])->assertSessionHasErrors('website_url');
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'http://localhost'])->assertSessionHasErrors('website_url');
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'nonsense'])->assertSessionHasErrors('website_choice');
        $this->post(route('customer.onboarding.website.store'), [])->assertSessionHasErrors('website_choice');
        $this->assertNull($business->refresh()->website_url);

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'SnapBooth.example'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $business->refresh();
        $this->assertSame('https://snapbooth.example', $business->website_url);
        $this->assertSame('snapbooth.example', $business->canonical_domain);
        $this->assertSame(WebsiteMode::External, app(WebsiteModeManager::class)->resolve($business));

        $this->get(route('customer.onboarding.show', ['step' => 'finish']))
            ->assertSee('Address saved. We have not connected or checked it yet.')
            ->assertDontSee('connected and verified');
        $this->assertDatabaseCount('external_site_crawls', 0);
    }

    public function test_website_later_needs_no_address_and_keeps_a_previously_entered_one(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'snapbooth.example']);
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->assertSame('later', $onboarding->refresh()->metadata['website']['choice']);
        $this->assertSame('https://snapbooth.example', $business->refresh()->website_url);
        $this->assertSame(WebsiteMode::None, app(WebsiteModeManager::class)->resolve($business));

        $this->get(route('customer.onboarding.show', ['step' => 'website']))->assertSee('snapbooth.example');
    }

    public function test_the_choice_survives_a_refresh(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'build']);

        $html = $this->get(route('customer.onboarding.show', ['step' => 'website']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="build"[^>]*checked/', $html);
    }

    public function test_an_existing_managed_website_is_never_repointed_or_detached(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();
        $website = Website::create(['business_id' => $business->id, 'name' => 'Snap Booth', 'status' => 'published']);
        app(WebsiteModeManager::class)->set($business, WebsiteMode::Hosted);

        $this->get(route('customer.onboarding.show', ['step' => 'website']))->assertSee('already set up');

        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'other.example'])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'finish']));

        $this->assertSame(WebsiteMode::Hosted, app(WebsiteModeManager::class)->resolve($business->refresh()));
        $this->assertNull($business->website_url);
        $this->assertSame('published', $website->refresh()->status->value);
        $this->assertSame('build', $onboarding->refresh()->metadata['website']['choice']);
    }

    // ── Finish step and completion ────────────────────────────────────────

    public function test_finish_names_the_exact_missing_step_with_an_edit_link_and_offers_no_completion(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $onboarding = $this->attach($customer, $business, OnboardingStep::Finish);

        $this->get(route('customer.onboarding.show', ['step' => 'finish']))
            ->assertOk()
            ->assertSee(route('customer.onboarding.show', ['step' => 'services']), false)
            ->assertSee(route('customer.onboarding.show', ['step' => 'location']), false)
            ->assertSee('Add at least one service you offer.')
            ->assertDontSee('data-role="complete-form"', false);

        $this->post(route('customer.onboarding.complete'))->assertSessionHasErrors('onboarding');
        $this->assertSame(OnboardingStatus::Started, $onboarding->refresh()->status);
        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status);
    }

    public function test_completion_is_judged_on_persisted_records_not_on_step_flags(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->assignPaidPlanFixture($business);
        $onboarding = $this->attach($customer, $business, OnboardingStep::Finish);
        $onboarding->completed_steps = ['business', 'location', 'services', 'website'];
        $onboarding->metadata = ['website' => ['choice' => 'later']];
        $onboarding->save();

        $this->post(route('customer.onboarding.complete'))->assertSessionHasErrors('onboarding');
        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status);
    }

    public function test_a_repeated_completion_activates_once_and_fires_one_event(): void
    {
        [$customer, $business, $onboarding] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);

        Event::fake([CustomerOnboardingCompleted::class]);

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
        app(OnboardingManager::class)->complete($onboarding, $customer);

        Event::assertDispatchedTimes(CustomerOnboardingCompleted::class, 1);
        $this->assertSame(1, Business::where('customer_id', $customer->user_id)->count());
        $this->assertSame(1, $business->locations()->count());
        $this->assertSame(1, $business->services()->count());
    }

    public function test_completion_with_a_suspended_plan_keeps_the_business_draft(): void
    {
        [$customer, $business] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);

        $admin = \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            \App\Models\Workspace::findOrFail($business->workspace_id),
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended,
            $admin->id,
            'Fixture.',
        );

        // The account-access gate refuses a suspended plan before onboarding runs.
        $this->post(route('customer.onboarding.complete'))->assertRedirect('http://localhost/account-locked');

        $this->assertSame(BusinessStatus::Draft, $business->refresh()->status);
        $this->assertNotSame(OnboardingStatus::Completed, CustomerOnboarding::where('customer_id', $customer->user_id)->first()->status);
    }

    public function test_completion_remembers_the_activated_business_as_the_home_context(): void
    {
        [$customer, $business] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);

        $this->post(route('customer.onboarding.complete'));

        $stored = session(\App\Library\Navigation\CustomerContextPreference::SESSION_KEY);
        $this->assertSame($business->uid, $stored['business']);
    }

    // ── Legacy rows and retired routes ────────────────────────────────────

    public function test_legacy_step_urls_redirect_to_the_current_step_without_bypassing_prerequisites(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        foreach (['goals' => 'business', 'assets' => 'business', 'analysis' => 'business', 'results' => 'business', 'complete' => 'business'] as $legacy => $expected) {
            $this->get(route('customer.onboarding.show', ['step' => $legacy]))
                ->assertRedirect(route('customer.onboarding.show', ['step' => $expected]));
        }
    }

    public function test_retired_post_routes_write_nothing_and_cannot_skip_a_step(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $before = CustomerOnboarding::where('customer_id', $customer->user_id)->first();

        foreach (['goals.store', 'assets.store', 'assets.skip', 'services.skip', 'analysis.request', 'action.complete'] as $name) {
            $this->post(route('customer.onboarding.' . $name), ['primary_goals' => ['lead_generation'], 'website_url' => 'https://x.example'])
                ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));
        }
        $this->get(route('customer.onboarding.analysis.status'))->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $after = $before->fresh();
        $this->assertSame($before->completed_steps, $after->completed_steps);
        $this->assertSame(OnboardingStep::Location, $after->current_step);
        $this->assertNull($after->primary_goals);
        $this->assertNull(Business::where('customer_id', $customer->user_id)->first()->website_url);
    }

    public function test_every_legacy_stored_step_resolves_from_persisted_records(): void
    {
        $manager = app(OnboardingManager::class);

        // Goals: nothing is known yet.
        $customer = $this->createCustomer();
        $onboarding = app(CustomerOnboardingRepository::class)->startForCustomer($customer, false);
        $onboarding->current_step = OnboardingStep::Goals;
        $this->assertSame(OnboardingStep::Business, $manager->currentStep($onboarding));

        // Assets/Analysis/Results/Complete with a business, location and a skipped Services step.
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(BusinessLocationRepository::class)->upsertPrimary($business, $this->serviceAreaLocation(['name' => 'Main']));
        $onboarding = $this->attach($customer, $business, OnboardingStep::Analysis);
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services', 'assets'];
        $onboarding->save();

        foreach ([OnboardingStep::Assets, OnboardingStep::Analysis, OnboardingStep::Results, OnboardingStep::Complete] as $legacy) {
            $onboarding->current_step = $legacy;
            $this->assertSame(OnboardingStep::Services, $manager->currentStep($onboarding), "{$legacy->value} with no service resumes at Services");
        }

        app(BusinessServiceRepository::class)->syncForBusiness($business, [['name' => 'Photo Booth Rental', 'is_primary' => true]]);
        $this->assertSame(OnboardingStep::Website, $manager->currentStep($onboarding->refresh()), 'The new Website question is asked once.');

        $onboarding->metadata = ['website' => ['choice' => 'later']];
        $this->assertSame(OnboardingStep::Finish, $manager->currentStep($onboarding));
    }

    public function test_legacy_goals_and_analysis_data_are_left_untouched(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $onboarding = $this->attach($customer, $business, OnboardingStep::Results);
        $onboarding->primary_goals = ['lead_generation'];
        $onboarding->analysis_payload = ['profile_completeness_percent' => 100, 'findings' => []];
        $onboarding->save();

        $this->get(route('customer.onboarding.show'))->assertOk()->assertDontSee('Profile completeness');

        $onboarding->refresh();
        $this->assertSame(['lead_generation'], $onboarding->primary_goals);
        $this->assertSame(100, $onboarding->analysis_payload['profile_completeness_percent']);
    }

    public function test_an_already_completed_account_stays_completed_and_is_not_sent_back(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $onboarding = $this->attach($customer, $business, OnboardingStep::Complete);
        $onboarding->status = OnboardingStatus::Completed;
        $onboarding->is_required = true;
        $onboarding->save();

        $this->assertSame(OnboardingStep::Finish, app(OnboardingManager::class)->currentStep($onboarding));
        $this->get(route('user.home'))->assertOk();
        $this->get(route('customer.onboarding.show'))->assertOk()->assertSee('Your setup is complete');
        $this->assertSame(OnboardingStatus::Completed, $onboarding->refresh()->status);
    }

    // ── Ownership, entitlement and provider calls ─────────────────────────

    public function test_a_customer_cannot_save_into_another_customers_business(): void
    {
        $owner = $this->createCustomer();
        $foreign = $this->createBusinessWithWorkspace($owner, $this->businessAttributes(['name' => 'Foreign Co']));

        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(CustomerOnboardingRepository::class)->startForCustomer($customer, false);
        $onboarding->business_id = $foreign->id;
        $onboarding->current_step = OnboardingStep::Services;
        $onboarding->save();

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Hijacked']))->assertStatus(401);
        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => 'Hijack']]])->assertStatus(401);
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'evil.example'])->assertStatus(401);

        $foreign->refresh();
        $this->assertSame('Foreign Co', $foreign->name);
        $this->assertNull($foreign->website_url);
        $this->assertSame(0, $foreign->services()->count());
    }

    public function test_no_ai_provider_or_network_call_happens_anywhere_in_onboarding(): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        Event::fake([\App\Events\Business\InitialBusinessAnalysisRequested::class]);

        [$customer, $business] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'existing', 'website_url' => 'snapbooth.example']);
        $this->post(route('customer.onboarding.complete'));

        Http::assertNothingSent();
        Bus::assertNotDispatched(\App\Jobs\Business\BuildInitialBusinessSnapshot::class);
        Event::assertNotDispatched(\App\Events\Business\InitialBusinessAnalysisRequested::class);
    }

    // ── Facts handed to Website, SEO and Home ─────────────────────────────

    public function test_onboarding_leaves_the_canonical_facts_other_modules_read(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation([
            'service_area_cities' => "Irvine\nAnaheim",
            'region' => 'California',
        ]));
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Photo Booth Rental', 'is_primary' => '1']],
            'suggested_services' => ['Glam photo booth'],
        ]);

        $business = Business::where('customer_id', $customer->user_id)->first();
        $location = $business->primaryLocation;

        $this->assertSame('photo_booth_service', $business->industry->value);
        $this->assertSame('Orange', $location->city);
        $this->assertSame('California', $location->region);
        $this->assertSame('US', $location->country_code);
        $this->assertSame(['Irvine', 'Anaheim'], $location->service_area_cities);
        $this->assertSame('Photo Booth Rental', $business->primaryService->name);
        $this->assertSame(['Photo Booth Rental', 'Glam photo booth'], $business->services()->orderBy('sort_order')->pluck('name')->all());
        // Services and places stay distinct: no geography is baked into a service name.
        $this->assertFalse($business->services()->where('name', 'like', '%Orange%')->exists());
    }

    public function test_a_completed_onboarding_hands_home_every_foundation_fact(): void
    {
        [$customer, $business] = $this->onboardingAtWebsiteStep();
        $this->post(route('customer.onboarding.website.store'), ['website_choice' => 'later']);
        $this->post(route('customer.onboarding.complete'));

        $status = app(\App\Library\Dashboard\DashboardStatusReader::class)->forBusiness($business->id);

        $this->assertTrue($status->hasPrimaryLocation);
        $this->assertTrue($status->hasPrimaryService);
        $this->assertNull($status->websiteStatus, 'Choosing a website path creates no website.');
        $this->assertFalse($status->hasCompletedExternalAudit);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0: Customer, 1: Business, 2: CustomerOnboarding} */
    private function onboardingAtWebsiteStep(): array
    {
        $customer = $this->actingAsHttpCustomer();
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $business = Business::where('customer_id', $customer->user_id)->firstOrFail();
        $this->post(route('customer.onboarding.location.store'), $this->serviceAreaLocation());
        $this->post(route('customer.onboarding.services.store'), ['services' => [['name' => 'Photo Booth Rental']]]);

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->firstOrFail();
        $this->assertSame(OnboardingStep::Website, $onboarding->current_step);

        return [$customer, $business, $onboarding];
    }

    private function attach(Customer $customer, Business $business, OnboardingStep $step): CustomerOnboarding
    {
        $repository = app(CustomerOnboardingRepository::class);
        $onboarding = $repository->attachBusiness($repository->startForCustomer($customer, true), $business);
        $onboarding->current_step = $step;
        $onboarding->save();

        return $onboarding;
    }

    /** @return array<string, mixed> */
    private function serviceAreaLocation(array $overrides = []): array
    {
        return array_merge([
            'service_mode' => 'service_area',
            'city' => 'Orange',
            'region' => 'CA',
            'country_code' => 'US',
        ], $overrides);
    }

    private function actingAsHttpCustomer(): Customer
    {
        foreach ([['license', 'test-license-key'], ['custom_script', '']] as [$setting, $value]) {
            if (! AppConfig::where('setting', $setting)->exists()) {
                AppConfig::create(['setting' => $setting, 'value' => $value]);
            }
        }

        if (! AppConfig::where('setting', 'customer_permissions')->exists()) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }
}
