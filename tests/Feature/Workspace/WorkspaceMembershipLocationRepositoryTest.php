<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\CrossBusinessLocationAssignmentException;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Exceptions\Workspace\WorkspaceMembershipLocationAccessScopeBackfillIncompleteException;
use App\Models\BusinessLocation;
use App\Models\WorkspaceMembershipLocation;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Eloquent\EloquentWorkspaceMembershipLocationRepository;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\Feature\Workspace\Support\TemporaryTestDatabase;
use Tests\TestCase;

/**
 * Implementation Contract 02 (Location ACL Foundation) §13. Two halves:
 *
 *  - Ordinary repository CRUD (mirrors WorkspaceMembershipBusinessRepositoryTest's
 *    own shape exactly), plus the §5 transitional cross-check assign()/
 *    syncForMembership() must enforce, run against the normal shared
 *    testing database like any other feature test.
 *  - Migration/backfill/enforcement behavior, run against a disposable
 *    database via the existing TemporaryTestDatabase::withEnforcementDatabase()
 *    infrastructure — mirroring WorkspaceTransitionsMigrationSchemaTest's
 *    own proven technique exactly (default-connection swap for direct
 *    up()/down() invocation, restored via finally) — so no schema mutation
 *    ever touches the primary testing database, and no dedicated group/
 *    runner is needed.
 */
class WorkspaceMembershipLocationRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function location(\App\Models\Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Ordinary repository CRUD — mirrors WorkspaceMembershipBusinessRepositoryTest.
    // -----------------------------------------------------------------

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceMembershipLocationRepository::class,
            app(WorkspaceMembershipLocationRepository::class)
        );
    }

    public function test_assigned_location_ids_is_scoped_to_one_membership(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user);

        $locationA = $this->location($business);
        $locationB = $this->location($business);

        $repository->assign($membershipA, $locationA);
        $repository->assign($membershipB, $locationB);

        $ids = $repository->assignedLocationIds($membershipA);

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($locationA->id));
        $this->assertFalse($ids->contains($locationB->id));
    }

    public function test_is_assigned_is_scoped_to_one_membership(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user);

        $repository->assign($membershipA, $location);

        $this->assertTrue($repository->isAssigned($membershipA, $location->id));
        $this->assertFalse($repository->isAssigned($membershipB, $location->id));
    }

    public function test_assign_persists_a_same_workspace_grant(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $assignment = $repository->assign($membership, $location);

        $this->assertInstanceOf(WorkspaceMembershipLocation::class, $assignment);
        $this->assertTrue($repository->isAssigned($membership, $location->id));
    }

    public function test_duplicate_assign_is_idempotent(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $first = $repository->assign($membership, $location);
        $second = $repository->assign($membership, $location);

        $this->assertTrue($first->is($second));
        $this->assertSame(
            1,
            WorkspaceMembershipLocation::where('workspace_membership_id', $membership->id)
                ->where('business_location_id', $location->id)
                ->count()
        );
    }

    public function test_assign_throws_cross_workspace_assignment_exception(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);

        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);
        $otherBusiness = $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);
        $otherLocation = $this->location($otherBusiness);

        $this->expectException(CrossWorkspaceAssignmentException::class);
        $repository->assign($membership, $otherLocation);
    }

    /**
     * §5's transitional cross-check, at assign() (§13's own explicit
     * scenario): same Workspace, but the membership's business_access_scope
     * is Selected and it has no Business-level grant for this Location's
     * Business — a Location-level grant must never be wider than the
     * still-live Business-level grant.
     */
    public function test_assign_throws_cross_business_location_assignment_exception_when_business_is_unreachable(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);

        $this->expectException(CrossBusinessLocationAssignmentException::class);
        $repository->assign($membership, $location);
    }

    public function test_assign_succeeds_when_selected_scope_membership_is_business_level_granted(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $assignment = $repository->assign($membership, $location);

        $this->assertInstanceOf(WorkspaceMembershipLocation::class, $assignment);
    }

    public function test_sync_validates_every_id_before_any_write(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $validLocation = $this->location($business);
        $missingId = $validLocation->id + 999999;

        try {
            $repository->syncForMembership($membership, [$validLocation->id, $missingId]);
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException $e) {
            // expected
        }

        $this->assertFalse($repository->isAssigned($membership, $validLocation->id));
        $this->assertSame(0, $repository->assignedLocationIds($membership)->count());
    }

    public function test_sync_rejects_a_cross_workspace_location_before_changing_assignments(): void
    {
        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);

        $workspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($otherCustomer->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $existing = $this->location($business);
        $repository->assign($membership, $existing);
        $otherBusiness = $this->createBusinessForCustomer($otherCustomer->user_id, $otherWorkspace->id);
        $otherLocation = $this->location($otherBusiness);

        try {
            $repository->syncForMembership($membership, [$existing->id, $otherLocation->id]);
            $this->fail('Expected CrossWorkspaceAssignmentException was not thrown.');
        } catch (CrossWorkspaceAssignmentException $e) {
            // expected
        }

        $this->assertTrue($repository->isAssigned($membership, $existing->id));
        $this->assertSame(1, $repository->assignedLocationIds($membership)->count());
    }

    public function test_sync_rejects_a_business_unreachable_location_before_changing_assignments(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $reachableBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $unreachableBusiness = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $reachableBusiness);

        $existing = $this->location($reachableBusiness);
        $repository->assign($membership, $existing);
        $unreachableLocation = $this->location($unreachableBusiness);

        try {
            $repository->syncForMembership($membership, [$existing->id, $unreachableLocation->id]);
            $this->fail('Expected CrossBusinessLocationAssignmentException was not thrown.');
        } catch (CrossBusinessLocationAssignmentException $e) {
            // expected
        }

        $this->assertTrue($repository->isAssigned($membership, $existing->id));
        $this->assertSame(1, $repository->assignedLocationIds($membership)->count());
    }

    public function test_sync_replaces_grants_atomically_after_successful_validation(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $keep = $this->location($business);
        $drop = $this->location($business);
        $add = $this->location($business);

        $repository->assign($membership, $keep);
        $repository->assign($membership, $drop);

        $result = $repository->syncForMembership($membership, [$keep->id, $add->id]);

        $resultIds = collect($result)->pluck('business_location_id');
        $this->assertCount(2, $resultIds);
        $this->assertTrue($resultIds->contains($keep->id));
        $this->assertTrue($resultIds->contains($add->id));
        $this->assertFalse($resultIds->contains($drop->id));

        $this->assertTrue($repository->isAssigned($membership, $keep->id));
        $this->assertTrue($repository->isAssigned($membership, $add->id));
        $this->assertFalse($repository->isAssigned($membership, $drop->id));
    }

    public function test_sync_with_empty_array_removes_all_grants(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);
        $repository->assign($membership, $location);

        $result = $repository->syncForMembership($membership, []);

        $this->assertCount(0, $result);
        $this->assertSame(0, $repository->assignedLocationIds($membership)->count());
    }

    public function test_unassign_removes_only_the_supplied_memberships_grant(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);

        $membershipA = $this->createMembership($workspace, $this->createCustomer()->user);
        $membershipB = $this->createMembership($workspace, $this->createCustomer()->user);

        $repository->assign($membershipA, $location);
        $repository->assign($membershipB, $location);

        $repository->unassign($membershipA, $location->id);

        $this->assertFalse($repository->isAssigned($membershipA, $location->id));
        $this->assertTrue($repository->isAssigned($membershipB, $location->id));
    }

    public function test_unassign_of_a_missing_grant_is_a_safe_no_op(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user);

        $repository->unassign($membership, $location->id);

        $this->assertFalse($repository->isAssigned($membership, $location->id));
    }

    public function test_remove_all_for_business_in_workspace_removes_and_returns_source_workspace_grants(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $sourceWorkspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $sourceWorkspace->id);
        $location = $this->location($business);

        $membershipA = $this->createMembership($sourceWorkspace, $this->createCustomer()->user);
        $membershipB = $this->createMembership($sourceWorkspace, $this->createCustomer()->user);
        $repository->assign($membershipA, $location);
        $repository->assign($membershipB, $location);

        $removed = $repository->removeAllForBusinessInWorkspace($business->id, $sourceWorkspace->id);

        $this->assertCount(2, $removed);
        $removedMembershipIds = collect($removed)->pluck('workspace_membership_id')->sort()->values();
        $this->assertSame(
            collect([$membershipA->id, $membershipB->id])->sort()->values()->all(),
            $removedMembershipIds->all()
        );

        $this->assertFalse($repository->isAssigned($membershipA, $location->id));
        $this->assertFalse($repository->isAssigned($membershipB, $location->id));
    }

    public function test_remove_all_for_business_in_workspace_is_idempotent(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $sourceWorkspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $sourceWorkspace->id);
        $location = $this->location($business);
        $membership = $this->createMembership($sourceWorkspace, $this->createCustomer()->user);
        $repository->assign($membership, $location);

        $first = $repository->removeAllForBusinessInWorkspace($business->id, $sourceWorkspace->id);
        $second = $repository->removeAllForBusinessInWorkspace($business->id, $sourceWorkspace->id);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_remove_all_for_business_in_workspace_never_touches_another_workspaces_grants(): void
    {
        $owner = $this->createCustomer();
        $repository = app(WorkspaceMembershipLocationRepository::class);
        $sourceWorkspace = $this->createWorkspace($owner->user);
        $otherWorkspace = $this->createWorkspace($this->createCustomer()->user);

        $business = $this->createBusinessForCustomer($owner->user_id, $sourceWorkspace->id);
        $location = $this->location($business);
        $membershipInSource = $this->createMembership($sourceWorkspace, $this->createCustomer()->user);
        $repository->assign($membershipInSource, $location);

        $otherOwner = $this->createCustomer();
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $otherLocation = $this->location($otherBusiness);
        $membershipInOther = $this->createMembership($otherWorkspace, $this->createCustomer()->user);
        $repository->assign($membershipInOther, $otherLocation);

        $repository->removeAllForBusinessInWorkspace($business->id, $sourceWorkspace->id);

        $this->assertTrue($repository->isAssigned($membershipInOther, $otherLocation->id));
    }

    // -----------------------------------------------------------------
    // Reactivation preserves deterministic Location access (§13's own
    // explicit remediation requirement).
    // -----------------------------------------------------------------

    public function test_reactivation_preserves_location_access_scope_and_its_grant_unchanged(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $location = $this->location($business);

        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        $locationRepository = app(WorkspaceMembershipLocationRepository::class);
        $locationRepository->assign($membership, $location);

        $membershipRepository = app(WorkspaceMembershipRepository::class);
        $membershipRepository->setActive($membership, false);
        $membershipRepository->setActive($membership, true);

        $fresh = $membership->fresh();
        $this->assertSame(LocationAccessScope::Selected, $fresh->location_access_scope);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($locationRepository->isAssigned($fresh, $location->id));
        $this->assertSame(1, $locationRepository->assignedLocationIds($fresh)->count());
    }

    // -----------------------------------------------------------------
    // Migration / backfill / enforcement — disposable database, mirroring
    // WorkspaceTransitionsMigrationSchemaTest's own proven technique.
    // -----------------------------------------------------------------

    private const MIGRATION_A = '2026_09_20_100001_add_location_access_scope_to_workspace_memberships_table';

    private const MIGRATION_B = '2026_09_20_100002_backfill_workspace_membership_location_access_scope';

    private const MIGRATION_C = '2026_09_20_100003_enforce_workspace_membership_location_access_scope_not_null';

    private const MIGRATION_D = '2026_09_20_100004_create_workspace_membership_locations_table';

    private function migrationInstance(string $name): object
    {
        return require database_path('migrations/' . $name . '.php');
    }

    /**
     * Rolls back every migration from $migrationName onward (inclusive),
     * landing on the schema exactly as it was just before $migrationName
     * originally ran — the step count is computed from the migrations
     * table itself, never hard-coded, mirroring
     * VerifiesEnforcementWorkspaceDatabase::rollbackToPostMigrationFive()'s
     * own technique exactly.
     */
    private function rollbackFromInclusive(string $connectionName, string $migrationName): void
    {
        $row = DB::connection($connectionName)->table('migrations')->where('migration', $migrationName)->first();

        if ($row === null) {
            throw new \RuntimeException("Refusing to roll back: migration [{$migrationName}] is not applied.");
        }

        $stepCount = DB::connection($connectionName)->table('migrations')->where('id', '>=', $row->id)->count();

        Artisan::call('migrate:rollback', ['--database' => $connectionName, '--step' => $stepCount, '--force' => true]);
    }

    /**
     * Mirrors WorkspaceTransitionsMigrationSchemaTest::withDefaultConnection()
     * exactly: Schema:: is uncached, so it always resolves against the
     * current default connection at call time — swapping it for the
     * deliberately-scoped duration of a direct migration up()/down() call
     * is how Migrator::usingConnection() itself makes --database work for
     * the ordinary Artisan commands used elsewhere in this test.
     */
    private function withDefaultConnection(string $connectionName, Closure $callback): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connectionName);

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function columnIsNullable(string $connectionName, string $databaseName): bool
    {
        $column = DB::connection($connectionName)->selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$databaseName, 'workspace_memberships', 'location_access_scope']
        );

        return $column !== null && $column->IS_NULLABLE === 'YES';
    }

    /** A minimal, real Workspace + User pair to satisfy workspace_memberships' own FKs. */
    private function insertRawMembership(string $connectionName, array $overrides = []): int
    {
        $userId = DB::connection($connectionName)->table('users')->insertGetId(array_merge([
            'first_name' => 'Fixture',
            'last_name' => 'User',
            'email' => 'fixture-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $workspaceId = DB::connection($connectionName)->table('workspaces')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Fixture Workspace',
            'owner_user_id' => $userId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $memberUserId = DB::connection($connectionName)->table('users')->insertGetId([
            'first_name' => 'Fixture',
            'last_name' => 'Member',
            'email' => 'fixture-member-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection($connectionName)->table('workspace_memberships')->insertGetId(array_merge([
            'workspace_id' => $workspaceId,
            'user_id' => $memberUserId,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'location_access_scope' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_full_migration_chain_applies_cleanly_and_backfill_covers_active_and_inactive_rows(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            // Roll back to right before the backfill migration (post
            // Migration A: column exists, nullable, no pivot table yet).
            $this->rollbackFromInclusive($connectionName, self::MIGRATION_B);
            $this->assertTrue($this->columnIsNullable($connectionName, $databaseName));

            $activeId = $this->insertRawMembership($connectionName, ['is_active' => true]);
            $inactiveId = $this->insertRawMembership($connectionName, ['is_active' => false]);

            $this->withDefaultConnection($connectionName, function () {
                $this->migrationInstance(self::MIGRATION_B)->up();
            });

            $activeScope = DB::connection($connectionName)->table('workspace_memberships')->where('id', $activeId)->value('location_access_scope');
            $inactiveScope = DB::connection($connectionName)->table('workspace_memberships')->where('id', $inactiveId)->value('location_access_scope');

            $this->assertSame('all', $activeScope, 'Active row must be backfilled to all.');
            $this->assertSame('all', $inactiveScope, 'Inactive row must ALSO be backfilled to all — no is_active filter.');

            $remainingNull = DB::connection($connectionName)->table('workspace_memberships')->whereNull('location_access_scope')->count();
            $this->assertSame(0, $remainingNull, 'Zero NULL rows must remain across active and inactive alike.');

            // Continue forward to head: migration B re-runs (idempotent —
            // both rows are already 'all') then C and D apply cleanly now
            // that zero NULL rows remain.
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            $this->assertFalse($this->columnIsNullable($connectionName, $databaseName), 'location_access_scope must be NOT NULL at head.');
            $this->assertTrue(
                DB::connection($connectionName)->table('information_schema.TABLES')
                    ->where('TABLE_SCHEMA', $databaseName)->where('TABLE_NAME', 'workspace_membership_locations')->exists()
            );
        });
    }

    public function test_enforce_not_null_migration_throws_and_applies_no_ddl_when_an_inactive_row_is_still_null(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            // Roll back to right before the enforce-NOT-NULL migration
            // (post Migration B: backfill has run, but we will now
            // reintroduce exactly one NULL row to prove the precondition
            // — an inactive row, deliberately, since that is the bug this
            // contract's own correction closed).
            $this->rollbackFromInclusive($connectionName, self::MIGRATION_C);
            $this->assertTrue($this->columnIsNullable($connectionName, $databaseName));

            $nullInactiveId = $this->insertRawMembership($connectionName, ['is_active' => false, 'location_access_scope' => null]);

            try {
                $this->withDefaultConnection($connectionName, function () {
                    $this->migrationInstance(self::MIGRATION_C)->up();
                });
                $this->fail('Expected WorkspaceMembershipLocationAccessScopeBackfillIncompleteException was not thrown.');
            } catch (WorkspaceMembershipLocationAccessScopeBackfillIncompleteException $e) {
                $this->assertSame(1, $e->remainingNullCount);
            }

            $this->assertTrue($this->columnIsNullable($connectionName, $databaseName), 'No DDL may be applied before the precondition check fails.');
            $this->assertNull(
                DB::connection($connectionName)->table('workspace_memberships')->where('id', $nullInactiveId)->value('location_access_scope')
            );
        });
    }

    public function test_enforce_not_null_migration_down_restores_nullable(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);
            $this->assertFalse($this->columnIsNullable($connectionName, $databaseName));

            $this->withDefaultConnection($connectionName, function () {
                $this->migrationInstance(self::MIGRATION_C)->down();
            });

            $this->assertTrue($this->columnIsNullable($connectionName, $databaseName));
        });
    }

    public function test_migration_order_a_through_d_is_ascending_in_the_migrations_table(): void
    {
        TemporaryTestDatabase::withEnforcementDatabase(function (string $databaseName, string $connectionName) {
            Artisan::call('migrate', ['--database' => $connectionName, '--force' => true]);

            $rows = DB::connection($connectionName)->table('migrations')
                ->whereIn('migration', [self::MIGRATION_A, self::MIGRATION_B, self::MIGRATION_C, self::MIGRATION_D])
                ->orderBy('id')
                ->pluck('migration')
                ->all();

            $this->assertSame([self::MIGRATION_A, self::MIGRATION_B, self::MIGRATION_C, self::MIGRATION_D], $rows);
        });
    }
}
