<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Usage\BillingProfileManager;
use App\Library\ViewAs\ViewAsContext;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * V1 Implementation Contract 04 — cross-Workspace Agency View As.
 *
 * Every §6 threat-model row is its own test, including entitlement loss as a
 * test distinct from relationship termination. The existing same-Workspace
 * View As coverage (ViewAsClientTest, ViewAsAccessLossTest,
 * ViewAsRouteBoundaryTest, CustomerContextSecurityTest) is re-run unmodified
 * alongside this file; one test here additionally pins that a same-Workspace
 * session still carries no Agency fields and still ends as access_lost.
 *
 * "A later request" is modelled explicitly: RequestScopedCache lives for one
 * request in production, so a test that changes persisted state between two
 * reads flushes it first, exactly as a fresh request would.
 */
class AgencyViewAsTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists: EloquentAccountRepository::hasPermission() short-circuits
        // that id to "every permission", which would invalidate every denial.
        $this->platformAdminId();
    }

    private function viewAs(): ViewAsManager
    {
        return app(ViewAsManager::class);
    }

    private function relationships(): AgencyClientRelationshipManager
    {
        return app(AgencyClientRelationshipManager::class);
    }

    private function nextRequest(): void
    {
        app(RequestScopedCache::class)->flush();
    }

    /**
     * An Agency-tier Workspace and its owner.
     *
     * @return array{0: Workspace, 1: User}
     */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    /**
     * A V1-shaped Client Workspace: its own owner, exactly one active
     * Business, its own (non-Agency) plan.
     *
     * @return array{0: Workspace, 1: Business, 2: User}
     */
    private function clientAccount(string $name = 'Alpha Dental'): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, $name . ' Clinic', $name);

        return [$workspace, $business, $customer->user->fresh()];
    }

    private function link(Workspace $agency, User $agencyOwner, Workspace $client): AgencyClientWorkspaceRelationship
    {
        return $this->relationships()->create((int) $agencyOwner->id, $agency, $client);
    }

    /**
     * A linked Agency and Client, ready to view.
     *
     * @return array{agency: Workspace, owner: User, client: Workspace, business: Business, clientOwner: User, relationship: AgencyClientWorkspaceRelationship}
     */
    private function linkedPair(string $agencyName = 'Northwind Agency', string $clientName = 'Alpha Dental'): array
    {
        [$agency, $owner] = $this->agency($agencyName);
        [$client, $business, $clientOwner] = $this->clientAccount($clientName);
        $relationship = $this->link($agency, $owner, $client);

        return compact('agency', 'owner', 'client', 'business', 'clientOwner', 'relationship');
    }

    /**
     * A customer User who is a member of $workspace and holds NO customer
     * permissions at all: under the V1 rule, active Agency membership alone
     * is the ordinary-management grant, so nothing in a permission list may
     * be what makes a test pass.
     */
    private function memberOf(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true, ?User $user = null): User
    {
        if ($user === null) {
            $customer = $this->createCustomer();
            $customer->permissions = json_encode([]);
            $customer->save();
            $user = $customer->user;
        }

        $this->member($workspace, $user, $role, active: $active);

        return $user->fresh();
    }

    /**
     * The repository's user-id-1 super admin. setUp() normally claims id 1
     * for the platform administrator, but auto-increment ids are not reset
     * between RefreshDatabase tests, so the row is created with id 1
     * explicitly when this test's transaction does not already hold it.
     */
    private function superAdmin(): User
    {
        $existing = User::query()->find(1);

        if ($existing !== null) {
            return $existing;
        }

        $user = new User([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-admin-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $user->id = 1;
        $user->save();

        return $user->fresh();
    }

    /**
     * Drives the Agency Workspace's account into a lifecycle state through
     * EntitlementManager's own writers — never by poking lifecycle columns —
     * so the state the resolver reads is exactly the production shape.
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

        $this->nextRequest();
    }

    private function assertAgencyAccountState(Workspace $agency, CustomerAccountAccessState $expected): void
    {
        $this->nextRequest();
        $this->assertSame($expected, app(CustomerAccountAccessResolver::class)->resolve($agency->fresh())->state);
    }

    private function downgradeToCore(Workspace $agency): void
    {
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $agency->id)
            ->update([
                'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Core->value)->value('id'),
            ]);
    }

    private function assertStartRefused(User $actor, string $agencyWorkspaceUid, string $clientWorkspaceUid): void
    {
        $rowsBefore = ViewAsSession::query()->count();
        $sessionKeyBefore = session(ViewAsManager::SESSION_KEY);

        try {
            $this->viewAs()->startAgencyView($actor, $agencyWorkspaceUid, $clientWorkspaceUid);
            $this->fail('User [' . $actor->id . '] must not be able to start this Agency View As.');
        } catch (HttpException $e) {
            // The existence-disclosure-safe refusal: indistinguishable from a
            // missing target, never a confirming 403.
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame($rowsBefore, ViewAsSession::query()->count(), 'A refused start writes no audit row.');
        $this->assertSame($sessionKeyBefore, session(ViewAsManager::SESSION_KEY), 'A refused start never touches the session key.');
    }

    private function assertSessionEndedWith(ViewAsSession $session, User $actor, string $expectedReason): void
    {
        $this->nextRequest();

        $this->assertNull($this->viewAs()->current($actor), 'The very next read must end the session.');

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertSame($expectedReason, $session->end_reason);
        $this->assertNull(session(ViewAsManager::SESSION_KEY), 'The session key is forgotten with the ended session.');

        $this->nextRequest();
        $this->assertNull($this->viewAs()->current($actor), 'An ended session never comes back.');
    }

    // ------------------------------------------------------------------
    // Happy paths — who may open a linked Client account
    // ------------------------------------------------------------------

    public function test_the_agency_owner_starts_a_linked_client_view_and_every_read_resolves_it(): void
    {
        $pair = $this->linkedPair();

        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid, 'Support ticket 42');

        $this->assertSame((int) $pair['owner']->id, (int) $session->actor_user_id);
        $this->assertSame((int) $pair['client']->id, (int) $session->workspace_id, 'workspace_id keeps meaning the Workspace being viewed.');
        $this->assertSame((int) $pair['agency']->id, (int) $session->viewing_agency_workspace_id);
        $this->assertSame((int) $pair['business']->id, (int) $session->business_id, 'The Client Workspace\'s sole Business is resolved internally.');
        $this->assertSame('Support ticket 42', $session->reason);
        $this->assertNull($session->ended_at);
        $this->assertSame(ViewAsManager::DEFAULT_TTL_MINUTES, (int) round($session->started_at->diffInMinutes($session->expires_at)));
        $this->assertSame($session->uid, session(ViewAsManager::SESSION_KEY));

        $this->nextRequest();
        $context = $this->viewAs()->current($pair['owner']);

        $this->assertInstanceOf(ViewAsContext::class, $context);
        $this->assertSame((int) $session->id, $context->sessionId);
        $this->assertSame((int) $pair['owner']->id, $context->actorUserId);
        $this->assertSame((int) $pair['client']->id, $context->workspaceId);
        $this->assertSame($pair['client']->uid, $context->workspaceUid);
        $this->assertSame((int) $pair['business']->id, $context->businessId);
        $this->assertSame($pair['business']->uid, $context->businessUid);
        $this->assertSame('Alpha Dental Clinic', $context->businessName);
        $this->assertSame((int) $pair['agency']->id, $context->viewingAgencyWorkspaceId);
        $this->assertSame($pair['agency']->uid, $context->viewingAgencyWorkspaceUid);
    }

    /** Security regression 1: an active Admin of Agency A views A's linked Client — by membership alone. */
    public function test_an_active_agency_admin_starts_a_linked_client_view(): void
    {
        $pair = $this->linkedPair();
        $admin = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Admin);

        $session = $this->viewAs()->startAgencyView($admin, $pair['agency']->uid, $pair['client']->uid);

        $this->assertSame((int) $admin->id, (int) $session->actor_user_id);
        $this->assertSame((int) $pair['agency']->id, (int) $session->viewing_agency_workspace_id);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($admin));
    }

    /** Security regression 2 / Blueprint §2: View As is not owner- or Admin-only — an active Staff member qualifies. */
    public function test_active_agency_staff_starts_a_linked_client_view(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Staff);

        $session = $this->viewAs()->startAgencyView($staff, $pair['agency']->uid, $pair['client']->uid);

        $this->assertSame((int) $staff->id, (int) $session->actor_user_id);

        $this->nextRequest();
        $this->assertSame((int) $pair['business']->id, $this->viewAs()->current($staff)?->businessId);
    }

    public function test_starting_an_agency_view_replaces_the_actors_previous_session(): void
    {
        $first = $this->linkedPair('Northwind Agency', 'Alpha Dental');
        [$secondClient] = $this->clientAccount('Beta Florist');
        $this->link($first['agency'], $first['owner'], $secondClient);

        $earlier = $this->viewAs()->startAgencyView($first['owner'], $first['agency']->uid, $first['client']->uid);
        $later = $this->viewAs()->startAgencyView($first['owner'], $first['agency']->uid, $secondClient->uid);

        $this->assertSame(ViewAsSession::END_REASON_REPLACED, $earlier->refresh()->end_reason);
        $this->assertNull($later->refresh()->ended_at);
        $this->assertSame(1, ViewAsSession::query()->whereNull('ended_at')->where('actor_user_id', $first['owner']->id)->count());
    }

    // ------------------------------------------------------------------
    // Authority — who may NOT (Contract 04 §6)
    // ------------------------------------------------------------------

    public function test_inactive_agency_admin_and_staff_memberships_are_refused(): void
    {
        $pair = $this->linkedPair();

        foreach ([WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff] as $role) {
            $inactive = $this->memberOf($pair['agency'], $role, active: false);
            $this->assertStartRefused($inactive, $pair['agency']->uid, $pair['client']->uid);
        }
    }

    /** A User with no membership at all in the Agency Workspace is nobody there. */
    public function test_a_user_with_no_agency_membership_is_refused(): void
    {
        $pair = $this->linkedPair();
        $stranger = $this->createCustomer()->user;

        $this->assertStartRefused($stranger, $pair['agency']->uid, $pair['client']->uid);
    }

    /**
     * Security regression 3: authority is per Agency Workspace. The same User
     * being an active member of Agency A grants nothing in Agency B — neither
     * through B's uid, nor by naming A as the Agency for B's Client.
     */
    public function test_membership_in_agency_a_grants_no_authority_in_agency_b(): void
    {
        $agencyA = $this->linkedPair('Agency A', 'Client of A');
        $agencyB = $this->linkedPair('Agency B', 'Client of B');

        $sharedUser = $this->memberOf($agencyA['agency'], WorkspaceMembershipRole::Admin);

        $this->assertStartRefused($sharedUser, $agencyB['agency']->uid, $agencyB['client']->uid);
        $this->assertStartRefused($sharedUser, $agencyA['agency']->uid, $agencyB['client']->uid);

        // ...while the very same User still works in the Agency they belong to.
        $this->assertSame(
            (int) $agencyA['business']->id,
            (int) $this->viewAs()->startAgencyView($sharedUser, $agencyA['agency']->uid, $agencyA['client']->uid)->business_id,
        );
    }

    /**
     * Security regression 6: user id 1 has no legacy super-admin bypass here.
     * When it is neither the owner nor an active member of the Agency
     * Workspace, it is refused like anyone else.
     */
    public function test_user_id_1_is_refused_when_not_owner_or_active_member_of_the_agency(): void
    {
        $pair = $this->linkedPair();
        $superAdmin = $this->superAdmin();

        $this->assertSame(1, (int) $superAdmin->id);
        $this->assertNotSame(1, (int) $pair['agency']->owner_user_id);
        $this->assertFalse(
            DB::table('workspace_memberships')->where('workspace_id', $pair['agency']->id)->where('user_id', 1)->exists(),
        );

        $this->assertStartRefused($superAdmin, $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_an_unrelated_agency_is_refused(): void
    {
        $pair = $this->linkedPair();
        [$otherAgency, $otherOwner] = $this->agency('Unrelated Agency');

        // Through its own Agency Workspace: no relationship to this client.
        $this->assertStartRefused($otherOwner, $otherAgency->uid, $pair['client']->uid);

        // Through the linked Agency's uid: no standing in that Agency.
        $this->assertStartRefused($otherOwner, $pair['agency']->uid, $pair['client']->uid);
    }

    /** Security regression 8: Agency authority is never inferred from Client Workspace membership (Addendum §2). */
    public function test_client_side_actors_never_gain_agency_view_as(): void
    {
        $pair = $this->linkedPair();

        $clientAdmin = $this->memberOf($pair['client'], WorkspaceMembershipRole::Admin);
        $clientStaff = $this->memberOf($pair['client'], WorkspaceMembershipRole::Staff);

        foreach ([$pair['clientOwner'], $clientAdmin, $clientStaff] as $clientActor) {
            $this->assertStartRefused($clientActor, $pair['agency']->uid, $pair['client']->uid);
            // Naming its own Workspace as "the Agency" is a self-target no
            // relationship can ever satisfy.
            $this->assertStartRefused($clientActor, $pair['client']->uid, $pair['client']->uid);
        }
    }

    /** Security regression 7: platform status alone never qualifies, even with the platform relationship Role permission. */
    public function test_platform_admin_status_alone_does_not_qualify(): void
    {
        $pair = $this->linkedPair();

        $admin = User::create([
            'first_name' => 'Platform',
            'last_name' => 'Support',
            'email' => 'platform-support-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $role = Role::create(['name' => 'support-' . uniqid('', true), 'status' => 1]);
        $role->permissions()->create(['name' => AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION]);
        $role->permissions()->create(['name' => 'view workspace']);
        $admin->roles()->attach($role->id);

        $this->assertStartRefused($admin->fresh(), $pair['agency']->uid, $pair['client']->uid);
    }

    // ------------------------------------------------------------------
    // Targets — nonexistent, unlinked and malformed all fail closed
    // ------------------------------------------------------------------

    public function test_nonexistent_or_unlinked_targets_fail_with_a_tenancy_safe_404(): void
    {
        $pair = $this->linkedPair();
        $owner = $pair['owner'];

        // Nonexistent Client and Agency Workspaces.
        $this->assertStartRefused($owner, $pair['agency']->uid, (string) Str::uuid());
        $this->assertStartRefused($owner, (string) Str::uuid(), $pair['client']->uid);

        // A real Workspace the Agency has no relationship with.
        [$unlinkedClient] = $this->clientAccount('Unlinked Bakery');
        $this->assertStartRefused($owner, $pair['agency']->uid, $unlinkedClient->uid);

        // A client actively managed by a DIFFERENT Agency.
        [$otherAgency, $otherOwner] = $this->agency('Other Agency');
        [$otherClient] = $this->clientAccount('Other Salon');
        $this->link($otherAgency, $otherOwner, $otherClient);
        $this->assertStartRefused($owner, $pair['agency']->uid, $otherClient->uid);

        // Self-targeting the Agency's own Workspace.
        $this->assertStartRefused($owner, $pair['agency']->uid, $pair['agency']->uid);

        // A relationship that has been terminated.
        $this->relationships()->terminate((int) $owner->id, $pair['relationship'], 'Client left.');
        $this->nextRequest();
        $this->assertStartRefused($owner, $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_a_terminated_relationship_is_refused_at_start(): void
    {
        $pair = $this->linkedPair();
        $this->relationships()->terminate((int) $pair['owner']->id, $pair['relationship'], 'Client left.');
        $this->nextRequest();

        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $pair['relationship']->fresh()->status);
        $this->assertStartRefused($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
    }

    /**
     * IDOR matrix: every combination of arbitrary, foreign and swapped
     * Agency/Client UIDs an authorized Agency owner can supply fails with the
     * same 404 as a missing target, and never reaches a Business it is not
     * linked to.
     */
    public function test_arbitrary_agency_and_client_uid_combinations_fail_tenancy_safely(): void
    {
        $mine = $this->linkedPair('My Agency', 'My Client');
        $theirs = $this->linkedPair('Their Agency', 'Their Client');
        [$loneWorkspace] = $this->clientAccount('Unmanaged Shop');
        $random = (string) Str::uuid();

        $combinations = [
            'random agency, random client' => [$random, (string) Str::uuid()],
            'my agency, random client' => [$mine['agency']->uid, $random],
            'random agency, my client' => [$random, $mine['client']->uid],
            'my agency, their client' => [$mine['agency']->uid, $theirs['client']->uid],
            'their agency, their client' => [$theirs['agency']->uid, $theirs['client']->uid],
            'their agency, my client' => [$theirs['agency']->uid, $mine['client']->uid],
            'my agency, unmanaged workspace' => [$mine['agency']->uid, $loneWorkspace->uid],
            'swapped: my client as agency, my agency as client' => [$mine['client']->uid, $mine['agency']->uid],
            'my agency as both' => [$mine['agency']->uid, $mine['agency']->uid],
            'my client as both' => [$mine['client']->uid, $mine['client']->uid],
            'their agency as my client' => [$mine['agency']->uid, $theirs['agency']->uid],
            'empty client uid' => [$mine['agency']->uid, ''],
            'empty agency uid' => ['', $mine['client']->uid],
            'business uid supplied as client workspace' => [$mine['agency']->uid, $mine['business']->uid],
        ];

        foreach ($combinations as $label => [$agencyUid, $clientUid]) {
            try {
                $this->viewAs()->startAgencyView($mine['owner'], $agencyUid, $clientUid);
                $this->fail('Combination [' . $label . '] must not start an Agency View As.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode(), 'Combination [' . $label . '] must fail as a tenancy-safe 404.');
            }
        }

        $this->assertSame(0, ViewAsSession::query()->count(), 'No combination wrote an audit row.');

        // ...while the one legitimate combination still works.
        $session = $this->viewAs()->startAgencyView($mine['owner'], $mine['agency']->uid, $mine['client']->uid);
        $this->assertSame((int) $mine['business']->id, (int) $session->business_id);
    }

    /**
     * Relationships never compose: authority is exactly one direct Active
     * relationship from the actor's own Agency Workspace. A manages B and B
     * manages C does not let A's owner reach C — not through A (no direct
     * link), and not through B (no Agency authority in B, merely because A
     * manages B).
     */
    public function test_relationships_are_never_transitive(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');
        [$clientC] = $this->clientAccount('Client C');

        $this->link($agencyA, $ownerA, $agencyB);
        $this->link($agencyB, $ownerB, $clientC);

        $this->assertStartRefused($ownerA, $agencyA->uid, $clientC->uid);
        $this->assertStartRefused($ownerA, $agencyB->uid, $clientC->uid);

        // B's own owner reaches C directly.
        $this->assertSame((int) $agencyB->id, (int) $this->viewAs()->startAgencyView($ownerB, $agencyB->uid, $clientC->uid)->viewing_agency_workspace_id);
    }

    /**
     * Cyclic data (A manages B, B manages A) resolves by one direct lookup,
     * never recursion: each owner views the other only through its OWN
     * Agency Workspace, and neither can borrow the other's Agency standing.
     */
    public function test_cyclic_relationship_data_never_creates_recursive_authorization(): void
    {
        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');

        // Both Agency Workspaces need a sole Business to be viewable.
        $this->addBusiness($ownerA->customer, $agencyA, 'Agency A Studio');
        $this->addBusiness($ownerB->customer, $agencyB, 'Agency B Studio');

        $this->link($agencyA, $ownerA, $agencyB);
        $this->link($agencyB, $ownerB, $agencyA);

        // Direct, own-Agency paths work.
        $this->assertSame((int) $agencyB->id, (int) $this->viewAs()->startAgencyView($ownerA, $agencyA->uid, $agencyB->uid)->workspace_id);
        $this->assertSame((int) $agencyA->id, (int) $this->viewAs()->startAgencyView($ownerB, $agencyB->uid, $agencyA->uid)->workspace_id);

        // Borrowing the other side of the cycle does not.
        $this->assertStartRefused($ownerA, $agencyB->uid, $agencyA->uid);
        $this->assertStartRefused($ownerB, $agencyA->uid, $agencyB->uid);

        // And revalidation of a cyclic pair still terminates and holds.
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($ownerB));
    }

    public function test_a_client_workspace_with_zero_businesses_fails_closed(): void
    {
        [$agency, $owner] = $this->agency();

        $clientCustomer = $this->createCustomer();
        $emptyClient = $this->createWorkspace($clientCustomer->user, ['name' => 'Empty Client']);
        $this->assignTier($emptyClient, WorkspacePlanTier::Core);
        $this->link($agency, $owner, $emptyClient);
        $this->nextRequest();

        $this->assertSame(0, Business::query()->where('workspace_id', $emptyClient->id)->count());
        $this->assertStartRefused($owner, $agency->uid, $emptyClient->uid);
    }

    public function test_a_client_workspace_with_more_than_one_business_fails_closed_rather_than_choosing_one(): void
    {
        $pair = $this->linkedPair();
        $this->addBusiness($pair['clientOwner']->customer, $pair['client'], 'Second Clinic');
        $this->nextRequest();

        $this->assertSame(2, Business::query()->where('workspace_id', $pair['client']->id)->count());
        $this->assertStartRefused($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_a_client_workspace_whose_sole_business_is_not_active_fails_closed(): void
    {
        $pair = $this->linkedPair();
        DB::table('businesses')->where('id', $pair['business']->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->nextRequest();

        $this->assertStartRefused($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_an_agency_no_longer_on_the_agency_tier_cannot_start_a_view(): void
    {
        $pair = $this->linkedPair();
        $this->downgradeToCore($pair['agency']);
        $this->nextRequest();

        $this->assertSame(AgencyClientRelationshipStatus::Active, $pair['relationship']->fresh()->status);
        $this->assertStartRefused($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
    }

    // ------------------------------------------------------------------
    // Revalidation on every read (Contract 04 §6, acceptance 3 and 4)
    // ------------------------------------------------------------------

    public function test_terminating_the_relationship_mid_session_ends_it_on_the_very_next_read(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']));

        $this->relationships()->terminate((int) $pair['owner']->id, $pair['relationship'], 'Client moved in-house.');

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_RELATIONSHIP_ENDED);
    }

    /** A still-Active relationship with a downgraded Agency fails just as closed, for a different, distinguishable reason. */
    public function test_losing_agency_entitlement_mid_session_ends_it_on_the_very_next_read(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']));

        $this->downgradeToCore($pair['agency']);

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_AGENCY_ENTITLEMENT_LOST);
        $this->assertSame(
            AgencyClientRelationshipStatus::Active,
            $pair['relationship']->fresh()->status,
            'The relationship itself is untouched — only entitlement was lost.'
        );
        $this->assertNotSame(ViewAsSession::END_REASON_RELATIONSHIP_ENDED, $session->end_reason);
        $this->assertNotSame(ViewAsSession::END_REASON_ACCESS_LOST, $session->end_reason);
    }

    public function test_an_active_relationship_with_current_entitlement_stays_usable_across_reads(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        foreach (range(1, 3) as $read) {
            $this->nextRequest();
            $context = $this->viewAs()->current($pair['owner']);

            $this->assertNotNull($context, 'Read ' . $read . ' must still resolve the session.');
            $this->assertSame((int) $pair['agency']->id, $context->viewingAgencyWorkspaceId);
        }

        $this->assertNull($session->refresh()->ended_at);
    }

    /** Losing the actor's own Agency authority is ordinary access loss — distinct from the two Agency-level causes. */
    /** Security regression 4: a membership deactivated mid-session ends the view as access_lost on the next read. */
    public function test_agency_membership_deactivated_mid_session_ends_it_as_access_lost(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Staff);
        $session = $this->viewAs()->startAgencyView($staff, $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($staff));

        DB::table('workspace_memberships')
            ->where('workspace_id', $pair['agency']->id)
            ->where('user_id', $staff->id)
            ->update(['is_active' => false]);

        $this->assertSessionEndedWith($session, $staff, ViewAsSession::END_REASON_ACCESS_LOST);
    }

    /** Security regression 5: a membership removed mid-session ends the view as access_lost on the next read. */
    public function test_agency_membership_removed_mid_session_ends_it_as_access_lost(): void
    {
        $pair = $this->linkedPair();
        $admin = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Admin);
        $session = $this->viewAs()->startAgencyView($admin, $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($admin));

        $membershipId = DB::table('workspace_memberships')
            ->where('workspace_id', $pair['agency']->id)
            ->where('user_id', $admin->id)
            ->value('id');
        DB::table('workspace_membership_businesses')->where('workspace_membership_id', $membershipId)->delete();
        DB::table('workspace_membership_locations')->where('workspace_membership_id', $membershipId)->delete();
        DB::table('workspace_memberships')->where('id', $membershipId)->delete();

        $this->assertSessionEndedWith($session, $admin, ViewAsSession::END_REASON_ACCESS_LOST);
    }

    /**
     * Changing a member's User-global customer permission list — the old,
     * rejected authority source — neither grants nor removes Agency authority:
     * an active member with every permission removed keeps viewing.
     */
    public function test_customer_permission_changes_do_not_affect_agency_authority(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Staff);
        $session = $this->viewAs()->startAgencyView($staff, $pair['agency']->uid, $pair['client']->uid);

        $staff->customer->update(['permissions' => json_encode(['manage_agency_clients'])]);
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($staff));

        $staff->customer->update(['permissions' => json_encode([])]);
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($staff));

        session()->put('permissions', collect([]));
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($staff));

        $this->assertNull($session->refresh()->ended_at);
    }

    // ------------------------------------------------------------------
    // Agency account lifecycle (Contract 04 correction 1): management
    // eligibility = Agency tier AND a usable account, per
    // CustomerAccountAccessResolver
    // ------------------------------------------------------------------

    public function test_an_active_agency_account_can_start_a_view(): void
    {
        $pair = $this->linkedPair();
        $this->assertAgencyAccountState($pair['agency'], CustomerAccountAccessState::Usable);

        $this->assertSame((int) $pair['agency']->id, (int) $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid)->viewing_agency_workspace_id);
    }

    public function test_an_agency_in_trial_can_start_a_view(): void
    {
        $owner = $this->createCustomer()->user;
        $agency = $this->createWorkspace($owner, ['name' => 'Trialing Agency']);
        app(EntitlementManager::class)->assignFirstPlan($agency, WorkspacePlanTier::Agency, $this->platformAdminId(), 'Trial fixture.', true, 0, now()->addDays(14));
        [$client] = $this->clientAccount();
        $this->link($agency->fresh(), $owner, $client);
        $this->nextRequest();

        $this->assertTrue(app(CustomerAccountAccessResolver::class)->resolve($agency->fresh())->isInTrial());
        $this->assertNotNull($this->viewAs()->startAgencyView($owner, $agency->uid, $client->uid));
    }

    public function test_an_agency_in_grace_can_start_a_view_and_keep_it(): void
    {
        $pair = $this->linkedPair();
        $this->putAgencyInto('grace', $pair['agency']);
        $this->assertAgencyAccountState($pair['agency'], CustomerAccountAccessState::Usable);
        $this->assertTrue(app(CustomerAccountAccessResolver::class)->resolve($pair['agency']->fresh())->isInGracePeriod());

        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']));
        $this->assertNull($session->refresh()->ended_at);
    }

    public function test_a_locked_inactive_or_suspended_agency_cannot_start_a_view(): void
    {
        $expectations = [
            'locked' => CustomerAccountAccessState::Locked,
            'inactive' => CustomerAccountAccessState::LockedInactive,
            'suspended' => CustomerAccountAccessState::LockedSuspended,
        ];

        foreach ($expectations as $state => $accessState) {
            $pair = $this->linkedPair(ucfirst($state) . ' Agency', ucfirst($state) . ' Client');
            $this->putAgencyInto($state, $pair['agency']);
            $this->assertAgencyAccountState($pair['agency'], $accessState);

            $this->assertSame(WorkspacePlanTier::Agency, app(EntitlementManager::class)->getWorkspaceEntitlementSummary($pair['agency']->fresh())->tier, 'Still on the Agency tier — only the account is unusable.');
            $this->assertSame(AgencyClientRelationshipStatus::Active, $pair['relationship']->fresh()->status);
            $this->assertStartRefused($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
        }
    }

    public function test_an_agency_becoming_locked_mid_session_ends_it_as_agency_entitlement_lost(): void
    {
        $this->assertAgencyLifecycleEndsSession('locked');
    }

    public function test_an_agency_becoming_inactive_mid_session_ends_it_as_agency_entitlement_lost(): void
    {
        $this->assertAgencyLifecycleEndsSession('inactive');
    }

    public function test_an_agency_becoming_suspended_mid_session_ends_it_as_agency_entitlement_lost(): void
    {
        $this->assertAgencyLifecycleEndsSession('suspended');
    }

    private function assertAgencyLifecycleEndsSession(string $state): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']));

        $this->putAgencyInto($state, $pair['agency']);

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_AGENCY_ENTITLEMENT_LOST);
        $this->assertSame(
            AgencyClientRelationshipStatus::Active,
            $pair['relationship']->fresh()->status,
            'The relationship itself is untouched — only the Agency account became unusable.'
        );
    }

    public function test_the_viewed_client_workspace_becoming_inactive_mid_session_ends_it(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        DB::table('workspaces')->where('id', $pair['client']->id)->update(['is_active' => false]);

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_ACCESS_LOST);
    }

    public function test_the_viewed_client_business_becoming_inactive_mid_session_ends_it(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        DB::table('businesses')->where('id', $pair['business']->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_ACCESS_LOST);
    }

    public function test_the_client_gaining_a_second_business_mid_session_fails_closed(): void
    {
        $pair = $this->linkedPair();
        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);

        $this->addBusiness($pair['clientOwner']->customer, $pair['client'], 'Unexpected Second Clinic');

        $this->assertSessionEndedWith($session, $pair['owner'], ViewAsSession::END_REASON_ACCESS_LOST);
    }

    // ------------------------------------------------------------------
    // The existing same-Workspace path is unchanged
    // ------------------------------------------------------------------

    public function test_the_same_workspace_view_as_path_is_unchanged(): void
    {
        [$agencyCustomer, $clientBusiness, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client Bakery', 'Northwind Agency');
        $this->addBusiness($agencyCustomer, $workspace, 'Client Florist');
        $actor = $agencyCustomer->user;

        $session = $this->viewAs()->start($actor, $workspace->uid, $clientBusiness->uid, 'Legacy path');

        $this->assertNull($session->viewing_agency_workspace_id, 'A same-Workspace session is never an Agency session.');
        $this->assertSame((int) $workspace->id, (int) $session->workspace_id);

        $this->nextRequest();
        $context = $this->viewAs()->current($actor);

        $this->assertNotNull($context);
        $this->assertNull($context->viewingAgencyWorkspaceId);
        $this->assertNull($context->viewingAgencyWorkspaceUid);

        // Its ordinary access loss still ends as access_lost.
        DB::table('businesses')->where('id', $clientBusiness->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->assertSessionEndedWith($session, $actor, ViewAsSession::END_REASON_ACCESS_LOST);
    }

    // ------------------------------------------------------------------
    // Identity and boundaries the Client keeps
    // ------------------------------------------------------------------

    public function test_the_real_agency_actor_stays_the_actor_and_client_tenancy_is_not_widened(): void
    {
        $pair = $this->linkedPair();
        $this->actingAs($pair['owner']);

        $session = $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
        $this->nextRequest();
        $context = $this->viewAs()->current($pair['owner']);

        $this->assertSame((int) $pair['owner']->id, Auth::id(), 'Auth::id() never changes (identity is layered on, never substituted).');
        $this->assertSame((int) $pair['owner']->id, (int) $session->actor_user_id);
        $this->assertSame((int) $pair['owner']->id, $context->actorUserId);
        $this->assertSame($pair['owner']->displayName(), $context->actorDisplayName);
        $this->assertNotSame((int) $pair['clientOwner']->id, $context->actorUserId);

        // View As is a narrowing, audited lens — it never makes the Agency
        // actor a tenant of the Client Workspace.
        $this->assertFalse(
            app(WorkspaceManager::class)->userCanAccessBusiness((int) $pair['owner']->id, $pair['business']),
            'Ordinary Client Workspace tenancy must still refuse the Agency actor while viewing.'
        );
    }

    /** Starting and reading an Agency View As never creates, activates or alters any Client Workspace membership. */
    public function test_no_client_workspace_membership_is_created(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Staff);

        $clientMembershipsBefore = DB::table('workspace_memberships')->where('workspace_id', $pair['client']->id)->get()->toArray();
        $membershipRowsBefore = DB::table('workspace_memberships')->count();

        foreach ([$pair['owner'], $staff] as $actor) {
            $this->viewAs()->startAgencyView($actor, $pair['agency']->uid, $pair['client']->uid);
            $this->nextRequest();
            $this->assertNotNull($this->viewAs()->current($actor));

            $this->assertFalse(
                DB::table('workspace_memberships')->where('workspace_id', $pair['client']->id)->where('user_id', $actor->id)->exists(),
                'The Agency actor must never become a member of the Client Workspace.'
            );
        }

        $this->assertEquals($clientMembershipsBefore, DB::table('workspace_memberships')->where('workspace_id', $pair['client']->id)->get()->toArray());
        $this->assertSame($membershipRowsBefore, DB::table('workspace_memberships')->count());
    }

    /** Contract 04 acceptance 5: View As never grants AgencyRebill / payer / funding authority. */
    /**
     * Security regression 9 / Contract 04 acceptance 5: View As grants no
     * AgencyRebill, payer or funding authority.
     *
     * Since Contract 09, AgencyRebill payer authority legitimately belongs to
     * the managing Agency's OWNER — through the relationship and ownership,
     * never through View As. So the proof is twofold: an active Agency
     * Admin with a live View As session of the Client gains none of it, and
     * the owner's payer-control answer is exactly the same with or without a
     * live session (View As adds nothing to anyone).
     */
    public function test_agency_view_as_confers_no_financial_authority_over_the_client(): void
    {
        $pair = $this->linkedPair();
        $billing = app(BillingProfileManager::class);

        // The owner: authority is identical before and during View As.
        $ownerId = (int) $pair['owner']->id;
        $ownerBefore = $billing->actorManagesPayerControls($pair['business'], $ownerId);

        $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']), 'Precondition: the owner\'s Agency View As session is live.');

        $this->assertSame($ownerBefore, $billing->actorManagesPayerControls($pair['business'], $ownerId), 'View As must not change the owner\'s payer authority.');

        // A non-owner Agency team member viewing the Client gains nothing.
        $admin = $this->memberOf($pair['agency'], WorkspaceMembershipRole::Admin);
        $adminId = (int) $admin->id;

        $this->viewAs()->startAgencyView($admin, $pair['agency']->uid, $pair['client']->uid);
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($admin), 'Precondition: the Agency Admin\'s View As session is live.');

        $this->assertFalse($billing->actorManagesPayerControls($pair['business'], $adminId));

        try {
            $billing->assertActorManagesPayerControls($pair['business'], $adminId);
            $this->fail('An Agency View As actor must not manage the client\'s payer controls.');
        } catch (UnauthorizedUsageBillingManagementException) {
            $this->addToAssertionCount(1);
        }

        $payerBefore = DB::table('business_payer_assignments')->where('business_id', $pair['business']->id)->first();

        foreach ([PayerType::AgencyRebill, PayerType::Business, PayerType::Workspace] as $payerType) {
            try {
                $billing->assignPayer($pair['business'], $payerType, $adminId, 'Attempted through View As.');
                $this->fail('An Agency View As actor must not assign the ' . $payerType->value . ' payer.');
            } catch (UnauthorizedPayerAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        $payerAfter = DB::table('business_payer_assignments')->where('business_id', $pair['business']->id)->first();
        $this->assertEquals($payerBefore, $payerAfter, 'No refused attempt may have changed the Client\'s payer.');

        // Structurally, the context carries no financial authority at all.
        $properties = array_map(
            fn (\ReflectionProperty $property): string => strtolower($property->getName()),
            (new ReflectionClass(ViewAsContext::class))->getProperties(),
        );

        foreach ($properties as $property) {
            foreach (['payer', 'wallet', 'fund', 'rebill', 'consent', 'billing'] as $financial) {
                $this->assertStringNotContainsString($financial, $property);
            }
        }
    }
}
