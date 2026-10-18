<?php

namespace Tests\Feature\Agency;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The Agency's OWN operating workspace: its own Business inside its own
 * Workspace, so its CRM, Calendar, Automations, SEO and Ads are never a
 * managed client's. Covers the explicit, onboarding-free enablement, who may
 * run it, that the module routes stay Business-scoped, and that the workspace
 * is never a billable client.
 */
class AgencyOperationsWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const MODULE_ROUTES = [
        'customer.workspaces.businesses.people.index',
        'customer.workspaces.businesses.crm.board',
        'customer.workspaces.businesses.calendar.index',
        'customer.workspaces.businesses.automations.workflows.index',
        'customer.workspaces.businesses.seo.keywords.index',
        'customer.workspaces.businesses.ads.index',
        'customer.workspaces.businesses.ads.settings',
        'customer.workspaces.businesses.ads.meta.settings',
    ];

    /** @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace} an Agency whose own Business is still Draft */
    private function draftAgency(): array
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Operations', 'Northwind Agency');
        $own->forceFill(['status' => BusinessStatus::Draft])->save();

        return [$owner, $own->fresh(), $workspace];
    }

    private function enable($workspace)
    {
        return $this->post(route('customer.workspaces.agency.operations.enable', $workspace->uid));
    }

    public function test_the_owner_turns_on_a_draft_agency_business_and_the_agency_modules_open(): void
    {
        [$owner, $own, $workspace] = $this->draftAgency();
        $this->authenticateAs($owner);
        $this->switchToAccount($workspace);

        $before = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->assertContains('agency-operations-setup', $before);
        $this->assertNotContains('contacts', $before);
        $this->assertContains('accounts', $before, 'Clients stays primary.');

        $this->enable($workspace)->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $own->fresh()->status);
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count(), 'Still exactly one Business.');

        $html = $this->home()->assertOk()->getContent();
        $after = $this->menuKeys($html);
        $this->assertNotContains('agency-operations-setup', $after);
        foreach (['own-business', 'opportunities', 'contacts', 'calendar', 'automations', 'seo', 'ads', 'accounts', 'prospecting',
            'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'team', 'settings'] as $key) {
            $this->assertContains($key, $after, "[{$key}] must be reachable once the agency workspace is on.");
        }
        $this->assertSame(array_values(array_unique($after)), $after, 'No entry is defined twice.');
        $this->assertStringContainsString('Contacts &amp; CRM', $this->sidebarHtml($html));
    }

    public function test_it_creates_the_agency_business_when_there_is_none_and_is_idempotent(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user, ['name' => 'Bare Agency']);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);
        $this->authenticateAs($owner);

        $this->assertSame(0, Business::query()->where('workspace_id', $workspace->id)->count());

        $this->enable($workspace)->assertRedirect(route('user.home'));
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertSame('Bare Agency', $business->name);
        $this->assertNotNull($business->primaryLocation);

        $business->forceFill(['name' => 'Renamed By Owner'])->save();
        $this->enable($workspace)->assertRedirect(route('user.home'));

        $again = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($business->id, $again->id);
        $this->assertSame('Renamed By Owner', $again->name, 'A second call never overwrites the owner\'s facts.');
        $this->assertSame(1, $again->locations()->count());
    }

    public function test_only_the_agency_owner_may_turn_it_on(): void
    {
        [$owner, $own, $workspace] = $this->draftAgency();
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin);
        $outsider = $this->createCustomer();

        foreach (['an Agency admin' => $admin, 'an unrelated customer' => $outsider] as $label => $actor) {
            $this->authenticateAs($actor);
            $this->enable($workspace)->assertNotFound();
            $this->assertSame(BusinessStatus::Draft, $own->fresh()->status, "{$label} must not activate the Agency Business.");
        }

        $this->authenticateAs($admin);
        $this->assertNotContains('agency-operations-setup', $this->menuKeys($this->home()->assertOk()->getContent()), 'Never offered to a non-owner.');
    }

    public function test_a_non_agency_workspace_cannot_use_it_and_gains_no_agency_entitlement(): void
    {
        [$owner, $own, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Co', 'Growth Workspace');
        $own->forceFill(['status' => BusinessStatus::Draft])->save();
        $this->authenticateAs($owner);

        $this->enable($workspace)->assertNotFound();
        $this->assertSame(BusinessStatus::Draft, $own->fresh()->status);
    }

    public function test_a_managed_client_workspace_cannot_be_activated_by_its_agency_or_by_itself(): void
    {
        $managed = $this->createAgencyManagedClient();
        $client = $managed['clientBusiness'];
        $client->forceFill(['status' => BusinessStatus::Draft])->save();

        foreach ([$managed['agencyOwner'], $managed['clientOwner']] as $actor) {
            $this->authenticateAs($actor);
            $this->enable($managed['clientWorkspace'])->assertNotFound();
        }

        $this->assertSame(BusinessStatus::Draft, $client->fresh()->status);
    }

    public function test_it_is_prohibited_while_viewing_as_a_client(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$managed['agencyWorkspace']->uid, $managed['clientWorkspace']->uid]))->assertRedirect();
        $managed['agencyBusiness']->forceFill(['status' => BusinessStatus::Draft])->save();

        $this->enable($managed['agencyWorkspace']);

        $this->assertSame(BusinessStatus::Draft, $managed['agencyBusiness']->fresh()->status);
    }

    public function test_the_agency_business_is_not_a_billable_client_and_clients_still_list_only_managed_accounts(): void
    {
        $managed = $this->createAgencyManagedClient(agencyBusinessName: 'Northwind Operations Internal', clientBusinessName: 'Managed Client Bakery');
        $managed['agencyBusiness']->forceFill(['status' => BusinessStatus::Draft])->save();
        $this->authenticateAs($managed['agencyOwner']);

        $relationshipsBefore = AgencyClientWorkspaceRelationship::query()->count();
        $this->enable($managed['agencyWorkspace'])->assertRedirect();

        $this->assertSame($relationshipsBefore, AgencyClientWorkspaceRelationship::query()->count(), 'Enabling creates no client relationship.');
        $this->assertSame(0, AgencyClientWorkspaceRelationship::query()
            ->where('client_workspace_id', $managed['agencyWorkspace']->id)->count());

        $clients = $this->get(route('customer.workspaces.clients.index', $managed['agencyWorkspace']->uid))->assertOk()->getContent();
        // The shell may name the agency's own Business; the Clients section itself lists managed accounts only.
        $this->assertSame(1, preg_match('/<section id="agency-clients-index">.*?<\/section>/s', $clients, $section));
        $this->assertStringContainsString('Managed Client Bakery', $section[0]);
        $this->assertStringNotContainsString('Northwind Operations Internal', $section[0], 'The agency workspace is not a client row.');

        $this->get(route('customer.workspaces.agency.saas.revenue', $managed['agencyWorkspace']->uid))->assertOk();
    }

    public function test_agency_modules_open_for_the_own_business_and_never_for_a_managed_clients_business(): void
    {
        $managed = $this->createAgencyManagedClient();
        $agency = $managed['agencyWorkspace'];
        $own = $managed['agencyBusiness'];
        $this->authenticateAs($managed['agencyOwner']);

        foreach (self::MODULE_ROUTES as $route) {
            $this->get(route($route, [$agency->uid, $own->uid]))->assertOk();

            // Outside View As the agency owner has no standing in the client's Workspace or Business,
            // whichever address (the client's own, or the client's Business under the agency's) is tried.
            $this->get(route($route, [$managed['clientWorkspace']->uid, $managed['clientBusiness']->uid]))->assertNotFound();
            $this->get(route($route, [$agency->uid, $managed['clientBusiness']->uid]))->assertNotFound();
        }
    }

    public function test_an_unrelated_agencys_owner_cannot_open_this_agencys_modules(): void
    {
        $managed = $this->createAgencyManagedClient();
        [$otherOwner] = $this->tenant(WorkspacePlanTier::Agency, 'Other Agency Business', 'Other Agency');
        $this->authenticateAs($otherOwner);

        foreach (self::MODULE_ROUTES as $route) {
            $this->get(route($route, [$managed['agencyWorkspace']->uid, $managed['agencyBusiness']->uid]))->assertNotFound();
        }
    }

    public function test_agency_team_access_follows_role_and_business_scope(): void
    {
        $managed = $this->createAgencyManagedClient();
        $agency = $managed['agencyWorkspace'];
        $own = $managed['agencyBusiness'];

        $staff = $this->createCustomer();
        $this->member($agency, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $selectedUnassigned = $this->createCustomer();
        $this->member($agency, $selectedUnassigned->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);

        $selectedAssigned = $this->createCustomer();
        $this->assign($this->member($agency, $selectedAssigned->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $own);

        $inactive = $this->createCustomer();
        $this->member($agency, $inactive->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, false);

        $contacts = route('customer.workspaces.businesses.people.index', [$agency->uid, $own->uid]);
        $clientContacts = route('customer.workspaces.businesses.people.index', [$managed['clientWorkspace']->uid, $managed['clientBusiness']->uid]);

        $this->authenticateAs($staff);
        $this->get($contacts)->assertOk();
        $this->get($clientContacts)->assertNotFound();

        $this->authenticateAs($selectedAssigned);
        $this->get($contacts)->assertOk();

        $this->authenticateAs($selectedUnassigned);
        $this->get($contacts)->assertNotFound();

        $this->authenticateAs($inactive);
        $this->get($contacts)->assertNotFound();
    }

    public function test_existing_client_business_behaviour_is_unchanged(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['clientOwner']);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->assertContains('contacts', $keys);
        foreach (['agency-operations-setup', 'own-business', 'agency', 'accounts'] as $agencyOnly) {
            $this->assertNotContains($agencyOnly, $keys);
        }
        $this->get(route('customer.workspaces.businesses.people.index', [$managed['clientWorkspace']->uid, $managed['clientBusiness']->uid]))->assertOk();
    }
}
