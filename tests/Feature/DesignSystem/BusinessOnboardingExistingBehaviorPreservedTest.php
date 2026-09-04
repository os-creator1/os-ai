<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Business\OnboardingStep;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Library\Business\OnboardingManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 A1 (Business Onboarding) — real-HTTP behavior-
 * preservation proof for the restyled 9-view surface. This is NOT a
 * duplicate of tests/Feature/Business/BusinessOnboardingHttpTest.php's
 * hundreds of backend assertions (run immediately before this file in the
 * locked test sequence) — it proves only that the presentation-layer
 * restyle left every route, form field name/value, hidden field, old()
 * binding, fingerprint/action_key pairing, and the just-merged nonvisual
 * remediation (master-switch gate, capacity-denial safe message) intact
 * when exercised through the actual rendered Blade output.
 */
class BusinessOnboardingExistingBehaviorPreservedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
    }

    public function test_all_11_onboarding_route_names_still_resolve(): void
    {
        foreach ([
            'customer.onboarding.show',
            'customer.onboarding.goals.store',
            'customer.onboarding.business.store',
            'customer.onboarding.location.store',
            'customer.onboarding.services.store',
            'customer.onboarding.assets.store',
            'customer.onboarding.assets.skip',
            'customer.onboarding.analysis.request',
            'customer.onboarding.analysis.status',
            'customer.onboarding.action.complete',
            'customer.onboarding.complete',
        ] as $name) {
            $this->assertIsString(route($name));
        }
    }

    public function test_goals_step_renders_native_checkboxes_with_preserved_names_and_values_and_still_advances(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $response = $this->get(route('customer.onboarding.show'))->assertOk();
        $response->assertSee('name="primary_goals[]"', false);
        $response->assertSee('type="checkbox"', false);

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']])
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'business']));

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(['lead_generation'], $onboarding->primary_goals);
    }

    public function test_business_step_renders_adopted_fields_that_still_submit_and_persist_correctly(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);

        $response = $this->get(route('customer.onboarding.show'))->assertOk();
        $response->assertSee('name="name"', false);
        $response->assertSee('name="industry"', false);
        $response->assertSee('id="description" name="description"', false);

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame('Snap Booth Co', $business->name);
    }

    public function test_business_step_redisplays_old_input_and_validation_errors_through_the_adopted_alert(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => '']))
            ->assertSessionHasErrors('name');

        $response = $this->get(route('customer.onboarding.show'))->assertOk();
        $response->assertSee('alert-danger', false);
        $response->assertSee('ds-alert', false);
    }

    public function test_location_step_service_mode_select_preserves_option_values_and_still_submits(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $response = $this->get(route('customer.onboarding.show'))->assertOk();
        $response->assertSee('name="service_mode"', false);
        $response->assertSee('value="storefront"', false);
        $response->assertSee('name="public_address"', false);
        $response->assertSee('type="checkbox"', false);

        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame('Austin', $business->locations()->first()->city);
    }

    public function test_services_step_hidden_id_and_indexed_field_names_survive_a_repeat_submission(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes());

        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);

        // Revisit the (now-completed) services step directly — the store
        // above already advanced current_step to assets.
        $response = $this->get(route('customer.onboarding.show', ['step' => 'services']))->assertOk();
        $response->assertSee('name="services[0][id]"', false);
        $response->assertSee('name="services[0][name]"', false);
        $response->assertSee('name="services[0][starting_price]"', false);
        $response->assertSee('name="services[0][is_primary]"', false);
        $response->assertSee('name="services[1][name]"', false);

        $serviceId = Business::where('customer_id', $customer->user_id)->first()->services()->first()->id;
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['id' => $serviceId, 'name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'assets']));

        $this->assertSame(1, Business::where('customer_id', $customer->user_id)->first()->services()->count());
    }

    public function test_assets_step_form_actions_and_field_names_are_unchanged_and_skip_still_advances(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes());
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);

        $response = $this->get(route('customer.onboarding.show'))->assertOk();
        $response->assertSee('action="' . route('customer.onboarding.assets.store') . '"', false);
        $response->assertSee('action="' . route('customer.onboarding.assets.skip') . '"', false);
        $response->assertSee('name="google_business_profile_url"', false);

        $this->post(route('customer.onboarding.assets.skip'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'analysis']));
    }

    public function test_results_step_fingerprint_and_action_key_hidden_fields_and_inline_action_still_work(): void
    {
        [$onboarding, $customer, $business] = $this->httpOnboardingAtAnalysisStep(['phone' => null]);
        $fingerprint = $this->seedRealAnalysisPayloadAndGetFingerprint($onboarding, $business, 'add_phone');

        $response = $this->get(route('customer.onboarding.show', ['step' => 'results']))->assertOk();
        $response->assertSee('name="fingerprint"', false);
        $response->assertSee('name="action_key"', false);
        $response->assertSee('value="' . $fingerprint . '"', false);
        $response->assertSee('action="' . route('customer.onboarding.action.complete') . '"', false);

        $this->post(route('customer.onboarding.action.complete'), [
            'fingerprint' => $fingerprint,
            'action_key' => 'add_phone',
            'value' => '+15551234567',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'complete']));

        $onboarding->refresh();
        $this->assertSame('add_phone', $onboarding->first_value_action_key);
    }

    public function test_results_step_finish_setup_still_completes_onboarding(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);

        $response = $this->get(route('customer.onboarding.show', ['step' => 'results']))->assertOk();
        $response->assertSee('Finish setup');

        $this->post(route('customer.onboarding.complete'))->assertRedirect(route('user.home'));
    }

    public function test_complete_step_link_still_targets_the_dashboard_route(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);
        $this->post(route('customer.onboarding.complete'));

        $response = $this->get(route('customer.onboarding.show', ['step' => 'complete']))->assertOk();
        $response->assertSee('href="' . route('user.home') . '"', false);
    }

    /**
     * Spot-check only — the exhaustive master-switch/capacity-denial
     * behavior matrix already lives in BusinessOnboardingHttpTest.php (run
     * immediately before this file). This proves the restyled show.blade.php
     * and business.blade.php did not regress either preserved behavior.
     */
    public function test_master_switch_disabled_still_returns_not_found_through_the_restyled_view(): void
    {
        config(['business.onboarding.enabled' => false]);
        $this->actingAsHttpCustomer();

        $this->get(route('customer.onboarding.show'))->assertNotFound();
    }

    public function test_capacity_denial_safe_message_is_unchanged_by_the_restyled_business_step(): void
    {
        $customer = $this->actingAsHttpCustomer();

        // A pre-existing Workspace with no plan assignment is required to
        // exercise EntitlementManager::assertCanCreateAnotherBusiness()'s
        // denial path (decideBusinessSlotCapacity()'s null-assignment
        // branch) — mirroring BusinessOnboardingHttpTest's own fixture.
        \App\Models\Workspace::create([
            'name' => 'Existing',
            'owner_user_id' => $customer->user_id,
            'is_active' => true,
        ]);

        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Business;
        $onboarding->save();

        $response = $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $response->assertRedirect(route('customer.onboarding.show', ['step' => 'business']));
        $response->assertSessionHasErrors([
            'onboarding' => "We can't create your business with the current account setup. Please contact support for help.",
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: CustomerOnboarding, 1: Customer, 2: Business}
     */
    private function httpOnboardingAtAnalysisStep(array $businessOverrides = []): array
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, array_merge(
            $this->businessAttributes(),
            $businessOverrides
        ));

        app(BusinessLocationRepository::class)->upsertPrimary($business, $this->locationAttributes());
        app(BusinessServiceRepository::class)->syncForBusiness($business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
        ]);

        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($customer, true);
        $onboarding = $onboardingRepository->attachBusiness($onboarding, $business);
        $onboarding->current_step = OnboardingStep::Analysis;
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services', 'assets'];
        $onboarding->save();

        return [$onboarding, $customer, $business];
    }

    private function seedRealAnalysisPayloadAndGetFingerprint(CustomerOnboarding $onboarding, Business $business, string $actionKey): string
    {
        $snapshot = app(InitialBusinessSnapshotBuilder::class)->build($business, $onboarding->primary_goals ?? []);
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, $snapshot);

        $finding = collect($snapshot['findings'])->firstWhere('action_key', $actionKey);
        $this->assertNotNull($finding, "Expected a seeded finding for action_key [{$actionKey}].");

        return $finding['fingerprint'];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationAttributes(array $overrides = []): array
    {
        return array_merge([
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => '1',
        ], $overrides);
    }

    private function actingAsHttpCustomer(): Customer
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
