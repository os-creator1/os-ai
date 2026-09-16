<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\AgencyClientRelationshipEstablished;
use App\Events\Workspace\AgencyClientRelationshipTerminated;
use App\Exceptions\Workspace\AgencyClientSelfLinkException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\ClientWorkspaceAlreadyManagedException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * V1 Implementation Contract 01 §13 — the Agency<->Client Workspace
 * relationship foundation.
 *
 * Every authority case in §6's matrix is asserted individually, including the
 * ones that must FAIL, because this slice's whole value is the authority it
 * refuses: the relationship itself does nothing yet, so a silent
 * over-permission here would surface only later, as a cross-tenant hole in
 * Contract 04's View As.
 *
 * Real concurrency (two connections racing for the same Client Workspace)
 * cannot live in this class: it needs committed rows, which RefreshDatabase's
 * open transaction hides from any other connection. It has its own file,
 * AgencyClientRelationshipConcurrencyTest, the same split
 * WorkspaceManagerTest / WorkspaceManagerConcurrencyTest already use.
 */
class AgencyClientRelationshipManagerTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private const ALL_RELATIONSHIP_EVENTS = [
        AgencyClientRelationshipEstablished::class,
        AgencyClientRelationshipTerminated::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists: EloquentAccountRepository::hasPermission() short-circuits
        // that id to "every permission", which would quietly invalidate
        // every denial asserted below.
        $this->platformAdminId();
    }

    private function manager(): AgencyClientRelationshipManager
    {
        return app(AgencyClientRelationshipManager::class);
    }

    /**
     * A Workspace on the Agency tier, plus its owner.
     *
     * @return array{0: Workspace, 1: User}
     */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user];
    }

    /**
     * An ordinary customer Workspace, plus its owner. Tier is assigned only
     * when asked for — an unassigned Workspace is a real state, and the
     * client side of this relationship has no tier requirement at all.
     *
     * @return array{0: Workspace, 1: User}
     */
    private function workspaceOn(?WorkspacePlanTier $tier = null, string $name = 'Alpha Dental'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);

        if ($tier !== null) {
            $this->assignTier($workspace, $tier);
        }

        return [$workspace->fresh(), $customer->user];
    }

    /**
     * A customer User who is an active (or deliberately inactive) member of
     * $workspace, holding exactly $permissions on the customer side.
     *
     * @param  array<int, string>  $permissions
     */
    private function memberOf(
        Workspace $workspace,
        WorkspaceMembershipRole $role,
        array $permissions = [],
        bool $active = true,
    ): User {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode($permissions);
        $customer->save();

        $this->createMembership($workspace, $customer->user, [
            'role' => $role,
            'is_active' => $active,
        ]);

        return $customer->user->fresh();
    }

    /**
     * An admin-panel User, optionally holding the dedicated admin-side
     * platform relationship Role permission (termination and migration-only
     * establishment). Never user id 1, so the assertion is about the Role
     * permission and nothing else.
     */
    private function adminPanelUser(bool $withPlatformPermission): User
    {
        $user = User::create([
            'first_name' => 'Support',
            'last_name' => 'Staff',
            'email' => 'admin-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $role = Role::create(['name' => 'role-' . uniqid('', true), 'status' => 1]);
        $role->permissions()->create(['name' => 'view workspace']);

        if ($withPlatformPermission) {
            $role->permissions()->create(['name' => AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION]);
        }

        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * A customer (non-admin) User whose own customer permission list carries
     * BOTH the Agency management permission and, by name, the admin-side
     * platform permission — the strongest non-admin impersonation of a
     * migration operator a permission list alone can produce.
     */
    private function customerNamingThePlatformPermission(): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode([
            AgencyClientRelationshipManager::MANAGE_PERMISSION,
            AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION,
        ]);
        $customer->save();

        return $customer->user->fresh();
    }

    /**
     * One global User that is BOTH an admin-panel account and a customer
     * (is_admin and is_customer), with a customer permission list and an
     * admin Role that are set independently — so a test can put the platform
     * permission in exactly one of the two permission domains.
     *
     * @param  array<int, string>  $customerPermissions
     */
    private function dualAdminCustomer(bool $roleHoldsPlatformPermission, array $customerPermissions = []): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode($customerPermissions);
        $customer->save();

        $user = $customer->user;
        $user->is_admin = true;
        $user->save();

        $role = Role::create(['name' => 'dual-role-' . uniqid('', true), 'status' => 1]);
        $role->permissions()->create(['name' => 'view workspace']);

        if ($roleHoldsPlatformPermission) {
            $role->permissions()->create(['name' => AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION]);
        }

        $user->roles()->attach($role->id);

        $user = $user->fresh();

        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_customer);

        return $user;
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

    /** A customer User owning nothing relevant, holding the management permission anyway. */
    private function outsiderHoldingThePermission(): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode([AgencyClientRelationshipManager::MANAGE_PERMISSION]);
        $customer->save();

        return $customer->user->fresh();
    }

    private function established(?Workspace $agency = null, ?User $owner = null, ?Workspace $client = null): AgencyClientWorkspaceRelationship
    {
        if ($agency === null || $owner === null) {
            [$agency, $owner] = $this->agency();
        }

        $client ??= $this->workspaceOn()[0];

        return $this->manager()->create((int) $owner->id, $agency, $client);
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    public function test_the_agency_owner_establishes_an_active_relationship_with_a_real_audit_trail(): void
    {
        Event::fake(self::ALL_RELATIONSHIP_EVENTS);

        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn(WorkspacePlanTier::Core);

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        $this->assertSame((int) $agency->id, (int) $relationship->agency_workspace_id);
        $this->assertSame((int) $client->id, (int) $relationship->client_workspace_id);
        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationship->status);
        $this->assertSame((int) $agencyOwner->id, (int) $relationship->established_by_user_id);
        $this->assertNotNull($relationship->established_at);
        $this->assertNull($relationship->terminated_by_user_id);
        $this->assertNull($relationship->terminated_at);
        $this->assertNull($relationship->termination_reason);
        $this->assertTrue(Str::isUuid((string) $relationship->uid));

        $this->assertSame(1, DB::table('agency_client_workspace_relationships')->count());

        Event::assertDispatched(AgencyClientRelationshipEstablished::class, 1);
        Event::assertDispatched(
            AgencyClientRelationshipEstablished::class,
            fn (AgencyClientRelationshipEstablished $event): bool => $event->relationshipId === (int) $relationship->id
                && $event->agencyWorkspaceId === (int) $agency->id
                && $event->clientWorkspaceId === (int) $client->id
                && $event->actorUserId === (int) $agencyOwner->id,
        );
    }

    public function test_one_agency_may_manage_many_client_workspaces(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$first] = $this->workspaceOn(null, 'First Client');
        [$second] = $this->workspaceOn(null, 'Second Client');

        $this->manager()->create((int) $agencyOwner->id, $agency, $first);
        $this->manager()->create((int) $agencyOwner->id, $agency, $second);

        $active = $this->manager()->findActiveForAgencyWorkspace((int) $agency->id);

        $this->assertCount(2, $active);
        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $second->id],
            $active->map(fn ($row) => (int) $row->client_workspace_id)->all(),
        );
    }

    /** Blueprint §2 as corrected: an Agency team member, not only the owner. */
    public function test_an_agency_admin_or_staff_member_holding_the_permission_may_establish_a_relationship(): void
    {
        foreach ([WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff] as $role) {
            [$agency] = $this->agency('Agency for ' . $role->value);
            [$client] = $this->workspaceOn(null, 'Client for ' . $role->value);

            $member = $this->memberOf($agency, $role, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

            $relationship = $this->manager()->create((int) $member->id, $agency, $client);

            $this->assertSame(AgencyClientRelationshipStatus::Active, $relationship->status);
            $this->assertSame((int) $member->id, (int) $relationship->established_by_user_id);
        }
    }

    // ------------------------------------------------------------------
    // Authority matrix — creation (Contract 01 §6)
    // ------------------------------------------------------------------

    public function test_an_agency_member_without_the_permission_cannot_establish_a_relationship(): void
    {
        foreach ([WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff] as $role) {
            [$agency] = $this->agency('Agency for bare ' . $role->value);
            [$client] = $this->workspaceOn(null, 'Client for bare ' . $role->value);

            $member = $this->memberOf($agency, $role);

            $this->assertRefusesToCreate($member, $agency, $client);
        }
    }

    public function test_an_inactive_agency_member_holding_the_permission_cannot_establish_a_relationship(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();

        $member = $this->memberOf(
            $agency,
            WorkspaceMembershipRole::Admin,
            [AgencyClientRelationshipManager::MANAGE_PERMISSION],
            active: false,
        );

        $this->assertRefusesToCreate($member, $agency, $client);
    }

    public function test_the_client_workspace_owner_cannot_establish_the_relationship(): void
    {
        [$agency] = $this->agency();
        [$client, $clientOwner] = $this->workspaceOn();

        $clientOwner->customer->update(['permissions' => json_encode([AgencyClientRelationshipManager::MANAGE_PERMISSION])]);

        $this->assertRefusesToCreate($clientOwner, $agency, $client);
    }

    /**
     * Addendum §2's own sentence, asserted: Agency authority is never
     * inferred from ordinary membership in the CLIENT Workspace. This actor
     * is an active Admin of the client, holding the management permission,
     * and is still nobody on the Agency side.
     */
    public function test_membership_in_the_client_workspace_grants_no_agency_authority(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();

        $clientAdmin = $this->memberOf(
            $client,
            WorkspaceMembershipRole::Admin,
            [AgencyClientRelationshipManager::MANAGE_PERMISSION],
        );

        $this->assertRefusesToCreate($clientAdmin, $agency, $client);
    }

    public function test_an_unrelated_third_workspace_owner_cannot_establish_a_relationship(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();

        $this->assertRefusesToCreate($this->outsiderHoldingThePermission(), $agency, $client);
    }

    /**
     * Addendum §10's posture — an administrator never originates on a
     * customer's behalf — applied symmetrically to normal product creation:
     * platform status alone grants nothing, including for the admin who may
     * legitimately TERMINATE one or establish one through the separate
     * operator-run migration entry point. (A User who is ALSO genuinely the
     * Agency owner or a permitted member is covered separately.)
     */
    public function test_platform_status_alone_never_establishes_a_relationship_through_normal_create(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();

        $this->assertRefusesToCreate($this->adminPanelUser(withPlatformPermission: false), $agency, $client);
        $this->assertRefusesToCreate($this->adminPanelUser(withPlatformPermission: true), $agency, $client);
    }

    // ------------------------------------------------------------------
    // Adversarial creation
    // ------------------------------------------------------------------

    public function test_a_workspace_cannot_be_established_as_the_managing_agency_of_itself(): void
    {
        [$agency, $agencyOwner] = $this->agency();

        $this->expectException(AgencyClientSelfLinkException::class);

        try {
            $this->manager()->create((int) $agencyOwner->id, $agency, $agency);
        } finally {
            $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
        }
    }

    public function test_an_already_managed_client_workspace_cannot_be_claimed_by_a_second_agency(): void
    {
        [$firstAgency, $firstOwner] = $this->agency('First Agency');
        [$secondAgency, $secondOwner] = $this->agency('Second Agency');
        [$client] = $this->workspaceOn();

        $this->manager()->create((int) $firstOwner->id, $firstAgency, $client);

        try {
            $this->manager()->create((int) $secondOwner->id, $secondAgency, $client);
            $this->fail('A second Agency must not be able to manage an already-managed Client Workspace.');
        } catch (ClientWorkspaceAlreadyManagedException $e) {
            $this->assertSame((int) $client->id, $e->clientWorkspaceId);
            $this->assertSame((int) $firstAgency->id, $e->existingAgencyWorkspaceId);
        }

        $this->assertSame(1, DB::table('agency_client_workspace_relationships')->count());
    }

    /** The same Agency re-running the same action is not silently idempotent either. */
    public function test_the_same_agency_cannot_establish_the_same_relationship_twice(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        $this->expectException(ClientWorkspaceAlreadyManagedException::class);

        try {
            $this->manager()->create((int) $agencyOwner->id, $agency, $client);
        } finally {
            $this->assertSame(1, DB::table('agency_client_workspace_relationships')->count());
        }
    }

    public function test_a_workspace_that_is_not_on_the_agency_tier_cannot_manage_a_client(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, null] as $tier) {
            [$notAnAgency, $owner] = $this->workspaceOn($tier, 'Pretender ' . ($tier?->value ?? 'unassigned'));
            [$client] = $this->workspaceOn(null, 'Client of ' . ($tier?->value ?? 'unassigned'));

            try {
                $this->manager()->create((int) $owner->id, $notAnAgency, $client);
                $this->fail('A ' . ($tier?->value ?? 'plan-less') . ' Workspace must not be able to manage a Client Workspace.');
            } catch (AgencyWorkspaceNotEligibleException $e) {
                $this->assertSame((int) $notAnAgency->id, $e->workspaceId);
                $this->assertSame($tier?->value, $e->tier);
            }
        }

        $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
    }

    /**
     * Authority is asserted before entitlement, so an actor who may not
     * manage this Agency never learns anything about its plan.
     */
    public function test_authority_is_refused_before_the_plan_tier_is_ever_considered(): void
    {
        [$notAnAgency] = $this->workspaceOn(WorkspacePlanTier::Core, 'Core Workspace');
        [$client] = $this->workspaceOn();

        $this->assertRefusesToCreate($this->outsiderHoldingThePermission(), $notAnAgency, $client);
    }

    public function test_a_missing_workspace_is_refused_rather_than_linked(): void
    {
        [$agency, $agencyOwner] = $this->agency();

        $vanished = new Workspace();
        $vanished->id = 99_999_999;

        $this->expectException(WorkspaceNotFoundException::class);

        try {
            $this->manager()->create((int) $agencyOwner->id, $agency, $vanished);
        } finally {
            $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
        }
    }

    /**
     * The database's own backstop, independent of the manager: the
     * generated-column unique index refuses a second ACTIVE row for one
     * Client Workspace even when the domain layer is bypassed entirely.
     */
    public function test_the_database_itself_refuses_a_second_active_row_for_the_same_client(): void
    {
        [$agency, $agencyOwner] = $this->agency('First Agency');
        [$otherAgency] = $this->agency('Second Agency');
        [$client] = $this->workspaceOn();

        $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        $this->expectException(QueryException::class);

        DB::table('agency_client_workspace_relationships')->insert([
            'uid' => (string) Str::uuid(),
            'agency_workspace_id' => $otherAgency->id,
            'client_workspace_id' => $client->id,
            'status' => AgencyClientRelationshipStatus::Active->value,
            'established_by_user_id' => $agencyOwner->id,
            'established_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ...while placing no limit at all on how much terminated history one client accumulates. */
    public function test_the_database_allows_many_terminated_rows_for_the_same_client(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        foreach (range(1, 3) as $round) {
            $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
            $this->manager()->terminate((int) $agencyOwner->id, $relationship, 'Round ' . $round . ' ended.');
        }

        $this->assertSame(3, DB::table('agency_client_workspace_relationships')->where('client_workspace_id', $client->id)->count());
        $this->assertNull($this->manager()->findActiveForClientWorkspace((int) $client->id));
    }

    /**
     * Contract 01 §7 fixes the lock order, and the order is the whole
     * mechanism: both Workspace rows are locked, ascending by id, BEFORE the
     * duplicate check reads the relationships table — otherwise two racers
     * could both pass that check and one would be stopped only by the raw
     * unique index. Asserted structurally because the outcome alone cannot
     * distinguish it: the foreign keys take parent-row locks of their own at
     * insert time, which mask a missing explicit lock in most interleavings.
     */
    public function test_both_workspace_rows_are_locked_in_ascending_id_order_before_the_duplicate_check(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $this->assertEstablishesUnderTheContractLockOrder(
            fn () => $this->manager()->create((int) $agencyOwner->id, $agency, $client),
            $agency,
            $client,
        );
    }

    // ------------------------------------------------------------------
    // Migration-only establishment (Contract 01 §6/§9, consumed by
    // Contract 10 step 4) — a distinct, non-surface entry point
    // ------------------------------------------------------------------

    public function test_a_migration_operator_establishes_the_relationship_and_is_recorded_as_the_real_actor(): void
    {
        Event::fake(self::ALL_RELATIONSHIP_EVENTS);

        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn(WorkspacePlanTier::Core);
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        $relationship = $this->manager()->createForMigration((int) $operator->id, $agency, $client);

        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationship->status);
        $this->assertSame((int) $agency->id, (int) $relationship->agency_workspace_id);
        $this->assertSame((int) $client->id, (int) $relationship->client_workspace_id);
        $this->assertNotNull($relationship->established_at);
        $this->assertTrue(Str::isUuid((string) $relationship->uid));

        // The operator, never a fabricated Agency-owner actor.
        $this->assertSame((int) $operator->id, (int) $relationship->established_by_user_id);
        $this->assertNotSame((int) $agencyOwner->id, (int) $relationship->established_by_user_id);
        $this->assertSame(
            (int) $operator->id,
            (int) DB::table('agency_client_workspace_relationships')->where('id', $relationship->id)->value('established_by_user_id'),
        );

        // The same event, with the same shape, as a product-created relationship.
        Event::assertDispatched(AgencyClientRelationshipEstablished::class, 1);
        Event::assertDispatched(
            AgencyClientRelationshipEstablished::class,
            fn (AgencyClientRelationshipEstablished $event): bool => $event->relationshipId === (int) $relationship->id
                && $event->agencyWorkspaceId === (int) $agency->id
                && $event->clientWorkspaceId === (int) $client->id
                && $event->actorUserId === (int) $operator->id,
        );
    }

    /**
     * The exact actor createForMigration() accepts is still refused by the
     * normal product create() — the migration entry point widens nothing.
     */
    public function test_the_same_platform_operator_is_still_refused_by_normal_create(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        $this->assertTrue(
            Gate::forUser($operator)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: this operator genuinely holds the platform relationship permission.'
        );

        $this->assertRefusesToCreate($operator, $agency, $client);

        // ...and the very same operator, on the very same pair, succeeds only
        // through the migration entry point.
        $relationship = $this->manager()->createForMigration((int) $operator->id, $agency, $client);
        $this->assertSame((int) $operator->id, (int) $relationship->established_by_user_id);
    }

    public function test_an_admin_panel_user_without_the_dedicated_permission_cannot_migrate_a_relationship(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();

        $this->assertRefusesToCreateForMigration($this->adminPanelUser(withPlatformPermission: false), $agency, $client);
    }

    /**
     * is_admin is load-bearing, not decorative: a customer whose own
     * permission list names the platform permission — and whom the Gate
     * therefore genuinely allows it — is still not a migration operator.
     */
    public function test_a_non_admin_naming_the_platform_permission_cannot_migrate_a_relationship(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();
        $impostor = $this->customerNamingThePlatformPermission();

        $this->assertFalse((bool) $impostor->is_admin);
        $this->assertTrue(
            Gate::forUser($impostor)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the Gate itself allows this customer the permission by name, so only is_admin can refuse it.'
        );

        $this->assertRefusesToCreateForMigration($impostor, $agency, $client);
    }

    /**
     * Agency actors act through create(); the migration entry point has no
     * owner or team branch, so it can never become a second, unaudited
     * create() for them.
     */
    public function test_no_agency_or_customer_actor_can_use_the_migration_entry_point(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client, $clientOwner] = $this->workspaceOn();

        $agencyOwner->customer->update(['permissions' => json_encode([AgencyClientRelationshipManager::MANAGE_PERMISSION])]);

        $this->assertRefusesToCreateForMigration($agencyOwner, $agency, $client);
        $this->assertRefusesToCreateForMigration(
            $this->memberOf($agency, WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION]),
            $agency,
            $client,
        );
        $this->assertRefusesToCreateForMigration(
            $this->memberOf($agency, WorkspaceMembershipRole::Staff, [AgencyClientRelationshipManager::MANAGE_PERMISSION]),
            $agency,
            $client,
        );
        $this->assertRefusesToCreateForMigration($clientOwner, $agency, $client);
        $this->assertRefusesToCreateForMigration($this->outsiderHoldingThePermission(), $agency, $client);

        // The owner remains fully able to act through the product path.
        $this->assertSame(
            (int) $agencyOwner->id,
            (int) $this->manager()->create((int) $agencyOwner->id, $agency, $client)->established_by_user_id,
        );
    }

    public function test_migration_creation_refuses_a_self_link(): void
    {
        [$agency] = $this->agency();
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        $this->expectException(AgencyClientSelfLinkException::class);

        try {
            $this->manager()->createForMigration((int) $operator->id, $agency, $agency);
        } finally {
            $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
        }
    }

    public function test_migration_creation_refuses_a_workspace_not_on_the_agency_tier(): void
    {
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, null] as $tier) {
            [$notAnAgency] = $this->workspaceOn($tier, 'Migrating pretender ' . ($tier?->value ?? 'unassigned'));
            [$client] = $this->workspaceOn(null, 'Migrating client of ' . ($tier?->value ?? 'unassigned'));

            try {
                $this->manager()->createForMigration((int) $operator->id, $notAnAgency, $client);
                $this->fail('Migration must not let a ' . ($tier?->value ?? 'plan-less') . ' Workspace manage a Client Workspace.');
            } catch (AgencyWorkspaceNotEligibleException $e) {
                $this->assertSame((int) $notAnAgency->id, $e->workspaceId);
                $this->assertSame($tier?->value, $e->tier);
            }
        }

        $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
    }

    /** Authority first on this entry point too: a non-operator learns nothing about the plan. */
    public function test_migration_authority_is_refused_before_the_plan_tier_is_ever_considered(): void
    {
        [$notAnAgency] = $this->workspaceOn(WorkspacePlanTier::Core, 'Core Workspace');
        [$client] = $this->workspaceOn();

        $this->assertRefusesToCreateForMigration($this->adminPanelUser(withPlatformPermission: false), $notAnAgency, $client);
    }

    /**
     * One-active-Agency uniqueness is one rule across both entry points: a
     * migrated relationship blocks a product one and vice versa, and the
     * same Agency cannot be linked twice through either.
     */
    public function test_one_active_agency_uniqueness_is_shared_by_both_entry_points(): void
    {
        [$firstAgency, $firstOwner] = $this->agency('First Agency');
        [$secondAgency, $secondOwner] = $this->agency('Second Agency');
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        // Migrated first, then an Agency tries the product path.
        [$migratedClient] = $this->workspaceOn(null, 'Migrated Client');
        $this->manager()->createForMigration((int) $operator->id, $firstAgency, $migratedClient);

        foreach ([
            fn () => $this->manager()->create((int) $secondOwner->id, $secondAgency, $migratedClient),
            fn () => $this->manager()->create((int) $firstOwner->id, $firstAgency, $migratedClient),
            fn () => $this->manager()->createForMigration((int) $operator->id, $secondAgency, $migratedClient),
            fn () => $this->manager()->createForMigration((int) $operator->id, $firstAgency, $migratedClient),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An already-managed Client Workspace must not gain a second active relationship.');
            } catch (ClientWorkspaceAlreadyManagedException $e) {
                $this->assertSame((int) $firstAgency->id, $e->existingAgencyWorkspaceId);
            }
        }

        // Product-created first, then the migration tries.
        [$productClient] = $this->workspaceOn(null, 'Product Client');
        $this->manager()->create((int) $firstOwner->id, $firstAgency, $productClient);

        try {
            $this->manager()->createForMigration((int) $operator->id, $secondAgency, $productClient);
            $this->fail('The migration must not add a second active Agency to an already-managed Client Workspace.');
        } catch (ClientWorkspaceAlreadyManagedException $e) {
            $this->assertSame((int) $firstAgency->id, $e->existingAgencyWorkspaceId);
        }

        $this->assertSame(1, DB::table('agency_client_workspace_relationships')->where('client_workspace_id', $migratedClient->id)->count());
        $this->assertSame(1, DB::table('agency_client_workspace_relationships')->where('client_workspace_id', $productClient->id)->count());
    }

    public function test_migration_creation_refuses_a_missing_workspace(): void
    {
        [$agency] = $this->agency();
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        $vanished = new Workspace();
        $vanished->id = 99_999_999;

        $this->expectException(WorkspaceNotFoundException::class);

        try {
            $this->manager()->createForMigration((int) $operator->id, $agency, $vanished);
        } finally {
            $this->assertSame(0, DB::table('agency_client_workspace_relationships')->count());
        }
    }

    /** The same race safety as create(), structurally: the same locks, in the same order. */
    public function test_migration_creation_takes_the_same_locks_in_the_same_order(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        $this->assertEstablishesUnderTheContractLockOrder(
            fn () => $this->manager()->createForMigration((int) $operator->id, $agency, $client),
            $agency,
            $client,
        );
    }

    /**
     * How a relationship was established changes nothing about who may end
     * it: the Agency owner and the platform operator may; an Agency team
     * member may not.
     */
    public function test_termination_authority_is_unchanged_for_a_migrated_relationship(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $operator = $this->adminPanelUser(withPlatformPermission: true);

        [$firstClient] = $this->workspaceOn(null, 'First migrated client');
        $first = $this->manager()->createForMigration((int) $operator->id, $agency, $firstClient);

        $this->assertRefusesToTerminate(
            $this->memberOf($agency, WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION]),
            $first,
        );
        $this->assertRefusesToTerminate($this->adminPanelUser(withPlatformPermission: false), $first);

        $endedByOwner = $this->manager()->terminate((int) $agencyOwner->id, $first, 'Agency ended a migrated client.');
        $this->assertSame((int) $agencyOwner->id, (int) $endedByOwner->terminated_by_user_id);
        $this->assertSame((int) $operator->id, (int) $endedByOwner->established_by_user_id);

        [$secondClient] = $this->workspaceOn(null, 'Second migrated client');
        $second = $this->manager()->createForMigration((int) $operator->id, $agency, $secondClient);

        $endedByPlatform = $this->manager()->terminate((int) $operator->id, $second, 'Platform ended a migrated client.');
        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $endedByPlatform->status);
        $this->assertSame((int) $operator->id, (int) $endedByPlatform->terminated_by_user_id);
    }

    // ------------------------------------------------------------------
    // Platform relationship authority reads ADMIN Role permissions directly
    // (Contract 01 §6) — never the generic mixed-account Gate's choice of
    // session, customer, or Role permission source
    // ------------------------------------------------------------------

    /**
     * A. A dual admin+customer account whose admin Role genuinely holds the
     * permission is a platform operator — even though the generic Gate,
     * which resolves a customer account's own permission list first, says
     * otherwise.
     */
    public function test_a_dual_admin_customer_whose_admin_role_holds_the_permission_may_migrate(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();
        $operator = $this->dualAdminCustomer(roleHoldsPlatformPermission: true, customerPermissions: ['access_backend']);

        $this->assertFalse(
            Gate::forUser($operator)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the generic Gate reads this dual account\'s customer list and misses the genuine Role grant.'
        );

        $relationship = $this->manager()->createForMigration((int) $operator->id, $agency, $client);

        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationship->status);
        $this->assertSame((int) $operator->id, (int) $relationship->established_by_user_id);
    }

    /**
     * B. The same dual account shape, but the permission text lives only in
     * the CUSTOMER's stored permission list — which the generic Gate would
     * accept — and not in any admin Role: not a platform operator.
     */
    public function test_a_dual_admin_customer_with_the_permission_only_in_customer_permissions_cannot_migrate(): void
    {
        [$agency] = $this->agency();
        [$client] = $this->workspaceOn();
        $impostor = $this->dualAdminCustomer(
            roleHoldsPlatformPermission: false,
            customerPermissions: [AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION],
        );

        $this->assertTrue(
            Gate::forUser($impostor)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the generic Gate would be satisfied by the customer list alone.'
        );

        $this->assertRefusesToCreateForMigration($impostor, $agency, $client);
    }

    /** C. The same customer-list-only dual account cannot terminate on the platform's behalf either. */
    public function test_a_dual_admin_customer_with_the_permission_only_in_customer_permissions_cannot_terminate(): void
    {
        $relationship = $this->established();
        $impostor = $this->dualAdminCustomer(
            roleHoldsPlatformPermission: false,
            customerPermissions: [AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION],
        );

        $this->assertTrue(Gate::forUser($impostor)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION));

        $this->assertRefusesToTerminate($impostor, $relationship);
    }

    /**
     * D. A session permission list naming the permission — which the generic
     * Gate resolves before anything else — grants no platform relationship
     * authority to an admin whose Role does not hold it.
     */
    public function test_session_permissions_naming_it_grant_no_platform_relationship_authority(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();
        [$otherClient] = $this->workspaceOn(null, 'Other Client');
        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $otherClient);
        $admin = $this->adminPanelUser(withPlatformPermission: false);

        session()->put('permissions', collect([AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION]));

        $this->assertTrue(
            Gate::forUser($admin)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the generic Gate would be satisfied by the session list alone.'
        );

        $this->assertRefusesToCreateForMigration($admin, $agency, $client);
        $this->assertRefusesToTerminate($admin, $relationship);
    }

    /**
     * E. The genuine admin Role grant is sufficient on its own, even when the
     * session and customer permission lists — which the generic Gate would
     * consult first — do not carry it.
     */
    public function test_the_admin_role_permission_grants_authority_when_session_and_customer_lists_lack_it(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();
        [$otherClient] = $this->workspaceOn(null, 'Other Client');
        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $otherClient);
        $operator = $this->dualAdminCustomer(roleHoldsPlatformPermission: true, customerPermissions: ['access_backend']);

        session()->put('permissions', collect(['access_backend', 'view workspace']));

        $this->assertFalse(
            Gate::forUser($operator)->allows(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the generic Gate reads the session list and misses the genuine Role grant.'
        );

        $migrated = $this->manager()->createForMigration((int) $operator->id, $agency, $client);
        $this->assertSame((int) $operator->id, (int) $migrated->established_by_user_id);

        $terminated = $this->manager()->terminate((int) $operator->id, $relationship, 'Platform intervention.');
        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $terminated->status);
        $this->assertSame((int) $operator->id, (int) $terminated->terminated_by_user_id);
    }

    /**
     * F. User id 1 keeps the repository's existing super-admin convention for
     * platform relationship authority without holding any Role — but only
     * while it is still an admin account.
     */
    public function test_user_id_1_keeps_super_admin_platform_authority_only_while_an_admin(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();
        [$terminableClient] = $this->workspaceOn(null, 'Terminable Client');
        [$laterClient] = $this->workspaceOn(null, 'Later Client');
        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $terminableClient);

        $superAdmin = $this->superAdmin();

        $this->assertSame(1, (int) $superAdmin->id);
        $this->assertTrue($superAdmin->is_admin);
        $this->assertFalse(
            $superAdmin->getPermissions()->contains(AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION),
            'Precondition: the super admin holds no Role grant, so only the id-1 convention can authorize it.'
        );

        $migrated = $this->manager()->createForMigration(1, $agency, $client);
        $this->assertSame(1, (int) $migrated->established_by_user_id);

        $terminated = $this->manager()->terminate(1, $relationship, 'Super admin intervention.');
        $this->assertSame(1, (int) $terminated->terminated_by_user_id);

        // No longer an admin account: the id-1 convention no longer applies.
        DB::table('users')->where('id', 1)->update(['is_admin' => false]);

        $this->assertRefusesToCreateForMigration(User::query()->find(1), $agency, $laterClient);
    }

    /**
     * Normal create() for a dual-role User: platform status adds nothing and
     * erases nothing. The same admin+customer account — whose admin Role even
     * holds the platform permission — creates through genuine Agency
     * ownership, creates through genuine permitted Agency membership, and is
     * refused on an Agency where it has neither.
     */
    public function test_a_dual_role_user_may_create_only_through_genuine_agency_authority(): void
    {
        $dual = $this->dualAdminCustomer(
            roleHoldsPlatformPermission: true,
            customerPermissions: [AgencyClientRelationshipManager::MANAGE_PERMISSION],
        );

        // Genuine Agency owner.
        $ownedAgency = $this->createWorkspace($dual, ['name' => 'Dual-owned Agency']);
        $this->assignTier($ownedAgency, WorkspacePlanTier::Agency);
        [$ownedClient] = $this->workspaceOn(null, 'Client of the dual owner');

        $byOwnership = $this->manager()->create((int) $dual->id, $ownedAgency->fresh(), $ownedClient);
        $this->assertSame((int) $dual->id, (int) $byOwnership->established_by_user_id);

        // Genuine, permitted, active member of someone else's Agency.
        [$memberAgency] = $this->agency('Agency the dual user works for');
        $this->createMembership($memberAgency, $dual, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        [$memberClient] = $this->workspaceOn(null, 'Client worked by the dual member');

        $byMembership = $this->manager()->create((int) $dual->id, $memberAgency, $memberClient);
        $this->assertSame((int) $dual->id, (int) $byMembership->established_by_user_id);

        // No ownership and no membership: platform status buys nothing.
        [$strangerAgency] = $this->agency('Unrelated Agency');
        [$strangerClient] = $this->workspaceOn(null, 'Unrelated Client');

        $this->assertRefusesToCreate($dual, $strangerAgency, $strangerClient);
    }

    // ------------------------------------------------------------------
    // Termination (Contract 01 §6 — strictly narrower than management)
    // ------------------------------------------------------------------

    public function test_the_agency_owner_terminates_and_the_row_becomes_history(): void
    {
        Event::fake(self::ALL_RELATIONSHIP_EVENTS);

        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
        $terminated = $this->manager()->terminate((int) $agencyOwner->id, $relationship, '  Client moved in-house.  ');

        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $terminated->status);
        $this->assertSame((int) $agencyOwner->id, (int) $terminated->terminated_by_user_id);
        $this->assertNotNull($terminated->terminated_at);
        $this->assertSame('Client moved in-house.', $terminated->termination_reason);

        // The establishment audit survives its own termination.
        $this->assertSame((int) $agencyOwner->id, (int) $terminated->established_by_user_id);
        $this->assertNotNull($terminated->established_at);

        Event::assertDispatched(AgencyClientRelationshipTerminated::class, 1);
        Event::assertDispatched(
            AgencyClientRelationshipTerminated::class,
            fn (AgencyClientRelationshipTerminated $event): bool => $event->relationshipId === (int) $relationship->id
                && $event->agencyWorkspaceId === (int) $agency->id
                && $event->clientWorkspaceId === (int) $client->id
                && $event->actorUserId === (int) $agencyOwner->id
                && $event->reason === 'Client moved in-house.',
        );
    }

    public function test_an_admin_panel_actor_holding_the_dedicated_permission_may_terminate(): void
    {
        $relationship = $this->established();
        $platform = $this->adminPanelUser(withPlatformPermission: true);

        $terminated = $this->manager()->terminate((int) $platform->id, $relationship, 'Platform intervention.');

        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $terminated->status);
        $this->assertSame((int) $platform->id, (int) $terminated->terminated_by_user_id);
    }

    /**
     * The whole point of the dedicated permission: an admin-panel account
     * with other admin permissions is not the Platform Owner.
     */
    public function test_an_admin_panel_actor_without_the_dedicated_permission_cannot_terminate(): void
    {
        $relationship = $this->established();

        $this->assertRefusesToTerminate($this->adminPanelUser(withPlatformPermission: false), $relationship);
    }

    public function test_no_agency_team_member_may_terminate_however_permitted(): void
    {
        foreach ([WorkspaceMembershipRole::Admin, WorkspaceMembershipRole::Staff] as $role) {
            [$agency, $agencyOwner] = $this->agency('Agency for terminating ' . $role->value);
            [$client] = $this->workspaceOn(null, 'Client for terminating ' . $role->value);

            $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
            $member = $this->memberOf($agency, $role, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);

            $this->assertRefusesToTerminate($member, $relationship);
        }
    }

    public function test_the_client_workspace_cannot_remove_its_own_managing_relationship(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client, $clientOwner] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        $clientOwner->customer->update(['permissions' => json_encode([AgencyClientRelationshipManager::MANAGE_PERMISSION])]);
        $this->assertRefusesToTerminate($clientOwner, $relationship);

        $clientAdmin = $this->memberOf($client, WorkspaceMembershipRole::Admin, [AgencyClientRelationshipManager::MANAGE_PERMISSION]);
        $this->assertRefusesToTerminate($clientAdmin, $relationship);
    }

    public function test_an_unrelated_third_workspace_owner_cannot_terminate(): void
    {
        $relationship = $this->established();

        $this->assertRefusesToTerminate($this->outsiderHoldingThePermission(), $relationship);
    }

    public function test_termination_requires_a_reason(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->manager()->terminate((int) $agencyOwner->id, $relationship, '   ');
        } finally {
            $this->assertSame(
                AgencyClientRelationshipStatus::Active,
                $this->manager()->findActiveForClientWorkspace((int) $client->id)?->status,
            );
        }
    }

    public function test_a_relationship_that_does_not_exist_cannot_be_terminated(): void
    {
        [$agency] = $this->agency();

        $phantom = new AgencyClientWorkspaceRelationship();
        $phantom->id = 99_999_999;
        $phantom->agency_workspace_id = $agency->id;

        $this->expectException(ModelNotFoundException::class);

        $this->manager()->terminate((int) $agency->owner_user_id, $phantom, 'Nothing to end.');
    }

    /**
     * WorkspaceManager::deactivateWorkspace()'s precedent: the duplicate
     * transition is an authorized no-op, and the original audit is never
     * overwritten by the second caller.
     */
    public function test_terminating_an_already_terminated_relationship_is_an_authorized_no_op(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
        $first = $this->manager()->terminate((int) $agencyOwner->id, $relationship, 'First and only reason.');

        $platform = $this->adminPanelUser(withPlatformPermission: true);
        $second = $this->manager()->terminate((int) $platform->id, $first, 'A different, later reason.');

        $this->assertSame('First and only reason.', $second->termination_reason);
        $this->assertSame((int) $agencyOwner->id, (int) $second->terminated_by_user_id);
        $this->assertEquals($first->terminated_at, $second->terminated_at);
    }

    public function test_an_unauthorized_actor_is_still_refused_on_an_already_terminated_relationship(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
        $terminated = $this->manager()->terminate((int) $agencyOwner->id, $relationship, 'Ended.');

        $this->assertRefusesToTerminate($this->outsiderHoldingThePermission(), $terminated);
    }

    // ------------------------------------------------------------------
    // History (Addendum §2 — never hard-deleted)
    // ------------------------------------------------------------------

    public function test_a_terminated_relationship_leaves_the_active_lookups_but_stays_queryable(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);
        $this->manager()->terminate((int) $agencyOwner->id, $relationship, 'Ended.');

        $this->assertNull($this->manager()->findActiveForClientWorkspace((int) $client->id));
        $this->assertCount(0, $this->manager()->findActiveForAgencyWorkspace((int) $agency->id));

        $history = $this->manager()->historyForClientWorkspace((int) $client->id);

        $this->assertCount(1, $history);
        $this->assertSame((int) $relationship->id, (int) $history->first()->id);
        $this->assertSame(AgencyClientRelationshipStatus::Terminated, $history->first()->status);
        $this->assertSame(1, DB::table('agency_client_workspace_relationships')->count());
    }

    public function test_history_distinguishes_a_previously_managed_client_from_one_never_managed(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$previouslyManaged] = $this->workspaceOn(null, 'Former Client');
        [$neverManaged] = $this->workspaceOn(null, 'Stranger');

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $previouslyManaged);
        $this->manager()->terminate((int) $agencyOwner->id, $relationship, 'Ended.');

        $this->assertCount(1, $this->manager()->historyForClientWorkspace((int) $previouslyManaged->id));
        $this->assertCount(0, $this->manager()->historyForClientWorkspace((int) $neverManaged->id));

        $this->assertNull($this->manager()->findActiveForClientWorkspace((int) $previouslyManaged->id));
        $this->assertNull($this->manager()->findActiveForClientWorkspace((int) $neverManaged->id));
    }

    public function test_after_termination_a_different_agency_may_manage_the_same_client(): void
    {
        [$firstAgency, $firstOwner] = $this->agency('First Agency');
        [$secondAgency, $secondOwner] = $this->agency('Second Agency');
        [$client] = $this->workspaceOn();

        $first = $this->manager()->create((int) $firstOwner->id, $firstAgency, $client);
        $this->manager()->terminate((int) $firstOwner->id, $first, 'Client changed agencies.');

        $second = $this->manager()->create((int) $secondOwner->id, $secondAgency, $client);

        $this->assertSame(AgencyClientRelationshipStatus::Active, $second->status);
        $this->assertSame((int) $secondAgency->id, (int) $this->manager()->findActiveForClientWorkspace((int) $client->id)?->agency_workspace_id);
        $this->assertCount(2, $this->manager()->historyForClientWorkspace((int) $client->id));
    }

    /**
     * Contract 01 §6's explicit rule: the row is the LINK, not proof of a
     * currently-true entitlement. A downgrade after the fact leaves it
     * standing, and this slice adds no downgrade-triggered termination.
     */
    public function test_an_active_relationship_survives_the_agencys_own_downgrade(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        [$client] = $this->workspaceOn();

        $relationship = $this->manager()->create((int) $agencyOwner->id, $agency, $client);

        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $agency->id)
            ->update([
                'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Core->value)->value('id'),
            ]);

        $stillActive = $this->manager()->findActiveForClientWorkspace((int) $client->id);

        $this->assertNotNull($stillActive);
        $this->assertSame((int) $relationship->id, (int) $stillActive->id);
        $this->assertSame(AgencyClientRelationshipStatus::Active, $stillActive->status);
    }

    // ------------------------------------------------------------------

    private function assertRefusesToCreate(User $actor, Workspace $agency, Workspace $client): void
    {
        $before = DB::table('agency_client_workspace_relationships')->count();

        try {
            $this->manager()->create((int) $actor->id, $agency, $client);
            $this->fail('User [' . $actor->id . '] must not be able to establish an Agency client relationship.');
        } catch (UnauthorizedAgencyRelationshipManagementException $e) {
            $this->assertSame((int) $actor->id, $e->actorUserId);
            $this->assertSame((int) $agency->id, $e->agencyWorkspaceId);
        }

        $this->assertSame($before, DB::table('agency_client_workspace_relationships')->count());
    }

    private function assertRefusesToCreateForMigration(User $actor, Workspace $agency, Workspace $client): void
    {
        $before = DB::table('agency_client_workspace_relationships')->count();

        try {
            $this->manager()->createForMigration((int) $actor->id, $agency, $client);
            $this->fail('User [' . $actor->id . '] must not be able to establish a relationship through the migration entry point.');
        } catch (UnauthorizedAgencyRelationshipManagementException $e) {
            $this->assertSame((int) $actor->id, $e->actorUserId);
            $this->assertSame((int) $agency->id, $e->agencyWorkspaceId);
        }

        $this->assertSame($before, DB::table('agency_client_workspace_relationships')->count());
    }

    /**
     * Contract 01 §7's lock order, asserted structurally for whichever entry
     * point $establish exercises: both Workspace rows locked, ascending by
     * id, before the locking duplicate read of the relationships table.
     */
    private function assertEstablishesUnderTheContractLockOrder(Closure $establish, Workspace $agency, Workspace $client): void
    {
        $locking = [];

        DB::listen(function ($query) use (&$locking): void {
            if (! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $locking[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        $establish();

        $this->assertGreaterThanOrEqual(3, count($locking), 'Expected two Workspace row locks and one locking relationship read.');

        $this->assertStringContainsString('from `workspaces`', $locking[0]['sql']);
        $this->assertStringContainsString('from `workspaces`', $locking[1]['sql']);
        $this->assertStringContainsString('from `agency_client_workspace_relationships`', $locking[2]['sql']);

        $lockedIds = [(int) $locking[0]['bindings'][0], (int) $locking[1]['bindings'][0]];

        $this->assertSame($lockedIds, collect($lockedIds)->sort()->values()->all(), 'The two Workspace rows must be locked in ascending id order.');
        $this->assertEqualsCanonicalizing([(int) $agency->id, (int) $client->id], $lockedIds);
    }

    private function assertRefusesToTerminate(User $actor, AgencyClientWorkspaceRelationship $relationship): void
    {
        $before = DB::table('agency_client_workspace_relationships')
            ->where('id', $relationship->id)
            ->first();

        try {
            $this->manager()->terminate((int) $actor->id, $relationship, 'Attempted termination.');
            $this->fail('User [' . $actor->id . '] must not be able to terminate an Agency client relationship.');
        } catch (UnauthorizedAgencyRelationshipManagementException $e) {
            $this->assertSame((int) $actor->id, $e->actorUserId);
            $this->assertSame((int) $relationship->agency_workspace_id, $e->agencyWorkspaceId);
        }

        $after = DB::table('agency_client_workspace_relationships')->where('id', $relationship->id)->first();

        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->terminated_by_user_id, $after->terminated_by_user_id);
        $this->assertSame($before->termination_reason, $after->termination_reason);
    }
}
