<?php

namespace Tests\Feature\QueryBudget;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Navigation\BusinessCandidate;
use App\Library\Navigation\ContextSource;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerFrame;
use App\Library\Navigation\WorkspaceCandidate;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 §18, Phase 2 — the shared ResolvesBusinessTenancy trait's
 * security invariants, proven directly rather than only through the six
 * controllers that now use it.
 *
 * The HTTP-level cases below run against the Automations route because it
 * is the one already wired with the full fixture surface (CreatesCustomerContextFixtures)
 * that every controller now shares this trait with also depends on; the
 * trait itself is controller-agnostic, so a security property proven
 * through one caller holds for all six.
 */
class BusinessTenancyContextReuseTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // =================================================================
    // The tenancy/security matrix.
    // =================================================================

    public function test_own_account_and_own_business_succeeds(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_foreign_account_workspace_is_404_equivalent(): void
    {
        [$customerA] = $this->tenant(WorkspacePlanTier::Growth, 'A Business', 'A Workspace');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'B Business', 'B Workspace');
        $this->authenticateAs($customerA);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();
    }

    public function test_foreign_business_inside_an_inaccessible_workspace_is_404_equivalent(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $foreignBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Someone Elses Business', 'Someone Elses Workspace');
        $this->authenticateAs($customer);

        // This customer's own Workspace uid, paired with a Business uid
        // that belongs to a completely different Workspace/Account — the
        // pairing itself must be rejected, not just the Business in
        // isolation.
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $foreignBusiness->uid]))
            ->assertNotFound();
    }

    public function test_unauthorized_member_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $outsider = $this->createCustomer();
        $this->authenticateAs($outsider);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_a_scoped_member_not_assigned_to_the_business_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAs($memberCustomer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_a_scoped_member_assigned_to_the_business_succeeds(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($memberCustomer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_an_inactive_business_remains_denied_even_when_otherwise_accessible(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $draftBusiness = $this->addBusiness($customer, $workspace, 'Draft Business', BusinessStatus::Draft);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $draftBusiness->uid]))
            ->assertNotFound();
    }

    public function test_view_as_context_is_never_reused_as_an_ordinary_business_context(): void
    {
        [$agencyCustomer, , $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Own Business', 'Agency Workspace');
        $client = $this->addBusiness($agencyCustomer, $agencyWorkspace, 'Client Business');
        $this->authenticateAs($agencyCustomer);

        $this->startViewAs($agencyWorkspace, $client, 'Support request')->assertRedirect(route('user.home'));

        // While viewing-as, the Automations page for the viewed Business
        // must still resolve correctly (real authorization still runs),
        // but through the repository path — ResolvesBusinessTenancy's own
        // guard refuses to treat a view-as-narrowed context as this
        // request's ordinary Route-sourced context.
        $this->get(route('customer.workspaces.businesses.automations.index', [$agencyWorkspace->uid, $client->uid]))
            ->assertOk();
    }

    public function test_agency_client_business_is_isolated_from_a_different_agency_client(): void
    {
        [$agencyCustomer, , $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Own Business', 'Agency Workspace');
        $clientA = $this->addBusiness($agencyCustomer, $agencyWorkspace, 'Client A');
        $clientB = $this->addBusiness($agencyCustomer, $agencyWorkspace, 'Client B');

        $staff = $this->createCustomer();
        $membership = $this->member($agencyWorkspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $clientA);
        $this->authenticateAs($staff);

        $this->get(route('customer.workspaces.businesses.automations.index', [$agencyWorkspace->uid, $clientA->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.automations.index', [$agencyWorkspace->uid, $clientB->uid]))->assertNotFound();
    }

    public function test_a_suspended_plan_denies_the_entitled_business_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            $workspace->fresh(),
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Test suspension.',
        );

        $this->get(route('customer.workspaces.businesses.automations.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_request_a_context_cannot_leak_into_request_b(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'A Business', 'A Workspace');
        [$customerB, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'B Business', 'B Workspace');

        $this->authenticateAs($customerA);
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspaceA->uid, $businessA->uid]))->assertOk();

        // A fresh simulated request for a different, unrelated customer:
        // nothing about A's resolved context/business/workspace may answer
        // for B.
        $this->authenticateAs($customerB);
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspaceB->uid, $businessB->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.automations.index', [$workspaceA->uid, $businessA->uid]))->assertNotFound();
    }

    // =================================================================
    // The trait's own route/context matching guard, proven directly:
    // a context naming a different Workspace/Business than the route
    // must never be trusted, whatever caused the mismatch.
    // =================================================================

    public function test_a_context_naming_a_different_business_than_the_route_is_never_trusted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $otherBusiness = $this->addBusiness($customer, $workspace, 'Other Business');
        $userId = (int) $customer->user_id;

        $mismatchedContext = $this->contextSelecting($workspace, $otherBusiness, $userId, ContextSource::Route, viewAs: null);

        $selecting = $this->invokeContextSelecting($mismatchedContext, $workspace->uid, $business->uid, $userId);

        $this->assertNull($selecting, 'A context whose selected Business uid differs from the route Business uid must never be treated as this request\'s context.');
    }

    public function test_a_context_naming_a_different_workspace_than_the_route_is_never_trusted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        [, $businessInOtherWorkspace, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Business', 'Other Workspace');
        $userId = (int) $customer->user_id;

        $mismatchedContext = $this->contextSelecting($otherWorkspace, $businessInOtherWorkspace, $userId, ContextSource::Route, viewAs: null);

        $selecting = $this->invokeContextSelecting($mismatchedContext, $workspace->uid, $business->uid, $userId);

        $this->assertNull($selecting, 'A context whose selected Workspace uid differs from the route Workspace uid must never be treated as this request\'s context.');
    }

    public function test_a_view_as_narrowed_context_is_never_selected_even_when_uids_match(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $userId = (int) $customer->user_id;

        $viewAsContext = $this->contextSelecting($workspace, $business, $userId, ContextSource::Route, viewAs: new \App\Library\ViewAs\ViewAsContext(
            sessionId: 1,
            uid: 'test-view-as-session',
            actorUserId: $userId,
            actorDisplayName: 'Test Actor',
            workspaceId: $workspace->id,
            workspaceUid: $workspace->uid,
            businessId: $business->id,
            businessUid: $business->uid,
            businessName: $business->name,
            startedAt: \Carbon\CarbonImmutable::now(),
            expiresAt: \Carbon\CarbonImmutable::now()->addMinutes(30),
        ));

        $selecting = $this->invokeContextSelecting($viewAsContext, $workspace->uid, $business->uid, $userId);

        $this->assertNull($selecting, 'A context narrowed by an active view-as session must never be reused as this request\'s ordinary tenancy context, even when its uids match the route.');
    }

    public function test_a_context_from_a_non_route_source_is_never_selected_even_when_uids_match(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $userId = (int) $customer->user_id;

        $preferenceContext = $this->contextSelecting($workspace, $business, $userId, ContextSource::Preference, viewAs: null);

        $selecting = $this->invokeContextSelecting($preferenceContext, $workspace->uid, $business->uid, $userId);

        $this->assertNull($selecting, 'Only a Route-sourced context may be reused; every other source falls back to the repository path.');
    }

    public function test_a_context_for_a_different_user_is_never_selected(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $userId = (int) $customer->user_id;

        $context = $this->contextSelecting($workspace, $business, $userId, ContextSource::Route, viewAs: null);

        $selecting = $this->invokeContextSelecting($context, $workspace->uid, $business->uid, $userId + 1);

        $this->assertNull($selecting, 'A context resolved for one user must never be selected on behalf of another.');
    }

    // =================================================================
    // Same-request mutation: the membership caches this Phase adds must
    // never mask a write that happens later in the same request.
    // =================================================================

    public function test_deactivating_a_membership_mid_request_immediately_denies_the_next_check(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $manager = app(\App\Library\Workspace\WorkspaceManager::class);
        $userId = (int) $memberCustomer->user_id;

        $this->assertTrue($manager->userCanAccessBusiness($userId, $business), 'Precondition: an active All-scope membership grants access.');

        app(WorkspaceMembershipRepository::class)->setActive($membership, false);

        $this->assertFalse(
            $manager->userCanAccessBusiness($userId, $business),
            'A membership deactivated mid-request must deny the very next check — never a cached "was active" membership row.',
        );
    }

    public function test_assigning_a_scoped_member_to_a_business_mid_request_is_visible_immediately(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $manager = app(\App\Library\Workspace\WorkspaceManager::class);
        $userId = (int) $memberCustomer->user_id;

        $this->assertFalse($manager->userCanAccessBusiness($userId, $business), 'Precondition: not yet assigned.');

        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $this->assertTrue(
            $manager->userCanAccessBusiness($userId, $business),
            'A Business assigned to a scoped member mid-request must be visible to the very next access check — never a cached "not assigned" answer.',
        );
    }

    public function test_unassigning_a_scoped_member_mid_request_immediately_denies_the_next_check(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $manager = app(\App\Library\Workspace\WorkspaceManager::class);
        $userId = (int) $memberCustomer->user_id;

        $this->assertTrue($manager->userCanAccessBusiness($userId, $business), 'Precondition: assigned.');

        app(WorkspaceMembershipBusinessRepository::class)->unassign($membership, $business->id);

        $this->assertFalse(
            $manager->userCanAccessBusiness($userId, $business),
            'Unassigning mid-request must deny the very next check — never a cached "was assigned" answer.',
        );
    }

    public function test_a_fresh_request_never_reuses_the_previous_requests_cached_membership_answers(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $memberCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $memberCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $userId = (int) $memberCustomer->user_id;

        app(WorkspaceMembershipRepository::class)->findByWorkspaceAndUser($workspace, $userId);
        app(WorkspaceMembershipBusinessRepository::class)->isAssigned($membership, $business->id);

        $this->app->instance('request', Request::create('/next'));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(WorkspaceMembershipRepository::class)->findByWorkspaceAndUser($workspace, $userId);
        app(WorkspaceMembershipBusinessRepository::class)->isAssigned($membership, $business->id);

        $this->assertSame(2, $queries, 'A fresh request must start with an empty membership cache — both reads must hit the database again.');
    }

    // -----------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------

    private function contextSelecting(Workspace $workspace, \App\Models\Business $business, int $userId, ContextSource $source, ?\App\Library\ViewAs\ViewAsContext $viewAs): CustomerContext
    {
        $workspaceCandidate = new WorkspaceCandidate(
            id: $workspace->id,
            uid: $workspace->uid,
            name: $workspace->name,
            isActive: $workspace->is_active,
            ownerUserId: $workspace->owner_user_id,
            isOwner: true,
            membershipRole: null,
            membershipScope: null,
            membershipActive: false,
            tier: null,
            tierDisplayName: null,
            businesses: [],
        );

        $businessCandidate = new BusinessCandidate(
            id: $business->id,
            uid: $business->uid,
            name: $business->name,
            status: $business->status->value,
            customerId: $business->customer_id,
            isPrimary: false,
            accessible: true,
            workspaceUid: $workspace->uid,
            workspaceName: $workspace->name,
        );

        return new CustomerContext(
            userId: $userId,
            frame: CustomerFrame::Business,
            workspaces: [$workspaceCandidate],
            selectedWorkspace: $workspaceCandidate,
            selectedBusiness: $businessCandidate,
            source: $source,
            viewAs: $viewAs,
            preferenceCleared: false,
        );
    }

    /**
     * Invokes ResolvesBusinessTenancy::tenancyContextSelecting() via a
     * throwaway class using the trait — the same private guard every one
     * of the six controllers relies on.
     */
    private function invokeContextSelecting(CustomerContext $context, string $workspaceUid, string $businessUid, int $userId): ?CustomerContext
    {
        request()->attributes->set('customerContext', $context);

        $holder = new class {
            use ResolvesBusinessTenancy;

            public function call(string $workspaceUid, string $businessUid, int $userId): ?CustomerContext
            {
                $method = new \ReflectionMethod($this, 'tenancyContextSelecting');
                $method->setAccessible(true);

                return $method->invoke($this, $workspaceUid, $businessUid, $userId);
            }
        };

        return $holder->call($workspaceUid, $businessUid, $userId);
    }
}
