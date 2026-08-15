<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 4 Slice 4D
 * (docs/automation/RFC-003-M4-SLICE-4D-CONTRACT.md, "Authorized behavior"):
 * the customer HTTP surface for creating a Business inside an existing,
 * accessible Workspace via WorkspaceManager::createBusinessInWorkspace().
 * Covers route shape, the existing reused UpsertBusinessIdentityRequest's
 * validation, owner-or-active-Admin authority, the HTTP-layer actor-to-
 * Customer tenancy binding (no caller-controlled customer selector is ever
 * honored), inactive-Workspace denial, and unknown/inaccessible-uid
 * handling.
 */
class WorkspaceBusinessCreationHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    /**
     * M2 fixture-compatibility helper (§14.3, read-only audit finding) —
     * NOT a change to the shared CreatesWorkspaceTestData trait. Wraps
     * createWorkspace() with an explicit valid complimentary Core
     * workspace_plan_assignments row, established via
     * EntitlementManager::assignFirstPlan() using a distinct platform-admin
     * fixture actor (never the test's own customer/owner/admin actor under
     * test) — so this fixture step never alters or expands the HTTP
     * endpoint's own Workspace/customer authority semantics.
     */
    private function entitledWorkspace($owner, array $overrides = []): \App\Models\Workspace
    {
        $workspace = $this->createWorkspace($owner, $overrides);

        $admin = \App\Models\User::create([
            'first_name' => 'M2Fixture', 'last_name' => 'Admin', 'email' => 'm2fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(\App\Library\Entitlement\EntitlementManager::class)->assignFirstPlan(
            $workspace,
            \App\Enums\Entitlement\WorkspacePlanTier::Core,
            $admin->id,
            'M2 fixture-compatibility assignment.',
            true,
            2,
        );

        return $workspace->fresh();
    }

    // --- Route shape -------------------------------------------------

    public function test_businesses_store_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.businesses.store'));

        $route = Route::getRoutes()->getByName('customer.workspaces.businesses.store');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/businesses', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    // --- Guests ------------------------------------------------------------

    public function test_guest_is_rejected_by_store_business(): void
    {
        $this->post(route('customer.workspaces.businesses.store', ['workspaceUid' => 'anything']))
            ->assertUnauthorized();
    }

    // --- Validation ----------------------------------------------------

    public function test_store_business_requires_a_name(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => ''])
        );

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_requires_an_industry(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $attributes = $this->businessAttributes();
        unset($attributes['industry']);

        $response = $this->post(route('customer.workspaces.businesses.store', $workspace->uid), $attributes);

        $response->assertSessionHasErrors('industry');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_requires_a_country_code(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $attributes = $this->businessAttributes();
        unset($attributes['country_code']);

        $response = $this->post(route('customer.workspaces.businesses.store', $workspace->uid), $attributes);

        $response->assertSessionHasErrors('country_code');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_requires_a_timezone(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $attributes = $this->businessAttributes();
        unset($attributes['timezone']);

        $response = $this->post(route('customer.workspaces.businesses.store', $workspace->uid), $attributes);

        $response->assertSessionHasErrors('timezone');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_requires_a_currency_code(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $attributes = $this->businessAttributes();
        unset($attributes['currency_code']);

        $response = $this->post(route('customer.workspaces.businesses.store', $workspace->uid), $attributes);

        $response->assertSessionHasErrors('currency_code');
        $this->assertSame(0, Business::count());
    }

    public function test_industry_other_is_required_only_when_industry_is_other(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['industry' => 'other'])
        );

        $response->assertSessionHasErrors('industry_other');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_rejects_a_name_over_the_existing_request_bound(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => str_repeat('a', 256)])
        );

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Business::count());
    }

    // --- Successful creation and tenancy binding ------------------------

    public function test_owner_can_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => 'Owner Created Co'])
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');

        $business = Business::where('name', 'Owner Created Co')->first();
        $this->assertNotNull($business);
        $this->assertSame((int) $workspace->id, (int) $business->workspace_id);
        $this->assertSame((int) $customer->user_id, (int) $business->customer_id);
    }

    public function test_active_admin_can_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->entitledWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => 'Admin Created Co'])
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');

        $business = Business::where('name', 'Admin Created Co')->first();
        $this->assertNotNull($business);
        $this->assertSame((int) $workspace->id, (int) $business->workspace_id);
    }

    public function test_active_admin_creation_binds_to_the_admins_own_customer_not_the_owner(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => 'Admin Tenancy Co'])
        );

        $business = Business::where('name', 'Admin Tenancy Co')->first();
        $this->assertNotNull($business);
        $this->assertSame((int) $customer->user_id, (int) $business->customer_id);
        $this->assertNotSame((int) $ownerCustomer->user_id, (int) $business->customer_id);
    }

    public function test_owner_creation_ignores_a_submitted_customer_id(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $otherCustomer = $this->createCustomer();

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            array_merge(
                $this->businessAttributes(['name' => 'Ignore Customer Id Co']),
                ['customer_id' => $otherCustomer->user_id]
            )
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));

        $business = Business::where('name', 'Ignore Customer Id Co')->first();
        $this->assertNotNull($business);
        $this->assertSame((int) $customer->user_id, (int) $business->customer_id);
        $this->assertNotSame((int) $otherCustomer->user_id, (int) $business->customer_id);
    }

    public function test_owner_creation_ignores_a_submitted_customer_uid(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $otherCustomer = $this->createCustomer();

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            array_merge(
                $this->businessAttributes(['name' => 'Ignore Customer Uid Co']),
                ['customer_uid' => $otherCustomer->uid]
            )
        );

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));

        $business = Business::where('name', 'Ignore Customer Uid Co')->first();
        $this->assertNotNull($business);
        $this->assertSame((int) $customer->user_id, (int) $business->customer_id);
        $this->assertNotSame((int) $otherCustomer->user_id, (int) $business->customer_id);
    }

    // --- Denial ----------------------------------------------------------

    public function test_staff_cannot_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame(0, Business::count());
    }

    public function test_inactive_admin_cannot_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertNotFound();
        $this->assertSame(0, Business::count());
    }

    public function test_unrelated_user_cannot_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertNotFound();
        $this->assertSame(0, Business::count());
        $this->assertTrue($customer->exists);
    }

    public function test_is_admin_alone_grants_no_business_creation_authority(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $customer->user->is_admin = true;
        $customer->user->save();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertNotFound();
        $this->assertSame(0, Business::count());
    }

    public function test_creation_against_an_inactive_workspace_fails_closed(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame(0, Business::count());
    }

    /**
     * RFC-004 Milestone 3 (§16 of the M3 contract, closing the §3.7 gap):
     * WorkspacePlanUnassignedException is now caught and mapped to a
     * specific flash_error message rather than propagating uncaught, as it
     * did before M3 (superseded M2-era proof removed).
     */
    public function test_creation_against_an_intentionally_unassigned_workspace_is_now_caught_and_mapped_to_flash_error(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame(0, Business::count());
    }

    public function test_store_business_for_an_unknown_uid_is_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.businesses.store', 'does-not-exist'), $this->businessAttributes())
            ->assertNotFound();
    }

    public function test_store_business_for_an_inaccessible_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $response = $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes()
        );

        $response->assertNotFound();
        $this->assertSame(0, Business::count());
        $this->assertTrue($customer->exists);
    }

    // --- Regression --------------------------------------------------------

    public function test_created_business_appears_in_the_effective_access_business_list(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $this->post(
            route('customer.workspaces.businesses.store', $workspace->uid),
            $this->businessAttributes(['name' => 'Listed Co'])
        )->assertRedirect();

        $showResponse = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $this->assertTrue(
            collect($showResponse->original->getData()['businesses'])->contains(fn ($business) => $business['name'] === 'Listed Co')
        );
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
