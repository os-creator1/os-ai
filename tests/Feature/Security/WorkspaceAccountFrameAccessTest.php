<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — Correction Round 1, Correction 2.
 *
 * The account frame (Workspace overview and account list) is reachable by
 * direct URL only for the roles the contract lets see it: the Workspace
 * owner, active Admins and Agency-wide staff. A client / Business-scoped
 * member (selected-scope membership) receives 404 — never the Agency
 * name, plan, staff list or sibling Businesses (contract §5.2, §5.4, §6;
 * T-CTX-2, T-CTX-3, T-NAV-1). Hiding the menu is not the mechanism: these
 * are typed URLs.
 */
class WorkspaceAccountFrameAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_a_client_business_owner_cannot_read_the_agency_overview_by_direct_url(): void
    {
        [$agencyOwner, $agencyBusiness, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $client = $this->createCustomer();
        $clientBusiness = $this->addBusiness($client, $workspace, 'Client Bakery');
        $this->assign($this->member($workspace, $client->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $clientBusiness);
        $this->authenticateAs($client);

        $this->get(route('customer.workspaces.show', $workspace->uid))->assertNotFound();
        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertNotFound();

        // The account list is empty for a client: no Agency identity is listed.
        $index = $this->get(route('customer.workspaces.index'))->assertOk();
        $this->assertStringNotContainsString('Northwind Agency', $index->getContent());
        $this->assertStringNotContainsString('Agency House', $index->getContent());
        // The overview is never linked (the client's own Business links share
        // the /workspaces/{uid}/businesses/… prefix, so match the whole href).
        $this->assertStringNotContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $index->getContent());

        // Their own Business stays fully reachable.
        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $clientBusiness->uid]))->assertOk();
    }

    public function test_business_scoped_staff_cannot_read_the_overview_even_as_an_admin_with_selected_scope(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $scopedAdmin = $this->createCustomer();
        $this->assign($this->member($workspace, $scopedAdmin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected), $business);
        $this->authenticateAs($scopedAdmin);

        $this->get(route('customer.workspaces.show', $workspace->uid))->assertNotFound();
        $this->assertStringNotContainsString('Harbor Lane<', $this->get(route('customer.workspaces.index'))->assertOk()->getContent());
        $this->get(route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $business->uid]))->assertOk();
    }

    public function test_agency_owner_admin_and_agency_wide_staff_keep_the_account_frame(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');

        $this->authenticateAs($owner);
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('Northwind Agency');

        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('Northwind Agency');

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff);
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('Northwind Agency');
        $this->assertStringContainsString(route('customer.workspaces.show', $workspace->uid), $this->get(route('customer.workspaces.index'))->assertOk()->getContent());
    }

    public function test_strangers_and_inactive_members_receive_not_found(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        [$stranger] = $this->tenant(WorkspacePlanTier::Growth, 'Elsewhere', 'Elsewhere Account');
        $this->authenticateAs($stranger);
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertNotFound();

        $former = $this->createCustomer();
        $this->member($workspace, $former->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, false);
        $this->authenticateAs($former);
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertNotFound();
    }

    /**
     * T-CTX-2 on the account pages themselves: a Core/Growth owner reaches
     * them as "account" pages and never reads the word Workspace.
     */
    public function test_core_and_growth_owners_read_account_not_workspace_on_the_account_pages(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, , $workspace] = $this->tenant($tier, 'Tier ' . $tier->value, 'Owner Account');
            $this->authenticateAs($owner);

            foreach ([route('customer.workspaces.show', $workspace->uid), route('customer.workspaces.index')] as $url) {
                $page = $this->get($url)->assertOk();
                // Rendered text only: script/style bodies and attributes are
                // not what the reader sees.
                $rendered = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $page->getContent()) ?? '';
                $text = html_entity_decode(strip_tags(preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $rendered) ?? ''));
                $this->assertStringNotContainsStringIgnoringCase('workspace', $text, $tier->value . ': ' . $url . ' must not say Workspace.');
            }

            $this->get(route('customer.workspaces.show', $workspace->uid))->assertSee('Account overview')->assertSee('Rename account');
            $this->get(route('customer.workspaces.index'))->assertSee('Create account');
        }
    }

    public function test_agency_pages_keep_the_established_wording(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->assertSee('Workspace overview')->assertSee('Rename Workspace');
        $this->get(route('customer.workspaces.index'))->assertOk()->assertSee('Create Workspace');
    }
}
