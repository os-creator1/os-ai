<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
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
     * A customer User who is a member of $workspace with exactly the given
     * customer permissions.
     *
     * @param  array<int, string>  $permissions
     */
    private function memberWith(Workspace $workspace, WorkspaceMembershipRole $role, array $permissions, bool $active = true): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode($permissions);
        $customer->save();

        $this->member($workspace, $customer->user, $role, active: $active);

        return $customer->user->fresh();
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

    public function test_a_permitted_agency_admin_starts_a_linked_client_view(): void
    {
        $pair = $this->linkedPair();
        $admin = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

        $session = $this->viewAs()->startAgencyView($admin, $pair['agency']->uid, $pair['client']->uid);

        $this->assertSame((int) $admin->id, (int) $session->actor_user_id);
        $this->assertSame((int) $pair['agency']->id, (int) $session->viewing_agency_workspace_id);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($admin));
    }

    /** Blueprint §2 as corrected: View As is not owner- or Admin-only. */
    public function test_permitted_agency_staff_starts_a_linked_client_view(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Staff, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

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

    public function test_an_agency_admin_without_the_permission_is_refused(): void
    {
        $pair = $this->linkedPair();
        $unpermittedAdmin = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Admin, ['access_backend']);

        $this->assertStartRefused($unpermittedAdmin, $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_agency_staff_without_the_permission_is_refused(): void
    {
        $pair = $this->linkedPair();
        $unpermittedStaff = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Staff, ['access_backend']);

        $this->assertStartRefused($unpermittedStaff, $pair['agency']->uid, $pair['client']->uid);
    }

    public function test_an_inactive_agency_member_holding_the_permission_is_refused(): void
    {
        $pair = $this->linkedPair();
        $inactive = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION], active: false);

        $this->assertStartRefused($inactive, $pair['agency']->uid, $pair['client']->uid);
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

    /** Agency authority is never inferred from Client Workspace membership (Addendum §2). */
    public function test_client_side_actors_never_gain_agency_view_as(): void
    {
        $pair = $this->linkedPair();

        $pair['clientOwner']->customer->update(['permissions' => json_encode([AgencyClientRelationshipManager::MANAGE_PERMISSION])]);
        $clientAdmin = $this->memberWith($pair['client'], WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);
        $clientStaff = $this->memberWith($pair['client'], WorkspaceMembershipRole::Staff, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

        foreach ([$pair['clientOwner'], $clientAdmin, $clientStaff] as $clientActor) {
            $this->assertStartRefused($clientActor, $pair['agency']->uid, $pair['client']->uid);
            // Naming its own Workspace as "the Agency" is a self-target no
            // relationship can ever satisfy.
            $this->assertStartRefused($clientActor, $pair['client']->uid, $pair['client']->uid);
        }
    }

    /** Platform status alone never qualifies, even with the platform relationship Role permission. */
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
    public function test_losing_agency_authority_mid_session_ends_it_as_access_lost(): void
    {
        $pair = $this->linkedPair();
        $staff = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Staff, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);
        $session = $this->viewAs()->startAgencyView($staff, $pair['agency']->uid, $pair['client']->uid);

        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($staff));

        $staff->customer->update(['permissions' => json_encode(['access_backend'])]);

        $this->assertSessionEndedWith($session, $staff, ViewAsSession::END_REASON_ACCESS_LOST);
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
        $staff = $this->memberWith($pair['agency'], WorkspaceMembershipRole::Staff, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

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
    public function test_agency_view_as_confers_no_financial_authority_over_the_client(): void
    {
        $pair = $this->linkedPair();
        $this->viewAs()->startAgencyView($pair['owner'], $pair['agency']->uid, $pair['client']->uid);
        $this->nextRequest();
        $this->assertNotNull($this->viewAs()->current($pair['owner']), 'Precondition: the Agency View As session is live.');

        $billing = app(BillingProfileManager::class);
        $agencyActorId = (int) $pair['owner']->id;

        $this->assertFalse($billing->actorManagesPayerControls($pair['business'], $agencyActorId));

        try {
            $billing->assertActorManagesPayerControls($pair['business'], $agencyActorId);
            $this->fail('An Agency View As actor must not manage the client\'s payer controls.');
        } catch (UnauthorizedUsageBillingManagementException) {
            $this->addToAssertionCount(1);
        }

        foreach ([PayerType::AgencyRebill, PayerType::Business, PayerType::Workspace] as $payerType) {
            try {
                $billing->assignPayer($pair['business'], $payerType, $agencyActorId, 'Attempted through View As.');
                $this->fail('An Agency View As actor must not assign the ' . $payerType->value . ' payer.');
            } catch (UnauthorizedPayerAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

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
