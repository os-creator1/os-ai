<?php

namespace Tests\Feature\Business;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 A2 nonvisual remediation implementation: HTTP-level,
 * end-to-end coverage for customer.business.edit/update -- both
 * authorization boundaries (Boundary A: EnsureBusinessProfileIsAccessible
 * route middleware; Boundary B: BusinessManager::updateOwnBusinessProfile()'s
 * mutation-time recheck, proven directly in BusinessManagerTest.php since a
 * sequential HTTP test cannot itself prove a TOCTOU race is closed) as
 * experienced by a real request, plus Finding 7 (the customer
 * industry_other field).
 */
class CustomerBusinessControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_guest_is_rejected(): void
    {
        $this->get(route('customer.business.edit'))->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Active Workspace
    // -----------------------------------------------------------------

    public function test_active_workspace_rightful_customer_get_succeeds(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->get(route('customer.business.edit'))->assertOk();
    }

    public function test_active_workspace_valid_put_persists_and_redirects_with_success(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes(['name' => 'Renamed Booth Co']))
            ->assertRedirect(route('customer.business.edit'));

        $this->assertSame('Renamed Booth Co', Business::find($business->id)->name);
    }

    public function test_active_workspace_invalid_put_receives_ordinary_validation_behavior(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), [])
            ->assertSessionHasErrors(['name', 'industry', 'country_code', 'timezone', 'currency_code']);
    }

    // -----------------------------------------------------------------
    // Inactive Workspace
    // -----------------------------------------------------------------

    public function test_inactive_workspace_get_returns_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        Workspace::whereKey($business->workspace_id)->update(['is_active' => false]);

        $this->get(route('customer.business.edit'))->assertNotFound();
    }

    public function test_inactive_workspace_valid_put_returns_not_found_with_zero_mutation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $originalName = $business->name;
        Workspace::whereKey($business->workspace_id)->update(['is_active' => false]);

        $this->put(route('customer.business.update'), $this->businessAttributes(['name' => 'Attempted Update']))
            ->assertNotFound();

        $this->assertSame($originalName, Business::find($business->id)->name);
    }

    /**
     * Mandatory: proves the middleware (Boundary A) denies before
     * UpdateBusinessRequest is ever resolved/validated -- a regression to
     * a controller-body-only design would make this fail (returning a
     * validation-error redirect instead of 404) while the two tests above
     * could still appear to pass.
     */
    public function test_inactive_workspace_malformed_put_returns_not_found_not_validation_redirect_with_zero_mutation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $originalName = $business->name;
        Workspace::whereKey($business->workspace_id)->update(['is_active' => false]);

        $response = $this->put(route('customer.business.update'), []);

        $response->assertNotFound();
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($originalName, Business::find($business->id)->name);
    }

    // -----------------------------------------------------------------
    // No primary Business
    // -----------------------------------------------------------------

    public function test_no_primary_business_get_redirects_to_onboarding(): void
    {
        $this->actingAsHttpCustomer();

        $this->get(route('customer.business.edit'))
            ->assertRedirect(route('customer.onboarding.show'));
    }

    public function test_no_primary_business_valid_payload_put_redirects_to_onboarding(): void
    {
        $this->actingAsHttpCustomer();

        $this->put(route('customer.business.update'), $this->businessAttributes())
            ->assertRedirect(route('customer.onboarding.show'));
    }

    /**
     * A malformed PUT with no primary Business fails UpdateBusinessRequest
     * validation before the controller body ever runs -- genuinely
     * pre-existing, unmodified behavior, not the onboarding redirect.
     */
    public function test_no_primary_business_invalid_payload_put_receives_ordinary_validation_failure(): void
    {
        $this->actingAsHttpCustomer();

        $response = $this->put(route('customer.business.update'), []);

        $response->assertSessionHasErrors(['name', 'industry', 'country_code', 'timezone', 'currency_code']);
        $this->assertNotSame(route('customer.onboarding.show'), $response->headers->get('Location'));
    }

    // -----------------------------------------------------------------
    // Finding 7 — industry_other
    // -----------------------------------------------------------------

    public function test_other_industry_with_valid_detail_persists(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes([
            'industry' => 'other',
            'industry_other' => 'Mobile Detailing',
        ]))->assertRedirect(route('customer.business.edit'));

        $fresh = Business::find($business->id);
        $this->assertSame('other', $fresh->industry->value);
        $this->assertSame('Mobile Detailing', $fresh->industry_other);
    }

    public function test_other_industry_without_detail_fails_validation(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $originalIndustry = $business->industry->value;

        $this->put(route('customer.business.update'), $this->businessAttributes(['industry' => 'other']))
            ->assertSessionHasErrors('industry_other');

        $this->assertSame($originalIndustry, Business::find($business->id)->industry->value);
    }

    public function test_standard_industry_remains_valid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes(['industry' => 'event_services']))
            ->assertRedirect(route('customer.business.edit'))
            ->assertSessionDoesntHaveErrors();
    }

    public function test_get_edit_for_other_industry_business_renders_the_stored_detail(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
            'industry' => 'other',
            'industry_other' => 'Mobile Detailing',
        ]));

        $this->get(route('customer.business.edit'))
            ->assertOk()
            ->assertSee('value="Mobile Detailing"', false);
    }

    public function test_transition_from_other_to_standard_industry_succeeds_without_clearing_stored_detail(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
            'industry' => 'other',
            'industry_other' => 'Mobile Detailing',
        ]));

        $this->put(route('customer.business.update'), $this->businessAttributes(['industry' => 'event_services']))
            ->assertRedirect(route('customer.business.edit'));

        $fresh = Business::find($business->id);
        $this->assertSame('event_services', $fresh->industry->value);
        // Deliberate, established convention (not a defect): switching away
        // from Other does not auto-clear the stored industry_other value.
        $this->assertSame('Mobile Detailing', $fresh->industry_other);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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
