<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 4 Slice 4A
 * (docs/automation/RFC-003-M4-SLICE-4A-CONTRACT.md, "Authorized behavior"):
 * the customer HTTP surfaces for Workspace create, rename, and deactivate.
 * Covers route shape, successful mutation flows and their persistence,
 * validation failures, unauthorized-role cases, inactive-state behavior,
 * unknown/inaccessible uid handling, redirects, and regression of the
 * existing Milestone 3 read-only switcher/overview surfaces.
 */
class WorkspaceMutationHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    // --- Route shape -------------------------------------------------

    public function test_store_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.store'));

        $route = Route::getRoutes()->getByName('customer.workspaces.store');

        $this->assertNotNull($route);
        $this->assertSame('workspaces', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_rename_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.rename'));

        $route = Route::getRoutes()->getByName('customer.workspaces.rename');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/rename', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_deactivate_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.deactivate'));

        $route = Route::getRoutes()->getByName('customer.workspaces.deactivate');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/deactivate', $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    public function test_show_route_still_carries_no_mutation_verb(): void
    {
        // Regression of the Milestone 3 boundary: mutations live on their
        // own URIs, never on customer.workspaces.show.
        $route = Route::getRoutes()->getByName('customer.workspaces.show');

        $this->assertNotNull($route);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertNotContains($verb, $route->methods());
        }
    }

    // --- Guests --------------------------------------------------------

    public function test_guest_is_rejected_by_store(): void
    {
        $this->post(route('customer.workspaces.store'), ['name' => 'Guest Co'])->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_rename(): void
    {
        $this->post(route('customer.workspaces.rename', ['workspaceUid' => 'anything']), ['name' => 'X'])
            ->assertUnauthorized();
    }

    public function test_guest_is_rejected_by_deactivate(): void
    {
        $this->post(route('customer.workspaces.deactivate', ['workspaceUid' => 'anything']))
            ->assertUnauthorized();
    }

    // --- Create ----------------------------------------------------------

    public function test_customer_can_create_a_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();

        $response = $this->post(route('customer.workspaces.store'), ['name' => 'New Co']);

        $workspace = Workspace::where('name', 'New Co')->first();

        $this->assertNotNull($workspace);
        $this->assertSame((int) $customer->user->id, (int) $workspace->owner_user_id);
        $this->assertTrue((bool) $workspace->is_active);
        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
    }

    public function test_create_does_not_create_a_business(): void
    {
        $customer = $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.store'), ['name' => 'No Business Co']);

        $workspace = Workspace::where('name', 'No Business Co')->first();

        $this->assertNotNull($workspace);
        $this->assertSame(0, $workspace->businesses()->count());
    }

    public function test_create_requires_a_name(): void
    {
        $this->actingAsHttpCustomer();

        $before = Workspace::count();

        $response = $this->post(route('customer.workspaces.store'), ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertSame($before, Workspace::count());
    }

    public function test_create_rejects_a_name_over_the_bound(): void
    {
        $this->actingAsHttpCustomer();

        $before = Workspace::count();

        $response = $this->post(route('customer.workspaces.store'), ['name' => str_repeat('a', 256)]);

        $response->assertSessionHasErrors('name');
        $this->assertSame($before, Workspace::count());
    }

    // --- Rename ------------------------------------------------------

    public function test_owner_can_rename_their_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Old Name']);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'New Name']);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertSame('New Name', $workspace->fresh()->name);
    }

    public function test_active_admin_can_rename_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Admin Renamed']);

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $this->assertSame('Admin Renamed', $workspace->fresh()->name);
    }

    public function test_active_staff_cannot_rename_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Old Name']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Staff Attempt']);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame('Old Name', $workspace->fresh()->name);
    }

    public function test_rename_requires_a_name(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Unchanged']);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Unchanged', $workspace->fresh()->name);
    }

    public function test_rename_of_an_inactive_workspace_fails(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Dormant Co', 'is_active' => false]);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Should Not Apply']);

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertSame('Dormant Co', $workspace->fresh()->name);
    }

    public function test_rename_of_an_unknown_uid_is_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.rename', 'does-not-exist'), ['name' => 'X'])->assertNotFound();
    }

    public function test_rename_of_an_inaccessible_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Unrelated Co']);

        $response = $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Hijacked']);

        $response->assertNotFound();
        $this->assertSame('Unrelated Co', $workspace->fresh()->name);
        $this->assertTrue($customer->exists);
    }

    // --- Deactivate ------------------------------------------------------

    public function test_owner_can_deactivate_their_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->post(route('customer.workspaces.deactivate', $workspace->uid));

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_deactivating_an_already_inactive_workspace_is_an_authorized_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);

        $response = $this->post(route('customer.workspaces.deactivate', $workspace->uid));

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_active_admin_cannot_deactivate_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.deactivate', $workspace->uid));

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertTrue((bool) $workspace->fresh()->is_active);
    }

    public function test_active_staff_cannot_deactivate_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.deactivate', $workspace->uid));

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertTrue((bool) $workspace->fresh()->is_active);
    }

    public function test_deactivate_of_an_unknown_uid_is_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.deactivate', 'does-not-exist'))->assertNotFound();
    }

    public function test_deactivate_of_an_inaccessible_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $response = $this->post(route('customer.workspaces.deactivate', $workspace->uid));

        $response->assertNotFound();
        $this->assertTrue((bool) $workspace->fresh()->is_active);
        $this->assertTrue($customer->exists);
    }

    // --- Regression of the read-only surfaces -------------------------

    public function test_renamed_workspace_is_reflected_on_the_read_only_switcher_and_overview(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Before Co']);

        $this->post(route('customer.workspaces.rename', $workspace->uid), ['name' => 'After Co'])->assertRedirect();

        $indexResponse = $this->get(route('customer.workspaces.index'))->assertOk();
        $this->assertSame(
            'After Co',
            collect($indexResponse->original->getData()['workspaces'])->firstWhere('uid', $workspace->uid)['name']
        );

        $showResponse = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $this->assertSame('After Co', $showResponse->original->getData()['workspace']['name']);
    }

    public function test_deactivated_workspace_remains_addressable_and_listed_as_inactive(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $this->post(route('customer.workspaces.deactivate', $workspace->uid))->assertRedirect();

        $showResponse = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $this->assertFalse($showResponse->original->getData()['workspace']['is_active']);

        $indexResponse = $this->get(route('customer.workspaces.index'))->assertOk();
        $this->assertFalse(
            collect($indexResponse->original->getData()['workspaces'])->firstWhere('uid', $workspace->uid)['is_active']
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
