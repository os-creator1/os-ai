<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\ViewAs\ViewAsManager;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * R2B — the OPERATIONAL authority seams beneath a cross-Workspace Agency
 * View As.
 *
 * PR #323 made Business-addressed ROUTES and the shell resolve the viewed
 * Client correctly. Three services underneath them still asked the older
 * question — "is the real actor an ordinary tenant/member of this Client
 * Workspace?" — which is false by design for an Agency actor and must stay
 * false (WorkspaceManager::userCanAccessBusiness() is ordinary tenancy
 * authority and nothing else). So a legitimate session reached the right
 * route and was then refused by:
 *
 *   1. BusinessLocationManager::canManage() — physical-location CRUD;
 *   2. LocationAccessGuard::userCanAccessLocation() — the Location ACL that
 *      Conversations, Contacts and CRM Opportunities all consume;
 *   3. ContactsController::currentBusinessContext() — Business-addressed
 *      Contacts routes.
 *
 * All three now compose the one canonical target
 * (BusinessRouteAccess::viewedBusinessIdFor(), which asks
 * ViewAsManager::current() and nothing else). What this file proves is the
 * pair of properties that makes that safe: the viewed Client's own Business
 * and Locations work exactly as the Client's would, and NOTHING else does —
 * including Businesses and Locations the real Agency actor genuinely owns,
 * because View As narrows in both directions. No membership is fabricated,
 * ordinary tenancy is unmoved, and money/slot authority stays prohibited.
 */
class AgencyViewAsOperationalAuthorityTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator: that id
        // short-circuits to "every permission" and would void every denial.
        $this->platformAdminId();
    }

    // -----------------------------------------------------------------
    // 1–3, 11. Physical locations through the real HTTP/controller/service
    //          path, as the viewed Client, audited as the Agency actor
    // -----------------------------------------------------------------

    public function test_view_as_opens_the_viewed_clients_business_locations_index(): void
    {
        $fx = $this->viewingFixture();

        $this->get($this->locationRoute('index', $fx))
            ->assertOk()
            ->assertSee($fx['clientLocation']->name, false);
    }

    public function test_view_as_can_create_a_location_for_the_viewed_client_through_http(): void
    {
        $fx = $this->viewingFixture();

        $this->post($this->locationRoute('store', $fx), $this->locationAttributes('Harbor Annexe'))
            ->assertRedirect($this->locationRoute('index', $fx));

        $created = BusinessLocation::query()
            ->where('business_id', $fx['clientBusiness']->id)
            ->where('name', 'Harbor Annexe')
            ->sole();

        $this->assertSame((int) $fx['clientBusiness']->id, (int) $created->business_id);
    }

    public function test_view_as_can_update_archive_reactivate_and_reprimary_the_viewed_clients_locations(): void
    {
        $fx = $this->viewingFixture();
        $second = app(BusinessLocationManager::class)->createLocation(
            $fx['clientBusiness'],
            $this->locationAttributes('Second Site'),
            (int) $fx['clientOwner']->user_id,
        );

        $this->post($this->locationRoute('update', $fx, $second), $this->locationAttributes('Second Site Renamed'))
            ->assertRedirect();
        $this->assertSame('Second Site Renamed', $second->fresh()->name);

        // Make the second primary, then archive the one that is no longer it.
        $this->post($this->locationRoute('primary', $fx, $second))->assertRedirect();
        $this->assertTrue((bool) $second->fresh()->is_primary);

        $this->post($this->locationRoute('archive', $fx, $fx['clientLocation']))->assertRedirect();
        $this->assertNotNull($fx['clientLocation']->fresh()->archived_at);

        $this->post($this->locationRoute('reactivate', $fx, $fx['clientLocation']))->assertRedirect();
        $this->assertNull($fx['clientLocation']->fresh()->archived_at);
    }

    public function test_the_real_agency_actor_stays_the_audited_actor_while_viewing(): void
    {
        $fx = $this->viewingFixture();

        $this->post($this->locationRoute('store', $fx), $this->locationAttributes('Audited Site'))->assertRedirect();

        // Identity is never reassigned by View As: the acting user is still
        // the Agency owner, and the durable session row names them too.
        $this->assertSame((int) $fx['agencyOwner']->user_id, (int) auth()->id());
        $this->assertSame(
            (int) $fx['agencyOwner']->user_id,
            (int) app(ViewAsManager::class)->current($fx['agencyOwner']->user->fresh())->actorUserId,
        );
    }

    // -----------------------------------------------------------------
    // 4–6, 15. The Location ACL: the viewed Client's Locations, and only
    //          those — the Agency's own included in the refusals
    // -----------------------------------------------------------------

    public function test_the_viewed_clients_location_passes_the_location_guard(): void
    {
        $fx = $this->viewingFixture();

        $this->assertTrue(
            app(LocationAccessGuard::class)->userCanAccessLocation((int) $fx['agencyOwner']->user_id, $fx['clientLocation']),
        );
    }

    public function test_a_location_of_another_business_is_refused_while_viewing(): void
    {
        $fx = $this->viewingFixture();

        $this->assertFalse(
            app(LocationAccessGuard::class)->userCanAccessLocation((int) $fx['agencyOwner']->user_id, $fx['foreignLocation']),
            'A Location outside the viewed Business is refused, session or not.',
        );
    }

    public function test_view_as_narrows_in_both_directions_for_locations_and_business_routes(): void
    {
        $fx = $this->viewingFixture();
        $actorId = (int) $fx['agencyOwner']->user_id;
        $guard = app(LocationAccessGuard::class);

        // The Agency actor's OWN Business and its Location — reachable for
        // them ordinarily — are unreachable while a client is being viewed.
        $this->assertFalse(
            $guard->userCanAccessLocation($actorId, $fx['agencyLocation']),
            'View As narrows: even the actor\'s own Location is out of reach while viewing.',
        );
        $this->get($this->businessLocationIndex($fx['agencyWorkspace'], $fx['agencyBusiness']))->assertNotFound();

        // And once the view ends, their own Location is theirs again while
        // the client's becomes unreachable — no residue in either direction.
        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));
        $this->nextRequest();

        $this->assertTrue($guard->userCanAccessLocation($actorId, $fx['agencyLocation']));
        $this->assertFalse($guard->userCanAccessLocation($actorId, $fx['clientLocation']));
        $this->get($this->locationRoute('index', $fx))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // 12–14. Contacts, and Location-bound records of the viewed Business
    // -----------------------------------------------------------------

    public function test_business_scoped_contacts_resolve_for_the_exact_viewed_target(): void
    {
        $fx = $this->viewingFixture();

        $this->get(route('customer.workspaces.businesses.contacts.index', [
            $fx['clientWorkspace']->uid, $fx['clientBusiness']->uid,
        ]))->assertOk();
    }

    public function test_business_scoped_contacts_refuse_every_other_pair_while_viewing(): void
    {
        $fx = $this->viewingFixture();

        // Foreign Workspace + viewed Business.
        $this->get(route('customer.workspaces.businesses.contacts.index', [
            $fx['agencyWorkspace']->uid, $fx['clientBusiness']->uid,
        ]))->assertNotFound();

        // Viewed Workspace + foreign Business.
        $this->get(route('customer.workspaces.businesses.contacts.index', [
            $fx['clientWorkspace']->uid, $fx['foreignBusiness']->uid,
        ]))->assertNotFound();

        // A Business the Agency actor ordinarily owns outright.
        $this->get(route('customer.workspaces.businesses.contacts.index', [
            $fx['agencyWorkspace']->uid, $fx['agencyBusiness']->uid,
        ]))->assertNotFound();
    }

    public function test_a_location_bound_record_of_the_viewed_business_is_not_refused_for_want_of_membership(): void
    {
        $fx = $this->viewingFixture();
        $actorId = (int) $fx['agencyOwner']->user_id;

        // The premise the three consumers (Conversations, Contacts, CRM) all
        // rest on: the guard admits this Location for an actor who holds no
        // Client membership at all, purely because the session names its
        // Business — and refuses the one that belongs elsewhere.
        $this->assertTrue(app(LocationAccessGuard::class)->userCanAccessLocation($actorId, $fx['clientLocation']));
        $this->assertFalse(app(LocationAccessGuard::class)->userCanAccessLocation($actorId, $fx['foreignLocation']));
        $this->assertNoClientMembershipExists($fx);
    }

    // -----------------------------------------------------------------
    // 7–9. Fail closed the moment the session stops being valid
    // -----------------------------------------------------------------

    public function test_a_terminated_relationship_closes_locations_and_contacts_on_the_next_request(): void
    {
        $fx = $this->viewingFixture();
        $this->get($this->locationRoute('index', $fx))->assertOk();

        app(AgencyClientRelationshipManager::class)->terminate(
            (int) $fx['agencyOwner']->user_id,
            $fx['relationship'],
            'Test: relationship ended mid-session.',
        );
        $this->nextRequest();

        $this->assertViewedClientIsClosed($fx);
    }

    public function test_losing_agency_management_eligibility_closes_the_next_request(): void
    {
        $fx = $this->viewingFixture();
        $this->get($this->locationRoute('index', $fx))->assertOk();

        app(EntitlementManager::class)->changePlanStatus(
            $fx['agencyWorkspace'],
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Test: agency plan suspended mid-session.',
        );
        $this->nextRequest();

        $this->assertFalse(
            app(LocationAccessGuard::class)->userCanAccessLocation((int) $fx['agencyOwner']->user_id, $fx['clientLocation']),
        );
        $this->assertFalse(
            app(BusinessLocationManager::class)->canManage((int) $fx['agencyOwner']->user_id, $fx['clientBusiness']),
        );
    }

    public function test_an_expired_session_leaves_no_client_access_behind(): void
    {
        $fx = $this->viewingFixture();
        $this->get($this->locationRoute('index', $fx))->assertOk();

        $this->travel(ViewAsManager::DEFAULT_TTL_MINUTES + 1)->minutes();

        $this->assertViewedClientIsClosed($fx);

        // Ordinary authority is restored intact — not widened, not lost.
        $this->assertTrue(
            app(LocationAccessGuard::class)->userCanAccessLocation((int) $fx['agencyOwner']->user_id, $fx['agencyLocation']),
        );
    }

    // -----------------------------------------------------------------
    // 10–11, 20. The hard boundaries this correction must not cross
    // -----------------------------------------------------------------

    /**
     * Regression: resolving ANOTHER user's authority mid-request must not
     * disturb the signed-in actor's live session. ViewAsManager::current()
     * looks a session up by uid AND actor_user_id and forgets the session key
     * when that finds nothing — so a service asking "is user X viewing?"
     * about anyone but the authenticated user would silently end the real
     * session. The Client owner's own authority is resolved here, while the
     * Agency actor is signed in and viewing, and the session must survive it.
     */
    public function test_resolving_another_users_authority_does_not_end_the_live_session(): void
    {
        $fx = $this->viewingFixture();
        $manager = app(BusinessLocationManager::class);

        $manager->canManage((int) $fx['clientOwner']->user_id, $fx['clientBusiness']);
        app(LocationAccessGuard::class)->userCanAccessLocation((int) $fx['clientOwner']->user_id, $fx['clientLocation']);

        $this->assertNotNull(
            app(ViewAsManager::class)->current($fx['agencyOwner']->user->fresh()),
            'The signed-in actor\'s session must survive another user\'s authority lookup.',
        );
        $this->assertTrue($manager->canManage((int) $fx['agencyOwner']->user_id, $fx['clientBusiness']));
        $this->get($this->locationRoute('index', $fx))->assertOk();
    }

    public function test_ordinary_tenancy_still_refuses_the_agency_actor_and_no_membership_is_created(): void
    {
        $fx = $this->viewingFixture();
        $actorId = (int) $fx['agencyOwner']->user_id;

        $this->assertFalse(
            app(WorkspaceManager::class)->userCanAccessBusiness($actorId, $fx['clientBusiness']->fresh()),
            'userCanAccessBusiness() must keep answering false for the Agency actor, session or not.',
        );
        $this->assertNoClientMembershipExists($fx);
    }

    public function test_slot_allocation_and_billing_mutations_remain_prohibited_while_viewing(): void
    {
        $classification = app(ViewAsProhibitedActions::class);

        // The physical-location family is allowed BusinessScoped work…
        foreach (['index', 'create', 'store', 'edit', 'update', 'archive', 'reactivate', 'primary'] as $action) {
            $name = 'customer.workspaces.businesses.locations.' . $action;
            $method = in_array($action, ['index', 'create', 'edit'], true) ? 'GET' : 'POST';

            $this->assertSame(
                ViewAsRouteClass::BusinessScoped,
                app(ViewAsRouteClassification::class)->classifyByName($name, $method),
                $name . ' must stay an allowed BusinessScoped action.',
            );
        }

        // …while the separate slot-allocation family and every usage-billing
        // mutation stay prohibited, untouched by this correction. The
        // allocation routes are listed ahead of their arrival, so the
        // inventory itself is the proof.
        $this->assertContains(
            'customer.workspaces.businesses.locations.allocations.',
            ViewAsProhibitedActions::PREFIXES,
        );
        $this->assertContains(
            'customer.workspaces.businesses.usage-billing.',
            ViewAsProhibitedActions::PREFIXES,
        );
        $this->assertTrue($classification->isProhibitedRoute(
            $this->fakeRoute('customer.workspaces.businesses.usage-billing.payer'),
            'POST',
        ));
    }

    // -----------------------------------------------------------------
    // 16–18. Ordinary, non-View-As behaviour is untouched
    // -----------------------------------------------------------------

    public function test_ordinary_owner_and_staff_location_authority_is_unchanged(): void
    {
        $fx = $this->viewingFixture(startView: false);
        $manager = app(BusinessLocationManager::class);
        $guard = app(LocationAccessGuard::class);

        // The Client's own owner manages and reads their locations.
        $this->assertTrue($manager->canManage((int) $fx['clientOwner']->user_id, $fx['clientBusiness']));
        $this->assertTrue($guard->userCanAccessLocation((int) $fx['clientOwner']->user_id, $fx['clientLocation']));

        // An active Staff member of the Client Workspace reads but does not
        // change — the pre-existing rule, unmoved.
        $staff = $this->createCustomer();
        $this->member($fx['clientWorkspace'], $staff->user, WorkspaceMembershipRole::Staff);
        $this->assertFalse($manager->canManage((int) $staff->user_id, $fx['clientBusiness']));
        $this->assertTrue($guard->userCanAccessLocation((int) $staff->user_id, $fx['clientLocation']));

        // An Admin of the Client Workspace manages, as before.
        $admin = $this->createCustomer();
        $this->member($fx['clientWorkspace'], $admin->user, WorkspaceMembershipRole::Admin);
        $this->assertTrue($manager->canManage((int) $admin->user_id, $fx['clientBusiness']));

        // And an unrelated stranger does neither.
        $stranger = $this->createCustomer();
        $this->assertFalse($manager->canManage((int) $stranger->user_id, $fx['clientBusiness']));
        $this->assertFalse($guard->userCanAccessLocation((int) $stranger->user_id, $fx['clientLocation']));

        // The Agency actor, with no session running, is refused outright.
        $this->assertFalse($manager->canManage((int) $fx['agencyOwner']->user_id, $fx['clientBusiness']));
        $this->assertFalse($guard->userCanAccessLocation((int) $fx['agencyOwner']->user_id, $fx['clientLocation']));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A real V1 Agency managing a real V1 Client, each with its own single
     * Business and at least one Location, plus an unrelated third tenant —
     * authenticated as the Agency owner and (by default) already viewing.
     *
     * @return array<string, mixed>
     */
    private function viewingFixture(bool $startView = true): array
    {
        $pair = $this->createAgencyManagedClient(
            clientBusinessName: 'Harbor Lane Studios',
            clientWorkspaceName: 'Harbor Lane',
            agencyBusinessName: 'Northwind House',
            agencyWorkspaceName: 'Northwind Agency',
        );

        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);

        $manager = app(BusinessLocationManager::class);

        $clientLocation = $manager->createLocation(
            $pair['clientBusiness'],
            $this->locationAttributes('Harbor Main'),
            (int) $pair['clientOwner']->user_id,
        );

        $agencyLocation = $manager->createLocation(
            $pair['agencyBusiness'],
            $this->locationAttributes('Northwind HQ'),
            (int) $pair['agencyOwner']->user_id,
        );

        $foreign = $this->createIndependentWorkspaceBusiness(businessName: 'Unrelated Co');
        $foreignLocation = $manager->createLocation(
            $foreign['business'],
            $this->locationAttributes('Unrelated Site'),
            (int) $foreign['customer']->user_id,
        );

        $fx = $pair + [
            'clientLocation' => $clientLocation,
            'agencyLocation' => $agencyLocation,
            'foreignBusiness' => $foreign['business'],
            'foreignWorkspace' => $foreign['workspace'],
            'foreignLocation' => $foreignLocation,
        ];

        $this->nextRequest();
        $this->authenticateAs($pair['agencyOwner']);

        if ($startView) {
            $this->post(route('customer.workspaces.clients.view-as', [
                $pair['agencyWorkspace']->uid,
                $pair['clientWorkspace']->uid,
            ]))->assertRedirect(route('user.home'));
        }

        return $fx;
    }

    /**
     * @param  array<string, mixed>  $fx
     */
    private function locationRoute(string $action, array $fx, ?BusinessLocation $location = null): string
    {
        $parameters = [$fx['clientWorkspace']->uid, $fx['clientBusiness']->uid];

        if ($location !== null) {
            $parameters[] = $location->uid;
        }

        return route('customer.workspaces.businesses.locations.' . $action, $parameters);
    }

    private function businessLocationIndex(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]);
    }

    /**
     * Everything the viewed Client offered is refused again, through both the
     * route surface and the two services beneath it.
     *
     * @param  array<string, mixed>  $fx
     */
    private function assertViewedClientIsClosed(array $fx): void
    {
        $actorId = (int) $fx['agencyOwner']->user_id;

        $this->get($this->locationRoute('index', $fx))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.contacts.index', [
            $fx['clientWorkspace']->uid, $fx['clientBusiness']->uid,
        ]))->assertNotFound();
        $this->assertFalse(app(LocationAccessGuard::class)->userCanAccessLocation($actorId, $fx['clientLocation']));
        $this->assertFalse(app(BusinessLocationManager::class)->canManage($actorId, $fx['clientBusiness']));
    }

    /**
     * @param  array<string, mixed>  $fx
     */
    private function assertNoClientMembershipExists(array $fx): void
    {
        $this->assertSame(
            0,
            WorkspaceMembership::query()
                ->where('workspace_id', $fx['clientWorkspace']->id)
                ->where('user_id', $fx['agencyOwner']->user_id)
                ->count(),
            'View As must never write the Agency actor into the Client Workspace.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function locationAttributes(string $name): array
    {
        return [
            'name' => $name,
            'service_mode' => 'storefront',
            'address_line_1' => '1 ' . $name,
            'city' => 'Springfield',
            'region' => 'IL',
            'postal_code' => '62701',
            'country_code' => 'US',
            'public_address' => true,
        ];
    }

    private function fakeRoute(string $name): \Illuminate\Routing\Route
    {
        return (new \Illuminate\Routing\Route(['POST'], '/fake', []))->name($name);
    }

    private function nextRequest(): void
    {
        app(RequestScopedCache::class)->flush();
    }
}
