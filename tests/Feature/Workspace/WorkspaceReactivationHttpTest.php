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
 * RFC-003 Milestone 4 Slice 4C
 * (docs/automation/RFC-003-M4-SLICE-4C-CONTRACT.md, "Authorized behavior"):
 * the customer HTTP surface for Workspace reactivation, the owner-only
 * symmetric counterpart to Slice 4A's deactivate. Covers route shape,
 * successful reactivation and its redirect/flash behavior, owner-only
 * authority, the manager's idempotent no-op on an already-active
 * Workspace, and unknown/inaccessible uid handling.
 */
class WorkspaceReactivationHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    // --- Route shape -------------------------------------------------

    public function test_reactivate_route_exists_as_post_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.reactivate'));

        $route = Route::getRoutes()->getByName('customer.workspaces.reactivate');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}/reactivate', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    // --- Guests ----------------------------------------------------------

    public function test_guest_is_rejected_by_reactivate(): void
    {
        $this->post(route('customer.workspaces.reactivate', ['workspaceUid' => 'anything']))
            ->assertUnauthorized();
    }

    // --- Reactivate --------------------------------------------------------

    public function test_owner_can_reactivate_their_inactive_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $response->assertSessionHas('flash_success');
        $this->assertTrue((bool) $workspace->fresh()->is_active);
    }

    public function test_reactivating_an_already_active_workspace_is_an_authorized_no_op(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => true]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertRedirect(route('customer.workspaces.show', $workspace->uid));
        $this->assertTrue((bool) $workspace->fresh()->is_active);
    }

    public function test_active_admin_cannot_reactivate_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_active_staff_cannot_reactivate_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertRedirect();
        $response->assertSessionHas('flash_error');
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_inactive_admin_cannot_reactivate_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => false,
        ]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertNotFound();
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_is_admin_alone_grants_no_reactivate_authority(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $customer->user->is_admin = true;
        $customer->user->save();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertNotFound();
        $this->assertFalse((bool) $workspace->fresh()->is_active);
    }

    public function test_reactivate_of_an_unknown_uid_is_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.reactivate', 'does-not-exist'))->assertNotFound();
    }

    public function test_reactivate_of_an_inaccessible_workspace_is_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['is_active' => false]);

        $response = $this->post(route('customer.workspaces.reactivate', $workspace->uid));

        $response->assertNotFound();
        $this->assertFalse((bool) $workspace->fresh()->is_active);
        $this->assertTrue($customer->exists);
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
