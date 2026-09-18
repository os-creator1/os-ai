<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Navigation\CustomerContextPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — security of the account context:
 * forged/cross-Workspace uids (S-2), the remembered preference as a
 * non-authorization (§3 rule 10), Agency client isolation (T-CTX-3),
 * navigation visibility never being the boundary (T-NAV-1), every rendered
 * link resolving to an authorized, registered route (T-NAV-2, T-NAV-3),
 * platform-owner and agency controls never leaking, and View-as never
 * widening authorization (T-VIEW-4).
 */
class CustomerContextSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_forged_workspace_and_business_uid_combinations_fail_with_404(): void
    {
        [$tenantA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Account A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Account B');
        $this->authenticateAs($tenantA);

        $this->switchTo($workspaceA, $businessB)->assertNotFound();
        $this->switchTo($workspaceB, $businessB)->assertNotFound();
        $this->switchTo($workspaceB, $businessA)->assertNotFound();
        $this->switchTo('not-a-workspace', 'not-a-business')->assertNotFound();
        $this->switchTo($workspaceA, $businessA)->assertRedirect(route('user.home'));

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspaceA->uid, $businessB->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
    }

    public function test_a_remembered_preference_cannot_grant_access(): void
    {
        [$tenantA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Account A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Account B');
        $this->authenticateAs($tenantA);

        $this->withSession([CustomerContextPreference::SESSION_KEY => ['workspace' => $workspaceB->uid, 'business' => $businessB->uid]]);

        $home = $this->home()->assertOk();
        $this->assertStringNotContainsString('Business B', $home->getContent());
        $this->assertStringContainsString('Business A', $this->shellText($home->getContent()), 'The actor lands in their own sole Business, never the forged one.');
        $this->assertNotSame($businessB->uid, session(CustomerContextPreference::SESSION_KEY)['business'] ?? null);
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
    }

    /**
     * Contract 13 remediation (Category C): "unassigned" used to be a
     * sibling Business in the SAME Workspace — impossible now. The
     * property under test is that Selected-scope grants nothing beyond its
     * explicit assignment; an entirely independent Business (not even a
     * member of this Workspace) is at least as strong a proof of that,
     * since it is denied by both the Selected-scope check and ordinary
     * tenancy.
     */
    public function test_selected_scope_staff_cannot_enumerate_or_switch_into_unassigned_businesses(): void
    {
        [$owner, $assigned, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Assigned Client', 'Northwind Agency');
        $unassigned = $this->createIndependentWorkspaceBusiness(businessName: 'Unassigned Client')['business'];
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $assigned);
        $this->authenticateAs($staff);

        $home = $this->home()->assertOk();
        $this->assertStringNotContainsString('Unassigned Client', $home->getContent());
        $this->switchTo($workspace, $unassigned)->assertNotFound();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $unassigned->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $unassigned->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $unassigned->uid]))->assertNotFound();
        $this->startViewAs($workspace, $unassigned)->assertNotFound();
    }

    /**
     * T-CTX-3 — Agency isolation: client A's actor cannot read, write or
     * discover client B's Business; the response is 404.
     *
     * Contract 13 remediation (Category A + C): the old fixture put both
     * clients as sibling Businesses inside the Agency's own Workspace,
     * each reached via Selected-scope Staff membership of THAT Workspace —
     * impossible now (one Business per Workspace) and, independently,
     * already reversed by Contracts 01/07/10 (an Agency's clients are
     * separate Client Workspaces, not sibling Businesses). The redesign
     * uses two real, current Agency-managed Client Workspaces sharing one
     * Agency, each with its own Selected-scope Staff member — proving the
     * property this test exists for (Agency isolation between two clients
     * of the SAME Agency, not merely two unrelated random tenants) rather
     * than the retired row shape. Client A's Staff has no membership in
     * Client B's Workspace, nor in the Agency's own Workspace, so every
     * denial below is ordinary tenancy AND the Agency's own identity is
     * never reachable at all, not merely hidden.
     */
    public function test_agency_clients_cannot_read_write_or_discover_each_other(): void
    {
        [, , $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Business', 'Northwind Agency');
        $pairA = $this->createAgencyManagedClient(agencyWorkspace: $agencyWorkspace, clientBusinessName: 'Client A Bakery', clientWorkspaceName: 'Client A Workspace');
        $pairB = $this->createAgencyManagedClient(agencyWorkspace: $agencyWorkspace, clientBusinessName: 'Client B Florist', clientWorkspaceName: 'Client B Workspace');

        $staffA = $this->createCustomer();
        $this->assign($this->member($pairA['clientWorkspace'], $staffA->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $pairA['clientBusiness']);

        $this->authenticateAs($staffA);

        $home = $this->home()->assertOk();
        $this->assertStringNotContainsString('Client B Florist', $home->getContent());
        $this->assertStringNotContainsString('Northwind Agency', $home->getContent());
        $this->assertStringNotContainsString('Agency Business', $home->getContent());

        $businessB = $pairB['clientBusiness'];
        $workspaceB = $pairB['clientWorkspace'];

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspaceB->uid, $businessB->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.usage-billing.payer', [$workspaceB->uid, $businessB->uid]), ['payer_type' => 'business'])->assertNotFound();
        $this->switchTo($workspaceB, $businessB)->assertNotFound();
        $this->startViewAs($workspaceB, $businessB)->assertNotFound();

        // The shell never leads a client to the Agency's account surface,
        // and (Correction Round 1) the account surface itself refuses a
        // client by direct URL: 404, never the Agency name, plan or staff.
        $keys = $this->menuKeys($home->getContent());
        $this->assertNotContains('team', $keys);
        $this->assertNotContains('accounts', $keys);
        $this->assertNotContains(route('customer.workspaces.show', $agencyWorkspace->uid), $this->menuLinks($home->getContent()));
        $this->assertNotContains(route('customer.workspaces.index'), $this->menuLinks($home->getContent()));
        $this->get(route('customer.workspaces.show', $agencyWorkspace->uid))->assertNotFound();
        $this->assertStringNotContainsString('Northwind Agency', $this->get(route('customer.workspaces.index'))->assertOk()->getContent());
    }

    /**
     * T-NAV-1 — with navigation hidden, a direct request to every
     * unauthorized route is still refused (permission: 401 per the house
     * convention; tenancy: 404).
     */
    public function test_cross_tenant_direct_requests_fail_even_when_the_menu_hides_the_feature(): void
    {
        [$owner, $assigned, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Assigned Client', 'Northwind Agency');
        $unassigned = $this->createIndependentWorkspaceBusiness(businessName: 'Unassigned Client')['business'];
        $staff = $this->createCustomer();
        $this->assign($this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $assigned);
        $this->authenticateAs($staff, ['view_contact', 'view_contact_group']);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->assertContains('contacts', $keys);
        $this->assertNotContains('analytics', $keys);
        $this->assertNotContains('gbp', $keys);

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $assigned->uid]))->assertStatus(401);
        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $assigned->uid]))->assertStatus(401);

        // Tenancy is refused for the unassigned Business even on a surface
        // the actor IS permitted to use (Contacts): 404, never disclosure.
        $this->get(route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $assigned->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $unassigned->uid]))->assertNotFound();
    }

    /**
     * T-NAV-2 / T-NAV-3 — every rendered menu link resolves to a registered
     * route the current actor may reach (200, or a redirect into a page it
     * may reach), across the experiences that render a menu.
     */
    public function test_every_rendered_menu_link_resolves_to_a_route_the_actor_may_reach(): void
    {
        $experiences = [];

        [$growth, , ] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Business', 'Growth Account');
        $experiences['growth owner'] = [$growth, null];

        // Contract 13 remediation (Category C): reaching the Account frame
        // used to be incidental (a second sibling Business made selection
        // ambiguous) — impossible now. The context switcher's own
        // deliberate "account" choice (switchToAccount(), Lane E) is the
        // current way to stand in it regardless of Business count.
        [$agency, $clientOne, $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $experiences['agency owner (account frame)'] = [$agency, 'account'];
        $experiences['agency owner (business frame)'] = [$agency, [$agencyWorkspace, $clientOne]];

        $staff = $this->createCustomer();
        $this->assign($this->member($agencyWorkspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $clientOne);
        $experiences['selected-scope staff'] = [$staff, null];

        foreach ($experiences as $label => [$customer, $switch]) {
            $this->authenticateAs($customer);

            if ($switch === 'account') {
                $this->switchToAccount($agencyWorkspace)->assertRedirect(route('user.home'));
            } elseif ($switch !== null) {
                $this->switchTo($switch[0], $switch[1])->assertRedirect(route('user.home'));
            }

            $links = $this->menuLinks($this->home()->assertOk()->getContent());
            $this->assertNotEmpty($links, $label . ' must render at least one link.');

            foreach ($links as $link) {
                $this->assertUrlMatchesARegisteredRoute($link);

                $status = $this->get($link)->getStatusCode();

                $this->assertContains($status, [200, 302], "{$label}: menu link {$link} answered {$status}.");
            }

            session()->forget(CustomerContextPreference::SESSION_KEY);
        }
    }

    public function test_platform_owner_controls_never_appear_in_the_customer_shell(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($customer);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());

        $this->assertStringNotContainsString('/' . config('app.admin_path') . '/', $shell);
        $this->assertStringNotContainsString('Sending servers', $shell);
        $this->assertStringNotContainsString('Plan catalog', $shell);
    }

    public function test_agency_controls_do_not_appear_to_normal_business_users(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->home()->assertOk();
        $keys = $this->menuKeys($response->getContent());

        $this->assertNotContains('accounts', $keys);
        $this->assertNotContains('prospecting', $keys);
        $this->assertNotContains('advanced', $keys);
        $response->assertDontSee('as a client', false);
        $response->assertDontSee(route('customer.view-as.start'), false);
        $this->assertStringNotContainsString('Client account', $this->shellText($response->getContent()));
    }

    /**
     * T-VIEW-4 — View-as cannot widen authorization: a Business the actor
     * cannot reach is still 404 while viewing, and so is every OTHER
     * Business while the view is active.
     */
    /**
     * Contract 13 remediation (Category C): "clientTwo" used to be a
     * sibling Business in the Agency's own Workspace — impossible now. The
     * property under test needs a Business the actor ordinarily reaches
     * (unlike $foreignBusiness, which they never can) but that becomes
     * unreachable ONLY while narrowed by an active View As session, and
     * reachable again once it ends — a genuinely separate Workspace with
     * an ordinary Admin membership proves exactly that, same as the
     * equivalent fix in ViewAsRouteBoundaryTest.
     */
    public function test_view_as_cannot_widen_authorization(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $clientTwoSetup = $this->createIndependentWorkspaceBusiness(businessName: 'Client Two', workspaceName: 'Client Two Workspace');
        $this->member($clientTwoSetup['workspace'], $agency->user, WorkspaceMembershipRole::Admin);
        $clientTwo = $clientTwoSetup['business'];
        $clientTwoWorkspace = $clientTwoSetup['workspace'];
        [, $foreignBusiness, $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Business', 'Foreign Account');
        $this->authenticateAs($agency);

        $this->startViewAs($workspace, $foreignBusiness)->assertNotFound();
        $this->startViewAs($workspace, $clientOne)->assertRedirect(route('user.home'));

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientOne->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$clientTwoWorkspace->uid, $clientTwo->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$clientTwoWorkspace->uid, $clientTwo->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
    }
}
