<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\AgencySaasPlan;
use App\Models\AgencyWhiteLabelChange;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementTransition;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency V1 completion, acceptance items 1, 4, 11 and 12 — the authorization
 * matrix across the Agency surfaces, for every actor the product distinguishes:
 * Platform Owner, Agency Owner, Agency Staff (and Admin), Client Owner, Client
 * Staff, another Agency's owner — plus the Location boundary and the audit
 * seams.
 *
 * Deep View As proofs (every authority, mid-session revocation, identity, no
 * financial authority) live in Tests\Feature\Workspace\AgencyViewAsTest and its
 * siblings, and the payer boundary in Tests\Feature\Usage\AgencyRebill*; this
 * file adds the cross-surface matrix and the seams between them.
 */
class AgencyAuthorizationMatrixTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private Workspace $agency;

    private Customer $agencyOwner;

    private Workspace $client;

    private Customer $clientOwner;

    private User $agencyAdmin;

    private User $agencyStaff;

    private User $inactiveAgencyAdmin;

    private User $clientStaff;

    private Workspace $otherAgency;

    private Customer $otherAgencyOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();

        $fixture = $this->createAgencyManagedClient(null, 'Alpha Dental Business', 'Alpha Dental', 'Agency Business', 'Northwind Agency');
        $this->agency = $fixture['agencyWorkspace'];
        $this->agencyOwner = $fixture['agencyOwner'];
        $this->client = $fixture['clientWorkspace'];
        $this->clientOwner = $fixture['clientOwner'];
        $this->assignTier($this->client, WorkspacePlanTier::Growth);

        $this->agencyAdmin = $this->memberUser($this->agency, WorkspaceMembershipRole::Admin);
        $this->agencyStaff = $this->memberUser($this->agency, WorkspaceMembershipRole::Staff);
        $this->inactiveAgencyAdmin = $this->memberUser($this->agency, WorkspaceMembershipRole::Admin, false);
        $this->clientStaff = $this->memberUser($this->client, WorkspaceMembershipRole::Staff);

        [$this->otherAgencyOwner, , $this->otherAgency] = $this->tenant(WorkspacePlanTier::Agency, 'Other Agency Business', 'Other Agency');
    }

    private function memberUser(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true): User
    {
        $customer = $this->createCustomer();
        $this->member($workspace, $customer->user, $role, WorkspaceBusinessAccessScope::All, $active);

        return $customer->user->fresh();
    }

    private function actAs(User $user): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $this->allCustomerPermissions()))]);
        $this->actingAs($user);
    }

    /** @return array<string, User> */
    private function actors(): array
    {
        return [
            'platform owner' => User::query()->findOrFail($this->platformAdminId()),
            'agency owner' => $this->agencyOwner->user->fresh(),
            'agency admin' => $this->agencyAdmin,
            'agency staff' => $this->agencyStaff,
            'inactive agency admin' => $this->inactiveAgencyAdmin,
            'client owner' => $this->clientOwner->user->fresh(),
            'client staff' => $this->clientStaff,
            'other agency owner' => $this->otherAgencyOwner->user->fresh(),
        ];
    }

    /** Who may open the Agency surfaces: the exact Agency's owner and ACTIVE Admin/Staff, by membership alone. */
    private const MAY_OPEN = ['agency owner', 'agency admin', 'agency staff'];

    /** @return array<string, string> label => url */
    private function agencySurfaces(): array
    {
        return [
            'clients list' => route('customer.workspaces.clients.index', $this->agency->uid),
            'client detail' => route('customer.workspaces.clients.show', [$this->agency->uid, $this->client->uid]),
            'saas plans' => route('customer.workspaces.agency.saas.plans', $this->agency->uid),
            'saas revenue' => route('customer.workspaces.agency.saas.revenue', $this->agency->uid),
            'stripe account' => route('customer.workspaces.agency.saas.stripe', $this->agency->uid),
            'white label' => route('customer.workspaces.agency.white-label.show', $this->agency->uid),
        ];
    }

    // ---------------------------------------------------------------- the matrix

    public function test_every_actor_by_every_agency_surface(): void
    {
        foreach ($this->actors() as $label => $user) {
            $this->actAs($user);

            foreach ($this->agencySurfaces() as $surface => $url) {
                $status = $this->get($url)->getStatusCode();

                if (in_array($label, self::MAY_OPEN, true)) {
                    $this->assertSame(200, $status, "{$label} must open {$surface}.");
                } else {
                    $this->assertNotSame(200, $status, "{$label} must NOT open {$surface} (got {$status}).");
                }
            }
        }
    }

    public function test_a_denied_actor_learns_nothing_about_whether_the_agency_exists(): void
    {
        $real = $this->agency->uid;
        $missing = '00000000-0000-4000-8000-000000000000';

        foreach (['client owner', 'client staff', 'other agency owner', 'inactive agency admin'] as $label) {
            $this->actAs($this->actors()[$label]);

            $realStatus = $this->get(route('customer.workspaces.clients.index', $real))->getStatusCode();
            $missingStatus = $this->get(route('customer.workspaces.clients.index', $missing))->getStatusCode();

            $this->assertSame(404, $realStatus, $label);
            $this->assertSame($missingStatus, $realStatus, "{$label}: a real Agency and a missing one must answer identically.");
        }
    }

    public function test_agency_writes_are_the_owners_alone_and_a_client_cannot_reach_them_at_all(): void
    {
        $planPayload = [
            'name' => 'Growth Partner', 'tier' => 'growth', 'price' => '349.00', 'currency_id' => $this->currencyId(),
            'billing_cycle' => 'monthly',
        ];
        $brandPayload = ['display_name' => 'Northwind Digital', 'is_enabled' => '1'];

        foreach (['agency admin', 'agency staff', 'client owner', 'client staff', 'other agency owner', 'platform owner'] as $label) {
            $this->actAs($this->actors()[$label]);

            $this->post(route('customer.workspaces.agency.saas.plans.store', $this->agency->uid), $planPayload);
            $this->post(route('customer.workspaces.agency.white-label.update', $this->agency->uid), $brandPayload);

            $this->assertSame(0, AgencySaasPlan::query()->count(), "{$label} must not create a resale plan.");
            $this->assertSame(0, AgencyWhiteLabelChange::query()->count(), "{$label} must not change the brand.");
        }

        // The owner can do both.
        $this->actAs($this->actors()['agency owner']);
        $this->post(route('customer.workspaces.agency.saas.plans.store', $this->agency->uid), $planPayload);
        $this->post(route('customer.workspaces.agency.white-label.update', $this->agency->uid), $brandPayload);

        $this->assertSame(1, AgencySaasPlan::query()->count());
        $this->assertSame(1, AgencyWhiteLabelChange::query()->count());
    }

    private function currencyId(): int
    {
        $existing = \App\Models\Currency::query()->where('code', 'USD')->value('id');

        return (int) ($existing ?? \Illuminate\Support\Facades\DB::table('currencies')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'US Dollar', 'code' => 'USD', 'format' => '$',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_the_relationship_cannot_be_ended_by_the_client_or_by_agency_team_members(): void
    {
        $manager = app(AgencyClientRelationshipManager::class);
        $relationship = $manager->findActiveForClientWorkspace((int) $this->client->id);

        foreach (['client owner', 'client staff', 'agency admin', 'agency staff', 'other agency owner'] as $label) {
            try {
                $manager->terminate((int) $this->actors()[$label]->id, $relationship, 'Test: should be refused.');
                $this->fail("{$label} ended the relationship.");
            } catch (UnauthorizedAgencyRelationshipManagementException) {
                $this->assertNotNull($manager->findActiveForClientWorkspace((int) $this->client->id), $label);
            }
        }

        // And no client-facing route exists that could do it.
        $offending = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'customer.') && preg_match('/relationship|terminate|leave-agency|unlink|clients\.(end|remove|disconnect)/i', $name) === 1)
            ->values()
            ->all();
        $this->assertSame([], $offending, 'A customer route can end an Agency relationship.');

        // The owner, and only the owner, can.
        $ended = $manager->terminate((int) $this->agencyOwner->user_id, $relationship, 'Test: owner ends it.');
        $this->assertFalse($ended->isActive());
        $this->assertNull($manager->findActiveForClientWorkspace((int) $this->client->id));
    }

    public function test_agency_a_and_agency_b_are_isolated_in_both_directions(): void
    {
        $clientB = $this->createAgencyManagedClient($this->otherAgency, 'Beta Business', 'Beta Clinic');

        // A's owner cannot open B's surfaces or B's client.
        $this->actAs($this->agencyOwner->user->fresh());
        $this->get(route('customer.workspaces.clients.index', $this->otherAgency->uid))->assertNotFound();
        $this->get(route('customer.workspaces.clients.show', [$this->otherAgency->uid, $clientB['clientWorkspace']->uid]))->assertNotFound();
        // ...nor reach B's client through A's own URL.
        $this->get(route('customer.workspaces.clients.show', [$this->agency->uid, $clientB['clientWorkspace']->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.clients.view-as', [$this->agency->uid, $clientB['clientWorkspace']->uid]))->assertNotFound();
        $this->assertNull(ViewAsSession::query()->first());

        // B's owner, symmetrically.
        $this->actAs($this->otherAgencyOwner->user->fresh());
        $this->get(route('customer.workspaces.clients.show', [$this->otherAgency->uid, $this->client->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.clients.view-as', [$this->otherAgency->uid, $this->client->uid]))->assertNotFound();
        $this->assertNull(ViewAsSession::query()->first());
    }

    // -------------------------------------------------------------- Location ACL

    public function test_location_scoped_client_staff_stay_location_scoped_when_an_agency_manages_the_workspace(): void
    {
        // A client built WITHOUT an Agency, with two Locations and one Location-scoped staff member.
        $independent = $this->createIndependentWorkspaceBusiness(businessName: 'Gamma Business', workspaceName: 'Gamma Group');
        $workspace = $independent['workspace'];
        $business = $independent['business'];
        $this->assignTier($workspace, WorkspacePlanTier::Growth);

        $locationA = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US', 'name' => 'Location A']);
        $locationB = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US', 'name' => 'Location B']);

        $staff = $this->createCustomer()->user;
        $membership = $this->createMembership($workspace, $staff, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locationA);

        $guard = app(LocationAccessGuard::class);
        $reach = fn () => $guard->accessibleLocationIdsForBusiness((int) $staff->id, $business->fresh());

        $before = $reach();
        $this->assertContains((int) $locationA->id, $before);
        $this->assertNotContains((int) $locationB->id, $before);

        // The Agency now manages this Workspace.
        app(AgencyClientRelationshipManager::class)->create((int) $this->agencyOwner->user_id, $this->agency, $workspace);

        $this->assertSame($before, $reach(), 'Agency management must not widen or narrow a staff member\'s Location reach.');
        $this->assertTrue($guard->userCanAccessLocation((int) $staff->id, $locationA));
        $this->assertFalse($guard->userCanAccessLocation((int) $staff->id, $locationB));

        // The Agency team have NO Location access of their own in the client's Business:
        // managing a Workspace is a lens (View As), not membership.
        foreach (['agency owner', 'agency admin', 'agency staff'] as $label) {
            foreach ([$locationA, $locationB] as $location) {
                $this->assertFalse(
                    $guard->userCanAccessLocation((int) $this->actors()[$label]->id, $location),
                    "{$label} must hold no direct Location access in a managed client's Business.",
                );
            }
        }
    }

    // ------------------------------------------------------------------- audit

    public function test_high_impact_agency_operations_leave_actor_attributed_audit_rows(): void
    {
        // Client created / relationship established: who and when, on the row itself.
        $relationship = app(AgencyClientRelationshipManager::class)->findActiveForClientWorkspace((int) $this->client->id);
        $this->assertSame((int) $this->agencyOwner->user_id, (int) $relationship->established_by_user_id);
        $this->assertNotNull($relationship->established_at);

        // Plan assigned: the entitlement ledger names the Workspace and the plan.
        $assigned = WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $this->client->id)->where('transition_type', 'plan_assigned')->first();
        $this->assertNotNull($assigned);
        $this->assertNotNull($assigned->to_plan_catalog_id);

        // Suspended / reactivated: each is a ledger row with the actor and a reason.
        $entitlements = app(EntitlementManager::class);
        $entitlements->changePlanStatus($this->client, \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Test: compliance hold.');
        $entitlements->changePlanStatus($this->client, \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Active, $this->platformAdminId(), 'Test: hold lifted.');

        $statusRows = WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $this->client->id)->where('transition_type', 'plan_status_changed')->orderBy('id')->get();
        $this->assertCount(2, $statusRows);
        $this->assertSame([$this->platformAdminId(), $this->platformAdminId()], $statusRows->pluck('actor_user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['suspended', 'active'], $statusRows->pluck('to_status')->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : (string) $s)->all());
        $this->assertSame(['Test: compliance hold.', 'Test: hold lifted.'], $statusRows->pluck('reason')->all());

        // View As started and stopped: a durable row attributed to the real Agency actor.
        $this->actAs($this->agencyStaff);
        $this->post(route('customer.workspaces.clients.view-as', [$this->agency->uid, $this->client->uid]))->assertRedirect();

        $session = ViewAsSession::query()->sole();
        $this->assertSame((int) $this->agencyStaff->id, (int) $session->actor_user_id);
        $this->assertSame((int) $this->agency->id, (int) $session->viewing_agency_workspace_id);
        $this->assertSame((int) $this->client->id, (int) $session->workspace_id);
        $this->assertNull($session->ended_at);

        // Termination ends it, with the reason recorded — "stopped" is audited, not just forgotten.
        app(AgencyClientRelationshipManager::class)->terminate(
            (int) $this->agencyOwner->user_id,
            app(AgencyClientRelationshipManager::class)->findActiveForClientWorkspace((int) $this->client->id),
            'Test: contract ended.',
        );
        $this->get(route('user.home'));

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertSame(ViewAsSession::END_REASON_RELATIONSHIP_ENDED, $session->end_reason);

        $history = app(AgencyClientRelationshipManager::class)->historyForClientWorkspace((int) $this->client->id);
        $this->assertSame('Test: contract ended.', $history->last()->termination_reason);
        $this->assertSame((int) $this->agencyOwner->user_id, (int) $history->last()->terminated_by_user_id);
    }
}
