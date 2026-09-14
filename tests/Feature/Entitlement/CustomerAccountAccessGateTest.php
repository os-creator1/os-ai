<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Chat F — Customer Account Access Gate.
 *
 * CustomerAccountAccessGate (middleware) end to end: an authenticated
 * customer whose Workspace plan is Inactive/Suspended cannot reach the
 * normal product by any route (GET, POST, JSON/AJAX), can always reach the
 * locked screen, logout and the one permitted billing recovery route, and
 * a locked Workspace never contaminates a different, active one for the
 * same actor. Admin/platform surfaces are untouched.
 */
class CustomerAccountAccessGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_an_active_customer_reaches_the_dashboard_normally(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);

        $this->authenticateAs($customer);

        $this->home()->assertOk();
    }

    public function test_an_inactive_customer_stays_authenticated_but_the_dashboard_returns_the_locked_experience(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);

        $this->authenticateAs($customer);

        // Login/auth remains valid — the gate never de-authenticates.
        $this->assertTrue(Auth::check());

        $this->home()->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_an_inactive_customers_direct_operational_get_is_blocked(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.show', $workspace->uid))
            ->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_an_inactive_customers_direct_operational_post_json_action_is_blocked(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);

        $originalName = $workspace->name;

        $this->postJson(route('customer.workspaces.rename', $workspace->uid), ['name' => 'Renamed While Locked'])
            ->assertStatus(403)
            ->assertJson(['status' => 'error', 'reason' => 'plan_inactive']);

        // Fails closed before the controller — the write never happened.
        $this->assertSame($originalName, $workspace->fresh()->name);
    }

    public function test_an_inactive_customer_can_reach_the_locked_screen_logout_and_the_billing_recovery_route(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);

        $locked = $this->get(route('customer.account-locked.show'));
        $locked->assertOk();
        $locked->assertSee('Welcome back');
        $locked->assertSee('Continue to billing');

        $billing = $this->get(route('customer.workspaces.plan.show', $workspace->uid));
        $billing->assertOk();

        $logout = $this->get(route('logout'));
        $this->assertNotSame(403, $logout->status());
        if ($logout->isRedirect()) {
            $this->assertStringNotContainsString('account-locked', (string) $logout->headers->get('Location'));
        }
    }

    public function test_visiting_the_locked_screen_while_still_locked_never_redirects_again(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);

        $this->get(route('customer.account-locked.show'))->assertOk();
        // A second load is exactly as reachable — no bounce, no loop.
        $this->get(route('customer.account-locked.show'))->assertOk();
    }

    public function test_a_suspended_customer_gets_the_same_product_lock_with_suspended_specific_copy_and_no_payment_promise(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);
        $this->authenticateAs($customer);

        $this->home()->assertRedirect(route('customer.account-locked.show'));

        $this->get(route('customer.workspaces.show', $workspace->uid))
            ->assertRedirect(route('customer.account-locked.show'));

        $this->postJson(route('customer.workspaces.rename', $workspace->uid), ['name' => 'x'])
            ->assertStatus(403)
            ->assertJson(['status' => 'error', 'reason' => 'plan_suspended']);

        $locked = $this->get(route('customer.account-locked.show'));
        $locked->assertOk();
        $locked->assertSee('suspended');
        // No truthful recovery route exists for a suspension — the CTA
        // Inactive shows must never appear here.
        $locked->assertDontSee('Continue to billing');
    }

    public function test_an_inactive_workspace_never_contaminates_a_different_active_workspace_for_the_same_actor(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Core, 'Inactive Co', 'Inactive Workspace');
        $this->lockWorkspace($workspaceA, WorkspacePlanAssignmentStatus::Inactive);

        // A second, genuinely ACTIVE Workspace the SAME user can also reach,
        // as an active admin member (not owner) — the realistic Agency-actor
        // shape the product decision describes.
        [, , $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Active Co', 'Active Workspace');
        $this->member($workspaceB, $customerA->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);

        $this->authenticateAs($customerA);

        // The inactive Workspace is locked.
        $this->get(route('customer.workspaces.show', $workspaceA->uid))
            ->assertRedirect(route('customer.account-locked.show'));

        // The SAME actor's active Workspace is completely unaffected by
        // workspace A's lock. (Not asserting a literal 200: an active,
        // business-first account's own show() legitimately 302s straight to
        // its Business settings page — a pre-existing, unrelated product
        // behavior. What this test exists to prove is that the GATE itself
        // never intervenes for workspace B.)
        $responseB = $this->get(route('customer.workspaces.show', $workspaceB->uid));
        $this->assertNotSame(403, $responseB->getStatusCode());
        $this->assertNotSame(route('customer.account-locked.show'), $responseB->headers->get('Location'));
    }

    public function test_admin_platform_surfaces_are_unaffected_by_the_gate(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $admin = User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'gate-admin-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        // The admin route group's own 'can:access backend' gate reads
        // session permissions exactly like the customer side does — this
        // mirrors the existing actingAsAdmin() pattern used elsewhere in
        // the suite (e.g. AdminWorkspaceControllerTest) rather than
        // inventing a new one.
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        $this->get(route('admin.home'))->assertOk();
    }

    private function lockWorkspace(Workspace $workspace, WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'Chat F fixture lock.');
    }
}
