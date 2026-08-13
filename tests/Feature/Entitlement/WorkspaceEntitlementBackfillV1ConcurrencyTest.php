<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves WorkspaceEntitlementBackfillV1's concurrency safety (RFC-004
 * §25.2) at the database level: two independent, live connections to the
 * same test database racing an insert for the same Workspace's
 * workspace_plan_assignments row must never both succeed.
 *
 * Deliberately does NOT use RefreshDatabase — its per-test wrapping
 * transaction on the default connection would hide an uncommitted insert
 * from a genuinely separate second connection, defeating the point of this
 * test (mirroring WorkspaceBackfillV1ConcurrencyTest's identical rationale,
 * RFC-003). Fixture rows are inserted directly (auto-committed) and
 * explicitly removed in tearDown().
 *
 * Uses two real, separately opened database connections from within this
 * one PHPUnit process rather than spawning OS subprocesses — MySQL's own
 * InnoDB row-locking on the unique workspace_id constraint is the thing
 * under test, not PHP-level parallelism, so this proves genuine contention
 * (connection B must either block until timeout or observably wait) without
 * cross-process bootstrap machinery.
 */
class WorkspaceEntitlementBackfillV1ConcurrencyTest extends TestCase
{
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        config(['database.connections.entitlement_concurrency_secondary' => config("database.connections.{$default}")]);
    }

    protected function tearDown(): void
    {
        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    private function insertWorkspace(): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Concurrency',
            'last_name' => 'Test',
            'email' => 'entitlement-concurrency-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $userId;

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'Entitlement Concurrency Workspace',
            'owner_user_id' => $userId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdWorkspaceIds[] = $workspaceId;

        return $workspaceId;
    }

    private function coreCatalogId(): int
    {
        return (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');
    }

    public function test_two_connections_racing_the_same_workspace_create_exactly_one_assignment_row(): void
    {
        $workspaceId = $this->insertWorkspace();
        $coreCatalogId = $this->coreCatalogId();
        $now = now();

        $connectionA = DB::connection();
        $connectionB = DB::connection('entitlement_concurrency_secondary');

        // A short lock-wait timeout on B's session so a genuine block is
        // observable within this test rather than hanging indefinitely.
        $connectionB->statement('SET SESSION innodb_lock_wait_timeout = 2');

        // Plain insert() on both connections — matching
        // WorkspaceEntitlementBackfillV1's own corrected mechanism exactly
        // (no insertOrIgnore()/INSERT IGNORE anywhere).
        $connectionA->beginTransaction();
        $connectionA->table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId,
            'workspace_plan_catalog_id' => $coreCatalogId,
            'status' => 'active',
            'is_complimentary' => true,
            'additional_business_slots' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $start = microtime(true);
        $blocked = false;

        try {
            $connectionB->table('workspace_plan_assignments')->insert([
                'workspace_id' => $workspaceId,
                'workspace_plan_catalog_id' => $coreCatalogId,
                'status' => 'active',
                'is_complimentary' => true,
                'additional_business_slots' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
            // A lock-wait-timeout while A's row is still uncommitted is
            // itself proof of genuine contention — B never even reaches the
            // point of discovering a duplicate key, since A's row is not
            // yet visible/committed.
            $blocked = true;
        }

        $elapsed = microtime(true) - $start;

        $connectionA->commit();

        $this->assertTrue(
            $blocked || $elapsed >= 1.0,
            "Expected connection B to genuinely block on connection A's uncommitted row lock."
        );

        $this->assertSame(
            1,
            DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceId)->count(),
            'Expected exactly one workspace_plan_assignments row after two racing connections.'
        );
    }

    // The real WorkspaceEntitlementBackfillV1::run() method, invoked
    // repeatedly back-to-back with no artificial delay, still never
    // produces more than one assignment or transition row per Workspace —
    // the algorithm's own narrow duplicate-workspace_id catch (verified
    // driver error code 1062 against this table's exact unique-constraint
    // name), not test timing, is what guarantees this.
    public function test_repeated_immediate_runs_never_produce_more_than_one_assignment_or_transition(): void
    {
        $workspaceId = $this->insertWorkspace();

        for ($i = 0; $i < 3; $i++) {
            (new WorkspaceEntitlementBackfillV1())->run();
        }

        $this->assertSame(
            1,
            DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceId)->count()
        );
        $this->assertSame(
            1,
            DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceId)
                ->where('transition_type', WorkspaceEntitlementTransitionType::PlanAssigned->value)
                ->count()
        );
    }

    // Bounded proof that the backfill's narrow duplicate-key catch does not
    // broadly swallow unrelated database failures: processWorkspace() is
    // invoked directly (via reflection, since it is protected) with a
    // workspace_id that references no real workspaces row, deterministically
    // triggering a foreign-key violation (MySQL driver error 1452) rather
    // than the narrow duplicate-workspace_id race (error 1062) the class is
    // permitted to absorb. The exception must propagate, and no assignment
    // row may be left behind for the non-existent Workspace.
    public function test_an_unrelated_database_failure_is_not_silently_absorbed(): void
    {
        $coreCatalogId = $this->coreCatalogId();
        $nonExistentWorkspaceId = 999999999;

        $backfill = new WorkspaceEntitlementBackfillV1();
        $method = new ReflectionMethod(WorkspaceEntitlementBackfillV1::class, 'processWorkspace');
        $method->setAccessible(true);

        try {
            $method->invoke($backfill, $nonExistentWorkspaceId, $coreCatalogId);
            $this->fail('Expected a QueryException for a foreign-key violation; none was thrown.');
        } catch (QueryException $e) {
            $this->assertSame(
                1452,
                (int) ($e->errorInfo[1] ?? 0),
                'Expected MySQL foreign-key-violation error code 1452, not the narrow 1062 duplicate race.'
            );
        }

        $this->assertSame(
            0,
            DB::table('workspace_plan_assignments')->where('workspace_id', $nonExistentWorkspaceId)->count()
        );
    }
}
