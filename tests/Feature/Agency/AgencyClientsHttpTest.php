<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\ClientInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AgencyClientProvisioningManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\ClientInvitationManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\ClientWorkspaceInvitation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Workspace\ClientInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08A — the Agency-facing Clients list/detail
 * screen and its View As entry point. Every test proves the controller
 * delegates to the existing canonical managers (AgencyClientRelationshipManager,
 * ViewAsManager, ClientInvitationManager) rather than reimplementing any
 * authorization or data-sourcing decision of its own.
 */
class AgencyClientsHttpTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists, matching every other Contract 01/04/07 test in this
        // suite — EloquentAccountRepository::hasPermission() short-circuits
        // that id to "every permission", which would invalidate every
        // denial asserted below.
        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();

        Notification::fake();
    }

    private function relationships(): AgencyClientRelationshipManager
    {
        return app(AgencyClientRelationshipManager::class);
    }

    private function invitations(): ClientInvitationManager
    {
        return app(ClientInvitationManager::class);
    }

    /** @return array{0: Workspace, 1: User} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    private function memberOf(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode([]);
        $customer->save();

        $this->createMembership($workspace, $customer->user, [
            'role' => $role,
            'is_active' => $active,
        ]);

        return $customer->user->fresh();
    }

    private function outsider(): User
    {
        return $this->createCustomer()->user->fresh();
    }

    /**
     * The customer portal's own generic baseline gate (`can:access_backend`,
     * routes/customer.php's middleware group) reads the SESSION 'permissions'
     * key, not the Customer.permissions DB column — mirroring
     * CreatesCustomerContextFixtures::authenticateAs()'s own convention
     * exactly. This is a baseline "may use the customer portal at all" check,
     * unrelated to this contract's own Agency authority policy (which is
     * membership-alone, asserted separately inside the controller), so it is
     * primed identically for every actor in this file, including the ones
     * expected to be refused by Contract 01's canonical authority check.
     */
    private function actingAsCustomer(User $user): static
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $this->allCustomerPermissions()))]);

        return $this->actingAs($user);
    }

    /** @return array{0: Workspace, 1: Business, 2: User} */
    private function clientAccount(string $name = 'Alpha Dental'): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, $name . ' Clinic', $name);

        return [$workspace, $business, $customer->user->fresh()];
    }

    private function link(Workspace $agency, User $owner, Workspace $client): AgencyClientWorkspaceRelationship
    {
        return $this->relationships()->create((int) $owner->id, $agency, $client);
    }

    private function terminate(AgencyClientWorkspaceRelationship $relationship, User $owner): AgencyClientWorkspaceRelationship
    {
        return $this->relationships()->terminate((int) $owner->id, $relationship, 'Test: relationship terminated.');
    }

    /**
     * Drives an Agency Workspace's account into a lifecycle state through
     * EntitlementManager's own writers — never by poking lifecycle columns
     * directly. Matches AgencyClientRelationshipManagerTest/AgencyViewAsTest's
     * own putWorkspaceInto()/putAgencyInto() convention.
     */
    private function putAgencyInto(string $state, Workspace $agency): void
    {
        $entitlements = app(EntitlementManager::class);

        match ($state) {
            'grace' => $entitlements->enterGracePeriod($agency),
            'locked' => [$entitlements->enterGracePeriod($agency), $entitlements->lockForNonPayment($agency)],
            'inactive' => $entitlements->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Test: plan made inactive.'),
            'suspended' => $entitlements->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Test: plan suspended.'),
        };

        app(RequestScopedCache::class)->flush();
    }

    private function indexUrl(Workspace $agency): string
    {
        return route('customer.workspaces.clients.index', $agency->uid);
    }

    private function showUrl(Workspace $agency, Workspace $client): string
    {
        return route('customer.workspaces.clients.show', [$agency->uid, $client->uid]);
    }

    private function viewAsUrl(Workspace $agency, Workspace $client): string
    {
        return route('customer.workspaces.clients.view-as', [$agency->uid, $client->uid]);
    }

    private function inviteUrl(Workspace $agency): string
    {
        return route('customer.workspaces.client-invitations.store', $agency->uid);
    }

    private function activateUrl(Workspace $client, Business $business): string
    {
        return route('customer.workspaces.businesses.activate.store', [$client->uid, $business->uid]);
    }

    /**
     * A real client owner submitting real values for the exact facts
     * AgencyClientProvisioningManager::accept() placeholdered — none of
     * industry Other / country US / timezone UTC / currency USD / the
     * address-less storefront location survive this payload unchanged.
     *
     * @return array<string, mixed>
     */
    private function validActivationPayload(): array
    {
        return [
            'industry' => \App\Enums\Business\BusinessIndustry::ProfessionalServices->value,
            'country_code' => 'ca',
            'timezone' => 'America/Toronto',
            'currency_code' => 'cad',
            'location_name' => 'Main Office',
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Toronto',
            'region' => 'ON',
            'postal_code' => 'M5V 2T6',
            'location_country_code' => 'ca',
            'public_address' => '1',
            'confirm' => '1',
        ];
    }

    // ------------------------------------------------------------------
    // AUTHORITY
    // ------------------------------------------------------------------

    public function test_the_agency_owner_can_open_the_index_and_a_linked_clients_detail(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertOk();
        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))->assertOk();
    }

    public function test_an_active_agency_admin_is_allowed(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin)->get($this->indexUrl($agency))->assertOk();
        $this->actingAsCustomer($admin)->get($this->showUrl($agency, $client))->assertOk();
    }

    public function test_an_active_agency_staff_member_is_allowed(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $staff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);

        $this->actingAsCustomer($staff)->get($this->indexUrl($agency))->assertOk();
        $this->actingAsCustomer($staff)->get($this->showUrl($agency, $client))->assertOk();
    }

    public function test_an_inactive_member_is_refused(): void
    {
        [$agency] = $this->agency();
        $inactiveAdmin = $this->memberOf($agency, WorkspaceMembershipRole::Admin, false);

        $this->actingAsCustomer($inactiveAdmin)->get($this->indexUrl($agency))->assertNotFound();
    }

    public function test_a_removed_member_is_refused(): void
    {
        [$agency] = $this->agency();
        $formerStaff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);
        DB::table('workspace_memberships')
            ->where('workspace_id', $agency->id)
            ->where('user_id', $formerStaff->id)
            ->delete();

        $this->actingAsCustomer($formerStaff)->get($this->indexUrl($agency))->assertNotFound();
    }

    public function test_a_member_of_a_different_agency_only_is_refused(): void
    {
        [$agencyA] = $this->agency('Agency A');
        [$agencyB] = $this->agency('Agency B');
        $memberOfB = $this->memberOf($agencyB, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($memberOfB)->get($this->indexUrl($agencyA))->assertNotFound();
    }

    public function test_an_unrelated_actor_is_refused(): void
    {
        [$agency] = $this->agency();
        $outsider = $this->outsider();

        $this->actingAsCustomer($outsider)->get($this->indexUrl($agency))->assertNotFound();
    }

    public function test_client_side_membership_alone_is_refused(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);
        $clientMember = $this->memberOf($client, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($clientMember)->get($this->indexUrl($agency))->assertNotFound();
    }

    public function test_platform_admin_status_alone_does_not_qualify(): void
    {
        [$agency] = $this->agency();

        $platformAdmin = User::find($this->platformAdminId());

        $this->actingAsCustomer($platformAdmin)->get($this->indexUrl($agency))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // AGENCY ELIGIBILITY
    // ------------------------------------------------------------------

    public function test_an_active_agency_is_allowed(): void
    {
        [$agency, $owner] = $this->agency();

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertOk();
    }

    public function test_an_agency_in_grace_is_allowed(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('grace', $agency);

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertOk();
    }

    /**
     * The Agency owner also always passes AccountFrameAccess (owner of
     * their own Workspace), so CustomerAccountAccessGate — the codebase's
     * OWN canonical lifecycle-lock decision, already running earlier in
     * the middleware pipeline for every {workspaceUid} route — intercepts
     * first with its own redirect to the locked screen, for exactly the
     * same reason the ACCOUNT/RELATIONSHIP LIFECYCLE section requires:
     * this surface "must fail through the canonical existing eligibility
     * decision." The refusal is real either way; test_a_selected_scope_staff_member_is_still_refused_by_the_controllers_own_eligibility_check()
     * below proves this controller's OWN explicit eligibility check is
     * what refuses an actor the account gate does not intercept.
     */
    public function test_a_locked_agency_is_refused(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('locked', $agency);

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_an_inactive_agency_is_refused(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('inactive', $agency);

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_a_suspended_agency_is_refused(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('suspended', $agency);

        $this->actingAsCustomer($owner)->get($this->indexUrl($agency))->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_a_non_agency_tier_workspace_is_refused(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Core Co']);
        $this->assignTier($workspace, WorkspacePlanTier::Core);

        $this->actingAsCustomer($customer->user->fresh())->get($this->indexUrl($workspace))->assertNotFound();
    }

    /**
     * A Selected-scope Staff member has Contract 01 Agency AUTHORITY
     * (membership-role-based, per actorHasAgencyAuthority()) but does NOT
     * pass AccountFrameAccess (owner-or-active-ALL-scope-member only), so
     * CustomerAccountAccessGate treats this route as "foreign, pass
     * through unevaluated" (its own documented behavior) and never
     * intercepts. This controller's OWN
     * assertAgencyWorkspaceHasManagementEligibility() call is therefore
     * the ONLY thing refusing this actor once the Agency is Locked — the
     * proof that this contract's own explicit eligibility requirement is
     * real, not merely incidental to the account gate's separate check.
     */
    public function test_a_selected_scope_staff_member_is_still_refused_by_the_controllers_own_eligibility_check(): void
    {
        [$agency] = $this->agency();
        $selectedScopeStaff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);
        DB::table('workspace_memberships')
            ->where('workspace_id', $agency->id)
            ->where('user_id', $selectedScopeStaff->id)
            ->update(['business_access_scope' => \App\Enums\Workspace\WorkspaceBusinessAccessScope::Selected->value]);

        $this->putAgencyInto('locked', $agency);

        $this->actingAsCustomer($selectedScopeStaff)->get($this->indexUrl($agency))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // LIST ISOLATION
    // ------------------------------------------------------------------

    public function test_the_list_shows_only_this_agencys_active_clients(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientA] = $this->clientAccount('Client A');
        [$clientB] = $this->clientAccount('Client B');
        $this->link($agencyA, $ownerA, $clientA);
        $this->link($agencyB, $ownerB, $clientB);

        $response = $this->actingAsCustomer($ownerA)->get($this->indexUrl($agencyA));

        $response->assertOk();
        $response->assertSee($clientA->name);
        $response->assertDontSee($clientB->name);
    }

    public function test_a_terminated_relationship_is_absent_from_the_active_list(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount('Former Client');
        $relationship = $this->link($agency, $owner, $client);
        $this->terminate($relationship, $owner);

        $response = $this->actingAsCustomer($owner)->get($this->indexUrl($agency));

        $response->assertOk();
        $response->assertDontSee('Former Client');
    }

    public function test_an_unrelated_workspace_never_appears_in_the_list(): void
    {
        [$agency, $owner] = $this->agency();
        [$linkedClient] = $this->clientAccount('Linked Client');
        $this->link($agency, $owner, $linkedClient);
        [$unrelatedWorkspace] = $this->clientAccount('Unrelated Workspace');

        $response = $this->actingAsCustomer($owner)->get($this->indexUrl($agency));

        $response->assertOk();
        $response->assertSee('Linked Client');
        $response->assertDontSee('Unrelated Workspace');
    }

    // ------------------------------------------------------------------
    // DETAIL IDOR
    // ------------------------------------------------------------------

    public function test_the_linked_clients_detail_page_opens(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $response = $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client));

        $response->assertOk();
        $response->assertSee($business->name);
    }

    public function test_another_agencys_client_cannot_be_opened_by_changing_the_url(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientB] = $this->clientAccount('Client B');
        $this->link($agencyB, $ownerB, $clientB);

        $this->actingAsCustomer($ownerA)->get($this->showUrl($agencyA, $clientB))->assertNotFound();
    }

    public function test_an_unrelated_workspace_with_no_relationship_cannot_be_opened(): void
    {
        [$agency, $owner] = $this->agency();
        [$unrelatedWorkspace] = $this->clientAccount();

        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $unrelatedWorkspace))->assertNotFound();
    }

    public function test_a_terminated_relationships_detail_page_is_refused(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $relationship = $this->link($agency, $owner, $client);
        $this->terminate($relationship, $owner);

        $this->actingAsCustomer($owner)->get($this->showUrl($agency, $client))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // INVITE — reuses the existing Contract 07 endpoint, unmodified
    // ------------------------------------------------------------------

    public function test_the_owner_can_submit_an_invite_from_the_agency_clients_context(): void
    {
        [$agency, $owner] = $this->agency();

        $response = $this->actingAsCustomer($owner)->post($this->inviteUrl($agency), [
            'email' => 'newclient' . uniqid('', true) . '@example.test',
            'intended_business_name' => 'Fresh Start Co',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, ClientWorkspaceInvitation::count());
        $this->assertSame(ClientInvitationStatus::Pending, ClientWorkspaceInvitation::first()->status);
    }

    public function test_an_active_admin_can_submit_an_invite(): void
    {
        [$agency] = $this->agency();
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);

        $this->actingAsCustomer($admin)->post($this->inviteUrl($agency), [
            'email' => 'newclient' . uniqid('', true) . '@example.test',
            'intended_business_name' => 'Fresh Start Co',
        ])->assertRedirect();

        $this->assertSame(1, ClientWorkspaceInvitation::count());
    }

    public function test_an_active_staff_member_can_submit_an_invite(): void
    {
        [$agency] = $this->agency();
        $staff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);

        $this->actingAsCustomer($staff)->post($this->inviteUrl($agency), [
            'email' => 'newclient' . uniqid('', true) . '@example.test',
            'intended_business_name' => 'Fresh Start Co',
        ])->assertRedirect();

        $this->assertSame(1, ClientWorkspaceInvitation::count());
    }

    public function test_sending_an_invite_creates_no_workspace_business_or_location(): void
    {
        [$agency, $owner] = $this->agency();
        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        $this->actingAsCustomer($owner)->post($this->inviteUrl($agency), [
            'email' => 'newclient' . uniqid('', true) . '@example.test',
            'intended_business_name' => 'Fresh Start Co',
        ])->assertRedirect();

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
    }

    public function test_the_invite_form_has_no_client_password_field(): void
    {
        [$agency, $owner] = $this->agency();

        $response = $this->actingAsCustomer($owner)->get($this->indexUrl($agency));

        $response->assertOk();
        $response->assertDontSee('name="password"', false);
        $response->assertDontSee('client password', false);
    }

    public function test_an_ineligible_agency_cannot_invite(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('locked', $agency);

        $response = $this->actingAsCustomer($owner)->post($this->inviteUrl($agency), [
            'email' => 'newclient' . uniqid('', true) . '@example.test',
            'intended_business_name' => 'Fresh Start Co',
        ]);

        $this->assertSame(0, ClientWorkspaceInvitation::count());
        $response->assertRedirect();
    }

    // ------------------------------------------------------------------
    // VIEW AS
    // ------------------------------------------------------------------

    /** Every Business created via the tenant()/addBusiness() fixture is forced Active — reusable directly for View As. */
    public function test_view_as_starts_a_contract_04_session_and_redirects_correctly(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $response = $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $client));

        $response->assertRedirect(route('user.home'));

        $session = app(ViewAsManager::class)->current($owner->fresh());
        $this->assertNotNull($session);
        $this->assertSame((int) $client->id, (int) $session->workspaceId);
    }

    public function test_a_cross_agency_crafted_client_id_is_refused(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientB] = $this->clientAccount('Client B');
        $this->link($agencyB, $ownerB, $clientB);

        $response = $this->actingAsCustomer($ownerA)->post($this->viewAsUrl($agencyA, $clientB));

        $response->assertNotFound();
        $this->assertNull(app(ViewAsManager::class)->current($ownerA->fresh()));
    }

    public function test_a_terminated_relationship_refuses_view_as(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $relationship = $this->link($agency, $owner, $client);
        $this->terminate($relationship, $owner);

        $response = $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $client));

        $response->assertNotFound();
        $this->assertNull(app(ViewAsManager::class)->current($owner->fresh()));
    }

    public function test_view_as_creates_no_client_workspace_membership(): void
    {
        [$agency, $owner] = $this->agency();
        [$client] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $client))->assertRedirect();

        $membershipExists = DB::table('workspace_memberships')
            ->where('workspace_id', $client->id)
            ->where('user_id', $owner->id)
            ->exists();

        $this->assertFalse($membershipExists);
    }

    public function test_view_as_grants_no_agencyrebill_authority(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        $this->link($agency, $owner, $client);

        $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $client))->assertRedirect();

        $this->assertFalse(BusinessPayerAssignment::where('business_id', $business->id)->exists());
    }

    // ------------------------------------------------------------------
    // CONTRACT 07 INTEGRATION — accepted invitation appears in the list
    // ------------------------------------------------------------------

    /**
     * Newly-invited-client flow fix — the corrected full path this task
     * exists to prove: invitation accepted -> Draft -> the Agency's list
     * shows "waiting", View As genuinely 404s while Draft (the existing
     * guard, untouched) -> the CLIENT OWNER (never the Agency) reviews and
     * confirms the real details themselves -> Active -> the Agency's list
     * shows a real button -> Agency View As succeeds.
     */
    public function test_the_full_path_from_accepted_invitation_through_client_activation_to_agency_view_as(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'accepted-client' . uniqid('', true) . '@example.test';

        $invitation = $this->invitations()->send((int) $owner->id, $agency, $email, 'Brand New Co');
        $plaintextToken = $this->capturedToken($invitation);

        $newUser = User::create([
            'first_name' => 'New',
            'last_name' => 'Client',
            'email' => $email,
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);
        Customer::create(['user_id' => $newUser->id]);

        $accepted = app(AgencyClientProvisioningManager::class)->accept($newUser->fresh(), $invitation->uid, $plaintextToken);
        $clientWorkspace = Workspace::find($accepted->created_client_workspace_id);
        $business = Business::where('workspace_id', $clientWorkspace->id)->firstOrFail();

        $this->assertSame(
            \App\Enums\Business\BusinessStatus::Draft,
            $business->status,
            'Acceptance creates the Business Draft and never activates it (Agency users must never activate a client\'s Business).',
        );

        // The list surfaces the wait honestly, never a button that 404s.
        $listResponse = $this->actingAsCustomer($owner)->get($this->indexUrl($agency));
        $listResponse->assertOk();
        $listResponse->assertSee($clientWorkspace->name);
        $listResponse->assertSee('Waiting for client setup');

        // The existing View As eligibility check and its 404 are untouched.
        $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $clientWorkspace))->assertNotFound();

        // The client owner — not the Agency — reviews and confirms the
        // real details themselves.
        $activateResponse = $this->actingAsCustomer($newUser->fresh())
            ->post($this->activateUrl($clientWorkspace, $business), $this->validActivationPayload());
        $activateResponse->assertRedirect(route('customer.workspaces.show', $clientWorkspace->uid));

        $business->refresh();
        $this->assertSame(\App\Enums\Business\BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
        $this->assertSame('CA', $business->country_code);
        $this->assertSame('CAD', $business->currency_code);

        $listAfter = $this->actingAsCustomer($owner)->get($this->indexUrl($agency));
        $listAfter->assertOk();
        $listAfter->assertDontSee('Waiting for client setup');

        $viewAsResponse = $this->actingAsCustomer($owner)->post($this->viewAsUrl($agency, $clientWorkspace));
        $viewAsResponse->assertRedirect(route('user.home'));

        $session = app(ViewAsManager::class)->current($owner->fresh());
        $this->assertNotNull($session);
        $this->assertSame((int) $clientWorkspace->id, (int) $session->workspaceId);
    }

    /**
     * Agency users must never activate a client's Business — the Agency
     * owner is never that Client Workspace's own owner_user_id
     * (WorkspaceManager::createWorkspace((int) $authenticatedUser->id, ...)
     * in AgencyClientProvisioningManager::accept() gives it to the
     * accepting client alone), so
     * ClientBusinessActivationController::resolveOwnedWorkspace() refuses
     * identically to an unrelated stranger.
     */
    public function test_the_inviting_agency_cannot_activate_the_clients_business(): void
    {
        [$agency, $owner] = $this->agency();
        [$client, $business] = $this->clientAccount();
        DB::table('businesses')->where('id', $business->id)->update(['status' => \App\Enums\Business\BusinessStatus::Draft->value]);
        $this->link($agency, $owner, $client);

        $response = $this->actingAsCustomer($owner)->post($this->activateUrl($client, $business), $this->validActivationPayload());

        $response->assertNotFound();
        $this->assertSame(\App\Enums\Business\BusinessStatus::Draft, $business->fresh()->status, 'A refused attempt never changes status.');
    }

    public function test_an_unrelated_actor_cannot_activate_a_clients_business(): void
    {
        [$client, $business] = $this->clientAccount();
        DB::table('businesses')->where('id', $business->id)->update(['status' => \App\Enums\Business\BusinessStatus::Draft->value]);
        $stranger = $this->outsider();

        $response = $this->actingAsCustomer($stranger)->post($this->activateUrl($client, $business), $this->validActivationPayload());

        $response->assertNotFound();
        $this->assertSame(\App\Enums\Business\BusinessStatus::Draft, $business->fresh()->status);
    }

    /** Requires Notification::fake() to already be active. */
    private function capturedToken(ClientWorkspaceInvitation $invitation): string
    {
        $captured = null;

        Notification::assertSentOnDemand(
            ClientInvitationNotification::class,
            function (ClientInvitationNotification $notification, array $channels, object $notifiable) use ($invitation, &$captured) {
                $ref = new ReflectionClass($notification);

                $uidProperty = $ref->getProperty('invitationUid');
                $uidProperty->setAccessible(true);

                if ($uidProperty->getValue($notification) !== $invitation->uid) {
                    return false;
                }

                $tokenProperty = $ref->getProperty('plaintextToken');
                $tokenProperty->setAccessible(true);
                $captured = $tokenProperty->getValue($notification);

                return true;
            },
        );

        $this->assertNotNull($captured, 'Expected a ClientInvitationNotification to have been sent for this invitation.');

        return $captured;
    }
}
