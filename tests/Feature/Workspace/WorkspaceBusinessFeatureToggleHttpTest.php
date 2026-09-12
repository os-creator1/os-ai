<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessFeatureToggle;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-004 Milestone 3 (docs/automation/RFC-004-M3-CONTRACT.md §12/§13): the
 * customer HTTP surface for recording/removing a Business-level feature
 * disable preference. The routes and their authority are unchanged; the page
 * now shows the switches only for features whose switch the product honours,
 * as Enabled / Disabled (see WorkspaceBusinessFeatureSettingsTest).
 */
class WorkspaceBusinessFeatureToggleHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function fixtureAdminId(): int
    {
        return User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin',
            'email' => 'fixtureadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function entitledWorkspace($owner, array $overrides = []): Workspace
    {
        $workspace = $this->createWorkspace($owner, $overrides);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace, WorkspacePlanTier::Core, $this->fixtureAdminId(), 'Fixture assignment.', true, 2,
        );

        return $workspace->fresh();
    }

    // --- Route shape ---------------------------------------------------

    public function test_disable_and_enable_routes_exist_as_post(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.businesses.features.disable'));
        $this->assertTrue(Route::has('customer.workspaces.businesses.features.enable'));

        $disable = Route::getRoutes()->getByName('customer.workspaces.businesses.features.disable');
        $this->assertContains('POST', $disable->methods());
        $this->assertNotContains('GET', $disable->methods());
    }

    public function test_guest_is_rejected(): void
    {
        $this->post(route('customer.workspaces.businesses.features.disable', ['anything', 'anything', 'crm']))
            ->assertUnauthorized();
    }

    // --- Success -------------------------------------------------------

    public function test_owner_can_record_and_remove_a_disable_preference(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('business_feature_toggles', ['business_id' => $business->id, 'feature_key' => 'crm']);

        $this->post(route('customer.workspaces.businesses.features.enable', [$workspace->uid, $business->uid, 'crm']))
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_success');
        $this->assertSame(0, BusinessFeatureToggle::where('business_id', $business->id)->count());
    }

    public function test_active_admin_can_record_a_disable_preference(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->entitledWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertRedirect(route('customer.workspaces.show', $workspace->uid))
            ->assertSessionHas('flash_success');
    }

    // --- Denial ----------------------------------------------------------

    public function test_staff_cannot_record_a_disable_preference(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->entitledWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => true]);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertSame(0, BusinessFeatureToggle::where('business_id', $business->id)->count());
    }

    public function test_inactive_admin_is_not_found_at_the_resolver(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->entitledWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->createMembership($workspace, $customer->user, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => false]);

        // resolveAccessibleWorkspace() fails closed with 404 for a user with
        // no owner/active-membership relationship at all -- an inactive
        // Admin has no active membership, so this is 404 at the resolver,
        // matching every other mutation action's existing precedent
        // (rename()/deactivate() etc. behave identically for this actor).
        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertNotFound();
    }

    public function test_unrelated_user_receives_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->entitledWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertNotFound();
        $this->assertTrue($customer->exists);
    }

    public function test_recording_a_preference_against_an_inactive_workspace_maps_to_flash_error(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);
        $workspace->is_active = false;
        $workspace->save();

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'crm']))
            ->assertRedirect()
            ->assertSessionHas('flash_error');
        $this->assertSame(0, BusinessFeatureToggle::where('business_id', $business->id)->count());
    }

    // --- 404 boundaries (Blocker 4) ------------------------------------

    public function test_unknown_feature_key_is_not_found_before_any_entitlement_manager_call(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'not-a-real-feature']))
            ->assertNotFound();
        $this->assertSame(0, BusinessFeatureToggle::where('business_id', $business->id)->count());
    }

    /**
     * Correction 2 — ProspectOutreach is now globally Available, so
     * without an independent scope guard this route could otherwise
     * reach the manager for it. Even on a genuinely Agency-tier Workspace
     * (truly entitled to ProspectOutreach at the Workspace level), this
     * Business-only surface must still 404 — identical to an unknown
     * feature key, never a distinguishable response.
     */
    public function test_prospect_outreach_is_not_found_on_the_business_toggle_disable_route(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->fixtureAdminId(), 'Fixture assignment.', true, 0);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->fresh()->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $business->uid, 'prospect_outreach']))
            ->assertNotFound();
        $this->assertSame(0, BusinessFeatureToggle::where('business_id', $business->id)->count());
    }

    public function test_prospect_outreach_is_not_found_on_the_business_toggle_enable_route(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->fixtureAdminId(), 'Fixture assignment.', true, 0);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->fresh()->id);

        $this->post(route('customer.workspaces.businesses.features.enable', [$workspace->uid, $business->uid, 'prospect_outreach']))
            ->assertNotFound();
    }

    public function test_a_business_belonging_to_another_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $otherWorkspace = $this->entitledWorkspace($customer->user);
        $foreignBusiness = $this->createBusinessForCustomer($customer->user->id, $otherWorkspace->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $foreignBusiness->uid, 'crm']))
            ->assertNotFound();
    }

    // --- Toggle UX ----------------------------------------------------------
    // The switches now change what the product does (every listed feature's
    // switch is honoured where it runs), so the page speaks in Enabled /
    // Disabled; the old stored-preference wording is gone. The full surface
    // is covered by WorkspaceBusinessFeatureSettingsTest.

    public function test_show_page_offers_enabled_disabled_switches_without_implementation_wording(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('Inbox & Conversations');
        $this->assertSame(3, preg_match_all('/<input [^>]*data-business-feature-switch/', $response->getContent()));
        $response->assertSee('Enabled');
        $response->assertDontSee('Runtime enforcement pending');
        $response->assertDontSee('Platform feature preference');
        $response->assertDontSee('Record disable preference');
    }

    public function test_a_switched_off_feature_stays_listed_so_it_can_be_turned_back_on(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Automations, (int) $customer->user_id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $this->assertMatchesRegularExpression('/>Automations<.*?<input [^>]*data-feature="automations"(?![^>]* checked)[^>]*>.*?data-role="business-feature-state">Disabled</s', $response->getContent());
    }

    public function test_no_switch_renders_when_the_account_has_no_plan(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        // Intentionally left unassigned -- every Available feature decides
        // 'workspace_plan_unassigned' (denied), so nothing is switchable.
        $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $this->assertSame(0, preg_match_all('/<input [^>]*data-business-feature-switch/', $response->getContent()));
        $response->assertDontSee('id="business-feature-settings"', false);
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
}
