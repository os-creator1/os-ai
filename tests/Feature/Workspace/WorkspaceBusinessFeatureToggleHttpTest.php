<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
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
 * disable preference. The mechanism is a stored preference, never claimed
 * runtime enforcement -- every rendered response must carry the fixed
 * enforcement-pending notice and never a live on/off implication.
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

    public function test_a_business_belonging_to_another_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $otherWorkspace = $this->entitledWorkspace($customer->user);
        $foreignBusiness = $this->createBusinessForCustomer($customer->user->id, $otherWorkspace->id);

        $this->post(route('customer.workspaces.businesses.features.disable', [$workspace->uid, $foreignBusiness->uid, 'crm']))
            ->assertNotFound();
    }

    // --- Toggle UX (§13) ---------------------------------------------------

    public function test_show_page_carries_the_enforcement_pending_notice_and_no_live_control_language(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('Runtime enforcement pending. This preference is stored at the Business level but the legacy module does not yet consult it.');
        $response->assertDontSee('Feature enabled');
        $response->assertDontSee('Feature disabled');
        $response->assertSee('Platform feature preference');
    }

    public function test_remove_disable_preference_still_renders_when_the_feature_is_denied_by_an_unrelated_workspace_override(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, (int) $customer->user_id);
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $this->fixtureAdminId(), 'Unrelated deny override.'
        );

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('Remove disable preference');
    }

    public function test_record_disable_preference_never_renders_when_there_is_no_preference_and_the_feature_is_denied(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        // Intentionally left unassigned -- every Available feature decides
        // 'workspace_plan_unassigned' (denied), and no toggle row exists.
        $business = $this->createBusinessForCustomer($customer->user->id, $workspace->id);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('No disable preference recorded');
        $response->assertDontSee('Record disable preference');
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
