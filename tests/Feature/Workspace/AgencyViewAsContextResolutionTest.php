<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Support\RequestScopedCache;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\BusinessRouteAccess;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * V1 Contract 04 / Contract 13 R2 correction — the SHELL half of
 * cross-Workspace Agency View As, and the Business-route authorization half
 * it shares one authority with.
 *
 * THE DEFECT THIS FILE PINS. startAgencyView() creates a genuine session
 * naming the Client Workspace, its sole Business and the Agency Workspace it
 * is authorized through. Redirecting to user.home then resolved the AGENCY'S
 * OWN Business instead, because CustomerContextResolver looked for the viewed
 * Workspace inside CustomerContextSnapshot::forUser() — the actor's ORDINARY
 * tenancy — and required WorkspaceManager::userCanAccessBusiness(). A managed
 * Client Workspace is deliberately neither of those things, so the lookup
 * missed and the resolver fell through to the actor's own account. The agency
 * saw its own Business behind a banner announcing a client.
 *
 * Two rules had to hold at once, and both are proven here:
 *  - ordinary tenancy must NOT widen. userCanAccessBusiness() still answers
 *    false for the Agency actor against the Client Business, and the Client
 *    Workspace must never appear in the ordinary snapshot or switcher;
 *  - the shell and every Business route must nevertheless resolve to, and
 *    serve, the exact viewed target — for exactly as long as
 *    ViewAsManager::current() keeps revalidating the session, and not one
 *    request longer.
 *
 * ViewAsManager::current() is the only authority for that: it re-reads the
 * relationship, the Agency's management eligibility, the actor's Agency
 * authority, both Workspaces' active state and the Client's sole active
 * Business on EVERY request. Nothing here re-implements any of those checks;
 * each loss test simply breaks one persisted fact and asserts the very next
 * request has lost the viewed context.
 */
class AgencyViewAsContextResolutionTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists: id 1 short-circuits to "every permission", which would
        // invalidate every denial below.
        $this->platformAdminId();
    }

    // -----------------------------------------------------------------
    // 1–3. The viewed Client target is what resolves
    // -----------------------------------------------------------------

    public function test_starting_an_agency_view_resolves_the_shell_to_the_client_workspace_and_business(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $context = $this->contextOnHome();

        $this->assertSame(ContextSource::ViewAs, $context->source);
        $this->assertTrue($context->isBusinessFrame());
        $this->assertNotNull($context->selectedWorkspace);
        $this->assertSame((int) $pair['clientWorkspace']->id, $context->selectedWorkspace->id);
        $this->assertNotNull($context->selectedBusiness);
        $this->assertSame((int) $pair['clientBusiness']->id, $context->selectedBusiness->id);
        $this->assertNotNull($context->viewAs);
        $this->assertSame((int) $pair['agencyWorkspace']->id, $context->viewAs->viewingAgencyWorkspaceId);
    }

    public function test_business_home_while_viewing_renders_the_client_business_never_the_agencys_own(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $home = $this->home()->assertOk();

        $home->assertSee($pair['clientBusiness']->name, false);
        $this->assertStringNotContainsString($pair['agencyBusiness']->name, $home->getContent());
    }

    public function test_the_agencys_own_business_is_not_selected_while_viewing(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);

        // Precondition: outside the view the Agency's own Business is exactly
        // what resolves, so the assertion below is about the view, not about
        // an actor who could never reach their own account.
        $before = $this->contextOnHome();
        $this->assertNotNull($before->selectedBusiness);
        $this->assertSame((int) $pair['agencyBusiness']->id, $before->selectedBusiness->id);

        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $context = $this->contextOnHome();

        $this->assertNotNull($context->selectedBusiness);
        $this->assertNotSame((int) $pair['agencyBusiness']->id, $context->selectedBusiness->id);
        $this->assertSame((int) $pair['clientBusiness']->id, $context->selectedBusiness->id);
    }

    // -----------------------------------------------------------------
    // 4–5. A remembered preference can never override the view
    // -----------------------------------------------------------------

    public function test_a_remembered_agency_account_frame_preference_cannot_override_the_view(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->switchToAccount($pair['agencyWorkspace'])->assertRedirect(route('user.home'));
        $this->assertSame(ContextSource::AccountPreference, $this->contextOnHome()->source);

        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $context = $this->contextOnHome();

        $this->assertSame(ContextSource::ViewAs, $context->source);
        $this->assertNotNull($context->selectedBusiness);
        $this->assertSame((int) $pair['clientBusiness']->id, $context->selectedBusiness->id);
    }

    public function test_a_remembered_agency_business_preference_cannot_override_the_view(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->switchTo($pair['agencyWorkspace'], $pair['agencyBusiness'])->assertRedirect(route('user.home'));
        $this->assertSame((int) $pair['agencyBusiness']->id, $this->contextOnHome()->selectedBusiness?->id);

        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $context = $this->contextOnHome();

        $this->assertSame(ContextSource::ViewAs, $context->source);
        $this->assertSame((int) $pair['clientBusiness']->id, $context->selectedBusiness?->id);
    }

    // -----------------------------------------------------------------
    // 6–7, 11. Ending the view restores the actor's own context, and
    //          leaves nothing of the client behind
    // -----------------------------------------------------------------

    public function test_exiting_the_view_restores_the_normal_agency_context(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->assertSame((int) $pair['clientBusiness']->id, $this->contextOnHome()->selectedBusiness?->id);

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));

        $this->assertRestoredToOwnContext($pair);
    }

    public function test_expiry_restores_the_normal_agency_context(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->assertSame((int) $pair['clientBusiness']->id, $this->contextOnHome()->selectedBusiness?->id);

        $this->travel(ViewAsManager::DEFAULT_TTL_MINUTES + 1)->minutes();

        $this->assertRestoredToOwnContext($pair);
        $this->assertSame(ViewAsSession::END_REASON_EXPIRED, ViewAsSession::query()->sole()->end_reason);
    }

    public function test_the_viewed_client_workspace_is_never_an_ordinary_switcher_candidate(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        // Even DURING the view the ordinary candidate list stays the actor's
        // own tenancy: the client is what the shell is pointed at, never a
        // Workspace the actor may switch between at will.
        $duringIds = $this->workspaceIds($this->contextOnHome());
        $this->assertContains((int) $pair['agencyWorkspace']->id, $duringIds);
        $this->assertNotContains((int) $pair['clientWorkspace']->id, $duringIds);

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));

        $afterIds = $this->workspaceIds($this->contextOnHome());
        $this->assertContains((int) $pair['agencyWorkspace']->id, $afterIds);
        $this->assertNotContains((int) $pair['clientWorkspace']->id, $afterIds);
    }

    // -----------------------------------------------------------------
    // 8–10. Every revalidation failure loses the viewed context on the
    //       very next request — one persisted fact broken per test
    // -----------------------------------------------------------------

    public function test_terminating_the_relationship_loses_the_viewed_context_on_the_next_request(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        app(AgencyClientRelationshipManager::class)->terminate(
            (int) $pair['agencyOwner']->user_id,
            $pair['relationship'],
            'Test: relationship ended mid-session.',
        );
        $this->nextRequest();

        $this->assertRestoredToOwnContext($pair);
        $this->assertSame(ViewAsSession::END_REASON_RELATIONSHIP_ENDED, ViewAsSession::query()->sole()->end_reason);
    }

    public function test_losing_agency_authority_loses_the_viewed_context_on_the_next_request(): void
    {
        $pair = $this->linkedPairViewedByAnAdmin();

        // The Admin membership that was the actor's ONLY Agency authority is
        // deactivated; the relationship and the Agency's plan are untouched.
        $pair['adminMembership']->is_active = false;
        $pair['adminMembership']->save();
        $this->nextRequest();

        $context = $this->contextOnHome();

        $this->assertNull($context->viewAs);
        $this->assertNotSame(ContextSource::ViewAs, $context->source);
        $this->assertNotSame((int) $pair['clientBusiness']->id, $context->selectedBusiness?->id);
        $this->assertSame(ViewAsSession::END_REASON_ACCESS_LOST, ViewAsSession::query()->sole()->end_reason);
    }

    public function test_losing_agency_management_eligibility_loses_the_viewed_context_on_the_next_request(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        // The Agency's own plan is suspended: eligibility is lost while the
        // relationship itself stays Active, so this is a distinct cause.
        app(EntitlementManager::class)->changePlanStatus(
            $pair['agencyWorkspace'],
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Test: agency plan suspended mid-session.',
        );
        $this->nextRequest();

        // A suspended Agency account is itself locked, so this actor's next
        // request is correctly redirected to the locked screen by
        // CustomerAccountAccessGate rather than rendering any frame at all.
        // What matters here is that the session did not survive it: the
        // durable row records eligibility loss as the distinct cause, and the
        // Client Business it used to serve is refused again immediately.
        $this->home()->assertRedirect(route('customer.account-locked.show'));

        $this->assertSame(ViewAsSession::END_REASON_AGENCY_ENTITLEMENT_LOST, ViewAsSession::query()->sole()->end_reason);
        $this->assertSame(
            AgencyClientRelationshipStatus::Active,
            $pair['relationship']->fresh()->status,
            'The relationship itself is untouched — only eligibility was lost.',
        );
        $this->clientAnalytics($pair)->assertNotFound();
    }

    // -----------------------------------------------------------------
    // 12 + the authorization half: ordinary tenancy never widened, and
    //     the shared Business-route seam serves only the exact target
    // -----------------------------------------------------------------

    public function test_ordinary_tenancy_still_refuses_the_agency_actor_for_the_client_business(): void
    {
        $pair = $this->linkedPair();
        $actorId = (int) $pair['agencyOwner']->user_id;

        $this->assertFalse(
            app(WorkspaceManager::class)->userCanAccessBusiness($actorId, $pair['clientBusiness']),
            'Ordinary tenancy must keep refusing an Agency actor for a managed Client Business.',
        );

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        // Unchanged BY the session: the session grants a route, never tenancy.
        $this->assertFalse(
            app(WorkspaceManager::class)->userCanAccessBusiness($actorId, $pair['clientBusiness']->fresh()),
            'An active View As session must not retroactively make the actor a tenant.',
        );

        // …while the canonical Business-route decision does admit the pair.
        $this->assertTrue(
            app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(
                $pair['agencyOwner']->user->fresh(),
                $pair['clientWorkspace'],
                $pair['clientBusiness'],
            ),
        );
    }

    public function test_without_a_session_or_tenancy_the_client_business_route_is_404(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);

        // An Active relationship alone is never Business-route authority.
        $this->clientAnalytics($pair)->assertNotFound();
        $this->assertFalse(
            app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(
                $pair['agencyOwner']->user->fresh(),
                $pair['clientWorkspace'],
                $pair['clientBusiness'],
            ),
        );
    }

    public function test_a_valid_session_serves_the_exact_target_through_the_shared_business_seam(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        // A non-Conversations Business-scoped route, proving the fix lives in
        // the SHARED seam rather than in the Conversations controller alone.
        $this->clientAnalytics($pair)->assertOk()->assertSee($pair['clientBusiness']->name, false);
    }

    public function test_the_viewed_business_addressed_through_a_foreign_workspace_is_404(): void
    {
        $pair = $this->linkedPair();
        [, , $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Business', 'Foreign Account');

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $this->get(route('customer.workspaces.businesses.analytics.overview', [
            $pair['agencyWorkspace']->uid, $pair['clientBusiness']->uid,
        ]))->assertNotFound();

        $this->get(route('customer.workspaces.businesses.analytics.overview', [
            $foreignWorkspace->uid, $pair['clientBusiness']->uid,
        ]))->assertNotFound();

        $this->assertFalse(
            app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(
                $pair['agencyOwner']->user->fresh(),
                $pair['agencyWorkspace'],
                $pair['clientBusiness'],
            ),
            'The viewed Business in a Workspace the session did not name is refused.',
        );
    }

    public function test_a_foreign_business_addressed_through_the_viewed_workspace_is_404(): void
    {
        $pair = $this->linkedPair();
        $other = $this->createIndependentWorkspaceBusiness(businessName: 'Unrelated Co');

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));

        $this->get(route('customer.workspaces.businesses.analytics.overview', [
            $pair['clientWorkspace']->uid, $other['business']->uid,
        ]))->assertNotFound();

        $this->assertFalse(
            app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(
                $pair['agencyOwner']->user->fresh(),
                $pair['clientWorkspace'],
                $other['business'],
            ),
            'A Business the session did not name is refused even inside the viewed Workspace.',
        );

        // And the Agency's own Business is unreachable while narrowed, too.
        $this->get(route('customer.workspaces.businesses.analytics.overview', [
            $pair['agencyWorkspace']->uid, $pair['agencyBusiness']->uid,
        ]))->assertNotFound();
    }

    public function test_an_ended_session_immediately_stops_serving_the_client_business_route(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->clientAnalytics($pair)->assertOk();

        $this->post(route('customer.view-as.exit'))->assertRedirect(route('user.home'));

        $this->clientAnalytics($pair)->assertNotFound();
    }

    public function test_an_expired_session_immediately_stops_serving_the_client_business_route(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->clientAnalytics($pair)->assertOk();

        $this->travel(ViewAsManager::DEFAULT_TTL_MINUTES + 1)->minutes();

        $this->clientAnalytics($pair)->assertNotFound();
    }

    public function test_a_terminated_relationship_immediately_stops_serving_the_client_business_route(): void
    {
        $pair = $this->linkedPair();

        $this->authenticateAs($pair['agencyOwner']);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->clientAnalytics($pair)->assertOk();

        app(AgencyClientRelationshipManager::class)->terminate(
            (int) $pair['agencyOwner']->user_id,
            $pair['relationship'],
            'Test: relationship ended mid-session.',
        );
        $this->nextRequest();

        $this->clientAnalytics($pair)->assertNotFound();
    }

    public function test_ordinary_client_tenancy_rules_are_unchanged(): void
    {
        $pair = $this->linkedPair();

        // The client's own owner reaches their own Business, with no session
        // involved and nothing about the Agency relationship in play.
        $this->authenticateAs($pair['clientOwner']);
        $this->clientAnalytics($pair)->assertOk();
        $this->assertTrue(
            app(WorkspaceManager::class)->userCanAccessBusiness((int) $pair['clientOwner']->user_id, $pair['clientBusiness']),
        );

        // And a stranger still reaches nothing, session or not.
        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);
        $this->clientAnalytics($pair)->assertNotFound();
        $this->assertFalse(
            app(WorkspaceManager::class)->userCanAccessBusiness((int) $stranger->user_id, $pair['clientBusiness']),
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A real V1 Agency managing a real V1 Client, linked by an Active
     * Contract 01 relationship through the shared R1 fixture.
     *
     * @return array{agencyOwner: Customer, agencyWorkspace: Workspace, agencyBusiness: Business, clientOwner: Customer, clientBusiness: Business, clientWorkspace: Workspace, relationship: AgencyClientWorkspaceRelationship}
     */
    private function linkedPair(): array
    {
        $pair = $this->createAgencyManagedClient(
            clientBusinessName: 'Alpha Dental Clinic',
            clientWorkspaceName: 'Alpha Dental',
            agencyBusinessName: 'Northwind House',
            agencyWorkspaceName: 'Northwind Agency',
        );

        // A managed Client carries its own plan, like any other customer.
        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);
        $this->nextRequest();

        return $pair;
    }

    /**
     * The same pair, viewed by an Agency ADMIN rather than the owner — the
     * only shape in which Agency authority can be revoked without touching
     * the relationship or the plan.
     *
     * @return array{agencyOwner: Customer, agencyWorkspace: Workspace, agencyBusiness: Business, clientOwner: Customer, clientBusiness: Business, clientWorkspace: Workspace, relationship: AgencyClientWorkspaceRelationship, admin: Customer, adminMembership: WorkspaceMembership}
     */
    private function linkedPairViewedByAnAdmin(): array
    {
        $pair = $this->linkedPair();
        $admin = $this->createCustomer();
        $membership = $this->member($pair['agencyWorkspace'], $admin->user, WorkspaceMembershipRole::Admin);

        $this->authenticateAs($admin);
        $this->startAgencyView($pair)->assertRedirect(route('user.home'));
        $this->assertSame((int) $pair['clientBusiness']->id, $this->contextOnHome()->selectedBusiness?->id);

        return $pair + ['admin' => $admin, 'adminMembership' => $membership];
    }

    /**
     * The real cross-Workspace entry point: the route
     * AgencyClientsController::viewAs() registers, which is the only way a
     * production actor starts one of these sessions.
     *
     * @param  array<string, mixed>  $pair
     */
    private function startAgencyView(array $pair): TestResponse
    {
        return $this->post(route('customer.workspaces.clients.view-as', [
            $pair['agencyWorkspace']->uid,
            $pair['clientWorkspace']->uid,
        ]));
    }

    /**
     * A Business-scoped route that is NOT Conversations, so the shared seam
     * is what is under test rather than one controller's own copy of it.
     *
     * @param  array<string, mixed>  $pair
     */
    private function clientAnalytics(array $pair): TestResponse
    {
        return $this->get(route('customer.workspaces.businesses.analytics.overview', [
            $pair['clientWorkspace']->uid,
            $pair['clientBusiness']->uid,
        ]));
    }

    /**
     * The CustomerContext the shell actually resolved for a real request —
     * read from the request the middleware bound it to, never rebuilt here,
     * so what is asserted is what production would have rendered.
     */
    private function contextOnHome(): CustomerContext
    {
        $this->home()->assertOk();

        $context = app(CustomerContext::class);

        $this->assertInstanceOf(CustomerContext::class, $context);

        return $context;
    }

    /**
     * The actor is back in their OWN account: no session, no client target,
     * and their own Business is what resolves again.
     *
     * @param  array<string, mixed>  $pair
     */
    private function assertRestoredToOwnContext(array $pair): void
    {
        $context = $this->contextOnHome();

        $this->assertNull($context->viewAs, 'No session may survive.');
        $this->assertNotSame(ContextSource::ViewAs, $context->source);
        $this->assertNotNull($context->selectedBusiness);
        $this->assertSame(
            (int) $pair['agencyBusiness']->id,
            $context->selectedBusiness->id,
            'The Agency actor resolves to their own Business again.',
        );
        $this->assertNotContains((int) $pair['clientWorkspace']->id, $this->workspaceIds($context));
    }

    /**
     * @return array<int, int>
     */
    private function workspaceIds(CustomerContext $context): array
    {
        return array_map(static fn ($workspace): int => $workspace->id, $context->workspaces);
    }

    private function nextRequest(): void
    {
        app(RequestScopedCache::class)->flush();
    }
}
