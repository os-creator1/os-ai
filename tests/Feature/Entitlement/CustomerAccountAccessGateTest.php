<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\ContactGroups;
use App\Models\Customer;
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

    // =========================================================================
    // PR #302 correction 2, P1 — two-factor redirect loop.
    // =========================================================================

    public function test_a_locked_customer_with_a_pending_two_factor_challenge_can_reach_the_verify_screen(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);
        $this->markPendingTwoFactor($customer);

        $this->get(route('verify.index'))->assertOk();
    }

    public function test_a_locked_customers_two_factor_submission_is_not_intercepted_by_the_account_gate(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);
        $code = $this->markPendingTwoFactor($customer);

        // A deliberately wrong code: the controller's own mismatch handling
        // must be the one answering (a redirect back to /verify with a
        // session error), never this gate's redirect to the locked screen.
        $wrongCode = $code === 123456 ? 654321 : 123456;
        $response = $this->post(route('verify.store'), ['two_factor_code' => (string) $wrongCode]);

        $this->assertFalse($response->isRedirect(route('customer.account-locked.show')));
    }

    public function test_completing_two_factor_while_locked_does_not_loop_and_the_product_stays_locked_afterwards(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Inactive);
        $this->authenticateAs($customer);
        $code = $this->markPendingTwoFactor($customer);

        // Correct code: the challenge clears, so TwoFactor middleware stops
        // forcing every request to /verify.
        $this->post(route('verify.store'), ['two_factor_code' => (string) $code])
            ->assertRedirect();

        // The very next request lands on the locked screen -- never bounced
        // back to /verify (nothing pending any more) and never stuck in a
        // loop between the two.
        $this->get(route('user.home'))->assertRedirect(route('customer.account-locked.show'));
        $this->get(route('customer.account-locked.show'))->assertOk();

        // Operational product routes remain locked afterwards.
        $this->get(route('customer.workspaces.show', $workspace->uid))
            ->assertRedirect(route('customer.account-locked.show'));
    }

    // =========================================================================
    // PR #302 correction 2, P1 — multiple locked Workspaces, no selection.
    // =========================================================================

    public function test_two_locked_workspaces_with_no_selection_does_not_become_usable(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Core, 'Inactive Co', 'Inactive Workspace');
        $this->lockWorkspace($workspaceA, WorkspacePlanAssignmentStatus::Inactive);

        [, , $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Suspended Co', 'Suspended Workspace');
        $this->member($workspaceB, $customerA->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);
        $this->lockWorkspace($workspaceB, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateAs($customerA);

        // Two accessible Workspaces, neither selected, both locked: this
        // must never collapse into the same "usable" case as a brand-new
        // customer with nothing to lock.
        $this->home()->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_one_active_and_one_inactive_workspace_remains_reachable_through_explicit_context(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Core, 'Inactive Co', 'Inactive Workspace');
        $this->lockWorkspace($workspaceA, WorkspacePlanAssignmentStatus::Inactive);

        [, , $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Active Co', 'Active Workspace');
        $this->member($workspaceB, $customerA->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);

        $this->authenticateAs($customerA);

        // The existing, explicit context-switch model -- unchanged by this
        // correction -- is what commits to the Active Workspace.
        $this->switchToAccount($workspaceB)->assertRedirect(route('user.home'));

        $response = $this->get(route('user.home'));
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertFalse($response->isRedirect(route('customer.account-locked.show')));
    }

    public function test_mixed_workspace_states_with_no_selection_are_not_contaminated_by_the_inactive_one(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Core, 'Inactive Co', 'Inactive Workspace');
        $this->lockWorkspace($workspaceA, WorkspacePlanAssignmentStatus::Inactive);

        [, , $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Active Co', 'Active Workspace');
        $this->member($workspaceB, $customerA->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);

        $this->authenticateAs($customerA);

        // No explicit selection at all -- the locked Workspace must not
        // contaminate the genuinely reachable Active one, and nothing here
        // invents a selection to decide either way.
        $response = $this->get(route('user.home'));
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertFalse($response->isRedirect(route('customer.account-locked.show')));
    }

    public function test_zero_workspaces_onboarding_behavior_is_unchanged(): void
    {
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $response = $this->get(route('user.home'));

        $this->assertFalse($response->isRedirect(route('customer.account-locked.show')));
    }

    // =========================================================================
    // PR #302 correction 2, P2 — foreign Workspace status disclosure.
    // =========================================================================

    public function test_a_foreign_active_workspace_route_receives_the_normal_existing_denial(): void
    {
        [$customerA] = $this->tenant(WorkspacePlanTier::Core, 'Actor Co', 'Actor Workspace');
        [, , $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Foreign Co', 'Foreign Workspace');

        $this->authenticateAs($customerA);

        $this->get(route('customer.workspaces.show', $foreignWorkspace->uid))->assertNotFound();
    }

    public function test_a_foreign_inactive_workspace_route_gets_the_same_denial_and_never_discloses_plan_state(): void
    {
        [$customerA] = $this->tenant(WorkspacePlanTier::Core, 'Actor Co', 'Actor Workspace');
        [, , $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Foreign Co', 'Foreign Workspace');
        $this->lockWorkspace($foreignWorkspace, WorkspacePlanAssignmentStatus::Inactive);

        $this->authenticateAs($customerA);

        $response = $this->get(route('customer.workspaces.show', $foreignWorkspace->uid));

        $response->assertNotFound();
        $this->assertStringNotContainsString('plan_inactive', $response->getContent());
    }

    public function test_owned_locked_workspace_still_locks_while_a_foreign_suspended_workspace_gets_the_normal_denial(): void
    {
        [$customerA, , $ownedWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Actor Co', 'Actor Workspace');
        $this->lockWorkspace($ownedWorkspace, WorkspacePlanAssignmentStatus::Inactive);

        [, , $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Foreign Co', 'Foreign Workspace');
        $this->lockWorkspace($foreignWorkspace, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateAs($customerA);

        // Owned: the normal locked-account behavior, unchanged.
        $this->get(route('customer.workspaces.show', $ownedWorkspace->uid))
            ->assertRedirect(route('customer.account-locked.show'));

        // Foreign: the route's own existing denial -- never this gate's
        // locked-account redirect, never a disclosed plan_suspended reason.
        $foreignResponse = $this->get(route('customer.workspaces.show', $foreignWorkspace->uid));
        $foreignResponse->assertNotFound();
        $this->assertStringNotContainsString('plan_suspended', $foreignResponse->getContent());
    }

    private function lockWorkspace(Workspace $workspace, WorkspacePlanAssignmentStatus $status): void
    {
        app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'Chat F fixture lock.');
    }

    /**
     * @return int the plaintext pending code, for a test that needs to
     *   submit it back
     */
    private function markPendingTwoFactor(Customer $customer): int
    {
        config(['app.two_factor' => true]);

        // TwoFactorController::store()'s own success path re-derives the
        // session's 'permissions' key straight from Customer.permissions,
        // not from this test's authenticateAs() priming -- a real customer
        // has this populated at registration. Without it, completing the
        // challenge would wipe the very access_backend permission the
        // locked screen's own route middleware requires on the very next
        // request.
        $customer->permissions = json_encode($this->allCustomerPermissions());
        $customer->save();

        $user = $customer->user->fresh();
        $user->two_factor = true;
        $user->generateTwoFactorCode();
        $user->save();

        // Rebind the guard to the freshly-mutated instance -- the same
        // object TwoFactor middleware will read auth()->user() as.
        $this->actingAs($user);

        return $user->two_factor_code;
    }

    // =========================================================================
    // PR #302 correction 3, finding C — a {businessUid} route must be
    // decided by Business-level authorization, never AccountFrameAccess
    // alone.
    // =========================================================================

    public function test_a_selected_scope_member_authorized_for_the_routed_business_is_locked_when_the_workspace_is_locked(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Agency Co', 'Agency Workspace');
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);

        $staffCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $staffCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, true);
        $this->assign($membership, $business);

        $this->authenticateAs($staffCustomer);

        // AccountFrameAccess alone would say no (not owner, not all-scope)
        // and previously let this pass through unevaluated. This actor IS
        // authorized for exactly this Business via the canonical
        // WorkspaceManager::userCanAccessBusiness() the routed controller
        // itself relies on, so the Workspace lock must apply.
        $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_an_actor_with_no_access_to_the_routed_business_gets_ordinary_denial_without_disclosing_the_lock(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Agency Co', 'Agency Workspace');
        $this->lockWorkspace($workspace, WorkspacePlanAssignmentStatus::Suspended);

        [$strangerCustomer] = $this->tenant(WorkspacePlanTier::Core, 'Stranger Co', 'Stranger Workspace');
        $this->authenticateAs($strangerCustomer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertNotFound();
        $this->assertStringNotContainsString('plan_suspended', $response->getContent());
    }

    // =========================================================================
    // PR #302 correction 3, finding D — a legacy resource-addressed web
    // route (no {workspaceUid} at all) must be decided by the target
    // resource's own Business/Workspace, never the customer's current
    // session selection.
    // =========================================================================

    public function test_a_legacy_web_contact_write_in_a_secondary_locked_workspace_is_blocked_even_though_current_selection_is_active(): void
    {
        [$customer, , $primaryWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Primary Co', 'Primary Workspace');
        // $primaryWorkspace stays Active -- the customer's current/only
        // session context, per tenant()'s own default.

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Business']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateAs($customer);

        $group = ContactGroups::create([
            'customer_id' => $customer->user_id,
            'business_id' => $secondaryBusiness->id,
            'name' => 'Fixture Group',
        ]);

        // postJson (not post): this app's own exception handler renders
        // EVERY generic HttpException as the same errors.404 page outside
        // `wantsJson()` requests (existence-disclosure hardening already
        // applied broadly elsewhere in this codebase) -- only a JSON
        // request surfaces the real 403 abort_if() raises.
        $this->postJson(route('customer.contact.store', $group->uid), ['PHONE' => '15551234567'])
            ->assertStatus(403);
    }

    public function test_a_legacy_web_contact_write_in_the_active_primary_workspace_remains_reachable_while_a_secondary_is_locked(): void
    {
        [$customer, $primaryBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Primary Co', 'Primary Workspace');

        $secondaryBusiness = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Secondary Business']));
        app(EntitlementManager::class)->assignFirstPlan($secondaryBusiness->workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture.', true, 0);
        $this->lockWorkspace($secondaryBusiness->workspace, WorkspacePlanAssignmentStatus::Suspended);

        $this->authenticateAs($customer);

        $group = ContactGroups::create([
            'customer_id' => $customer->user_id,
            'business_id' => $primaryBusiness->id,
            'name' => 'Fixture Group',
        ]);

        // postJson so a real 403 (if one occurred) would be visible as
        // such rather than masked into this app's generic errors.404 page
        // -- see the companion "secondary locked" test above.
        $response = $this->postJson(route('customer.contact.store', $group->uid), ['PHONE' => '15551234567']);

        // Never this gate's own 403: the locked secondary Workspace must
        // never contaminate a write correctly addressed to the active one.
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
