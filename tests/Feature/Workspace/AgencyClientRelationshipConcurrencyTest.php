<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 01 §7/§13 — real concurrency for
 * AgencyClientRelationshipManager::create(). Three proofs, none of them
 * mocking a lock:
 *
 *   A. the Client Workspace row lock genuinely serializes creation;
 *   B. the duplicate check is a LOCKING read, so it sees a relationship
 *      another connection committed while this transaction was waiting —
 *      which an ordinary REPEATABLE READ snapshot would not;
 *   C. two independent OS processes racing for the same Client Workspace
 *      from two different Agencies leave exactly one Active relationship,
 *      the loser refused by the domain rule rather than by a raw
 *      unique-index violation;
 *   D. the same race run through BOTH entry points at once — a product
 *      create() against an operator's createForMigration() — obeys the same
 *      single uniqueness rule, and records the real actor who won.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process or
 * connection needs committed rows, which an open RefreshDatabase transaction
 * would hide entirely (the same rationale WorkspaceManagerConcurrencyTest and
 * WorkspaceBackfillV1ConcurrencyTest already record). Fixture rows are
 * inserted directly and explicitly removed in tearDown().
 */
class AgencyClientRelationshipConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_agency_client_relationship_lock_probe';

    private const LOCK_WAIT_TIMEOUT_SECONDS = 1;

    /**
     * create() runs inside DB::transaction(..., 3), and a MySQL lock-wait
     * timeout is one of the concurrency errors Laravel retries, so a blocked
     * attempt burns up to three lock-wait windows before it surfaces. The
     * ceiling is generous enough for all three and still far below "it hung".
     */
    private const MAX_ELAPSED_SECONDS = 12.0;

    private const MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE = 1205;

    private const ALREADY_MANAGED_EXIT_CODE = 4;

    /** How long the contested row stays locked after both children are inside create(). */
    private const HELD_AFTER_BOTH_ENTERED_MICROSECONDS = 1_500_000;

    /** Allows for clock granularity; still an order of magnitude above an unblocked create(). */
    private const MINIMUM_BLOCKED_MILLISECONDS = 1_000;

    private array $createdUserIds = [];

    private array $createdWorkspaceIds = [];

    private array $createdRoleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every write below is committed for real, so the disposable-database
        // guard runs before the first one rather than after.
        $this->assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();

        parent::tearDown();
    }

    private function deleteFixtures(): void
    {
        if ($this->createdWorkspaceIds !== []) {
            DB::table('agency_client_workspace_relationships')
                ->whereIn('agency_workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('client_workspace_id', $this->createdWorkspaceIds)
                ->delete();

            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();

            $this->createdWorkspaceIds = [];
        }

        if ($this->createdRoleIds !== []) {
            DB::table('role_user')->whereIn('role_id', $this->createdRoleIds)->delete();
            DB::table('permissions')->whereIn('role_id', $this->createdRoleIds)->delete();
            DB::table('roles')->whereIn('id', $this->createdRoleIds)->delete();

            $this->createdRoleIds = [];
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();

            $this->createdUserIds = [];
        }
    }

    /**
     * Forwarded explicitly to every spawned runner process, mirroring the
     * proven pattern in WorkspaceManagerConcurrencyTest — the child must
     * resolve the very same validated disposable database this parent process
     * is running against, never a hardcoded literal.
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function insertOwner(string $label): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Relationship',
            'last_name' => $label,
            'email' => 'agency-client-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    private function insertWorkspace(int $ownerUserId, string $name): int
    {
        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => $name,
            'owner_user_id' => $ownerUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdWorkspaceIds[] = $workspaceId;

        return $workspaceId;
    }

    /**
     * The plan assignment is written directly rather than through
     * EntitlementManager::assignFirstPlan(): this suite commits every row for
     * real, and a narrower fixture is a narrower cleanup.
     *
     * @return array{0: int, 1: int} the Agency Workspace id and its owner's user id
     */
    private function agencyWorkspace(string $name): array
    {
        $ownerUserId = $this->insertOwner('Agency');
        $workspaceId = $this->insertWorkspace($ownerUserId, $name);

        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId,
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')
                ->where('tier', WorkspacePlanTier::Agency->value)
                ->value('id'),
            'status' => 'active',
            'is_complimentary' => false,
            'additional_business_slots' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$workspaceId, $ownerUserId];
    }

    private function clientWorkspace(string $name): int
    {
        return $this->insertWorkspace($this->insertOwner('Client'), $name);
    }

    /**
     * An admin-panel account holding the dedicated platform relationship Role
     * permission — the only actor createForMigration() accepts — written
     * through the same roles / permissions / role_user tables the admin RBAC
     * reads.
     */
    private function platformOperator(): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Migration',
            'last_name' => 'Operator',
            'email' => 'migration-operator-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $userId;

        $roleId = DB::table('roles')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'relationship-operator-' . uniqid('', true),
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdRoleIds[] = $roleId;

        DB::table('permissions')->insert([
            'uid' => (string) Str::uuid(),
            'role_id' => $roleId,
            'name' => AgencyClientRelationshipManager::PLATFORM_RELATIONSHIP_PERMISSION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);

        return $userId;
    }

    private function activeRelationshipCountFor(int $clientWorkspaceId): int
    {
        return DB::table('agency_client_workspace_relationships')
            ->where('client_workspace_id', $clientWorkspaceId)
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->count();
    }

    // ------------------------------------------------------------------
    // A. The Client Workspace row lock really serializes creation.
    // ------------------------------------------------------------------

    public function test_the_client_workspace_row_lock_serializes_relationship_creation(): void
    {
        [$agencyWorkspaceId, $agencyOwnerUserId] = $this->agencyWorkspace('Lock Probe Agency');
        $clientWorkspaceId = $this->clientWorkspace('Lock Probe Client');

        $probe = $this->setUpProbeConnection();

        try {
            $this->assertConnectionsAreGenuinelyDistinct($probe);

            $probe->beginTransaction();

            try {
                // A genuine row lock on the CLIENT Workspace row, held open
                // on the probe connection — exactly the lock another Agency's
                // own create() would be holding mid-transaction.
                $probe->select('SELECT * FROM workspaces WHERE id = ? FOR UPDATE', [$clientWorkspaceId]);

                $caught = null;
                $start = microtime(true);

                $this->withShortLockWaitTimeout(function () use ($agencyWorkspaceId, $clientWorkspaceId, $agencyOwnerUserId, &$caught) {
                    try {
                        app(AgencyClientRelationshipManager::class)->create(
                            $agencyOwnerUserId,
                            Workspace::query()->find($agencyWorkspaceId),
                            Workspace::query()->find($clientWorkspaceId),
                        );
                    } catch (QueryException $e) {
                        $caught = $e;
                    }
                });

                $elapsed = microtime(true) - $start;

                $this->assertLessThan(
                    self::MAX_ELAPSED_SECONDS,
                    $elapsed,
                    'create() did not fail within the bounded lock-wait window — it may have hung.'
                );
                $this->assertNotNull(
                    $caught,
                    'Expected create() to fail with a MySQL lock-wait-timeout QueryException while the Client Workspace row was locked by another connection.'
                );
                $this->assertSame(self::MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE, $caught->errorInfo[1] ?? null);

                $this->assertSame(0, $this->activeRelationshipCountFor($clientWorkspaceId));
            } finally {
                $probe->rollBack();
            }
        } finally {
            $this->tearDownProbeConnection();
        }
    }

    // ------------------------------------------------------------------
    // B. The duplicate check is a locking read, not a snapshot read.
    // ------------------------------------------------------------------

    /**
     * The exact hazard Contract 01 §7 guards against: under MySQL/InnoDB
     * REPEATABLE READ, a transaction that read anything before waiting for
     * its Workspace locks keeps that older snapshot, so an ordinary query
     * cannot see the winner's freshly committed relationship. Only a locking
     * read sees the current row — which is why create() uses one, and why a
     * duplicate is refused by the domain rule instead of falling through to
     * the unique index.
     */
    public function test_the_duplicate_check_sees_a_relationship_committed_after_this_transaction_began(): void
    {
        [$winnerWorkspaceId, $winnerOwnerUserId] = $this->agencyWorkspace('Snapshot Winner Agency');
        $clientWorkspaceId = $this->clientWorkspace('Snapshot Client');

        $probe = $this->setUpProbeConnection();

        try {
            $this->assertConnectionsAreGenuinelyDistinct($probe);

            $repository = app(AgencyClientWorkspaceRelationshipRepository::class);

            DB::beginTransaction();

            try {
                // Establishes this transaction's consistent snapshot, before
                // the other connection writes anything.
                $this->assertSame(0, DB::table('agency_client_workspace_relationships')
                    ->where('client_workspace_id', $clientWorkspaceId)
                    ->count());

                // The other Agency wins the race and commits.
                $probe->insert(
                    'INSERT INTO agency_client_workspace_relationships'
                    . ' (uid, agency_workspace_id, client_workspace_id, status, established_by_user_id, established_at, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
                    [
                        (string) Str::uuid(),
                        $winnerWorkspaceId,
                        $clientWorkspaceId,
                        AgencyClientRelationshipStatus::Active->value,
                        $winnerOwnerUserId,
                    ],
                );

                $this->assertNull(
                    $repository->findActiveForClientWorkspace($clientWorkspaceId),
                    'The snapshot read is expected to miss the other connection\'s committed row — that is the hazard this guards against.'
                );

                $seen = $repository->findActiveForClientWorkspaceForUpdate($clientWorkspaceId);

                $this->assertNotNull($seen, 'The locking read must see the relationship committed by the other connection.');
                $this->assertSame($winnerWorkspaceId, (int) $seen->agency_workspace_id);
            } finally {
                DB::rollBack();
            }
        } finally {
            $this->tearDownProbeConnection();
        }
    }

    // ------------------------------------------------------------------
    // C. Two real processes, one Client Workspace, exactly one winner.
    // ------------------------------------------------------------------

    public function test_two_racing_agencies_leave_exactly_one_active_relationship(): void
    {
        [$firstAgencyId, $firstOwnerId] = $this->agencyWorkspace('Racing Agency One');
        [$secondAgencyId, $secondOwnerId] = $this->agencyWorkspace('Racing Agency Two');
        $clientWorkspaceId = $this->clientWorkspace('Contested Client');

        $this->assertExactlyOneWinnerRacingFor(
            $clientWorkspaceId,
            ['agency' => $firstAgencyId, 'actor' => $firstOwnerId, 'entry' => 'create'],
            ['agency' => $secondAgencyId, 'actor' => $secondOwnerId, 'entry' => 'create'],
        );
    }

    /**
     * D. The two entry points share one uniqueness rule under real
     * contention, not only sequentially: an Agency owner's product create()
     * and a migration operator's createForMigration() racing for the same
     * Client Workspace still leave exactly one Active relationship, the loser
     * refused cleanly, and the winner's row recording whichever real actor
     * won.
     */
    public function test_a_product_create_racing_a_migration_create_leaves_exactly_one_active_relationship(): void
    {
        [$productAgencyId, $productOwnerId] = $this->agencyWorkspace('Racing Product Agency');
        [$migratedAgencyId] = $this->agencyWorkspace('Racing Migrated Agency');
        $operatorUserId = $this->platformOperator();
        $clientWorkspaceId = $this->clientWorkspace('Contested Migration Client');

        $this->assertExactlyOneWinnerRacingFor(
            $clientWorkspaceId,
            ['agency' => $productAgencyId, 'actor' => $productOwnerId, 'entry' => 'create'],
            ['agency' => $migratedAgencyId, 'actor' => $operatorUserId, 'entry' => 'migration'],
        );
    }

    /**
     * Races two child processes for $clientWorkspaceId behind a row lock the
     * parent holds, releases them together, and asserts the contracted
     * outcome.
     *
     * @param  array{agency: int, actor: int, entry: string}  $firstRacer
     * @param  array{agency: int, actor: int, entry: string}  $secondRacer
     */
    private function assertExactlyOneWinnerRacingFor(int $clientWorkspaceId, array $firstRacer, array $secondRacer): void
    {
        $runnerScript = __DIR__ . '/Support/concurrent_agency_client_relationship_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $probe = $this->setUpProbeConnection();
        $probe->beginTransaction();

        $first = new Process(
            [$phpBinary, $runnerScript, (string) $firstRacer['agency'], (string) $clientWorkspaceId, (string) $firstRacer['actor'], $firstRacer['entry']],
            null,
            $this->childEnvironment(),
        );
        $second = new Process(
            [$phpBinary, $runnerScript, (string) $secondRacer['agency'], (string) $clientWorkspaceId, (string) $secondRacer['actor'], $secondRacer['entry']],
            null,
            $this->childEnvironment(),
        );

        try {
            // Holding the contested Client Workspace row makes the race
            // deterministic: both children must queue behind this one lock,
            // whatever order they boot in.
            $probe->select('SELECT * FROM workspaces WHERE id = ? FOR UPDATE', [$clientWorkspaceId]);

            $first->start();
            $second->start();

            $bothEnteredCreate = $this->waitForBothChildrenToEnterCreate($first, $second);

            // Held for a further, known interval after both children are
            // inside create(): whatever else happens, neither can finish
            // before this elapses, and each child reports how long its own
            // create() took — so the overlap is measured, not assumed.
            usleep(self::HELD_AFTER_BOTH_ENTERED_MICROSECONDS);
        } finally {
            // Released together, so the two transactions resume genuinely
            // concurrently rather than one after the other.
            $probe->rollBack();
            $this->tearDownProbeConnection();
        }

        $first->wait();
        $second->wait();

        $this->assertTrue(
            $bothEnteredCreate,
            'Both child processes were expected to reach create() while the contested Client Workspace row was still locked; the race never actually overlapped.'
        );

        $exitCodes = [$first->getExitCode(), $second->getExitCode()];
        $output = 'first: ' . $first->getOutput() . $first->getErrorOutput()
            . ' second: ' . $second->getOutput() . $second->getErrorOutput();

        sort($exitCodes);

        $this->assertSame(
            [0, self::ALREADY_MANAGED_EXIT_CODE],
            $exitCodes,
            'Exactly one process must establish the relationship and exactly one must be refused cleanly. ' . $output
        );

        foreach ($this->reportedCreateDurations($first, $second) as $elapsedMilliseconds) {
            $this->assertGreaterThanOrEqual(
                self::MINIMUM_BLOCKED_MILLISECONDS,
                $elapsedMilliseconds,
                'Each create() must have genuinely waited on the contested Client Workspace row lock, not merely run after it was released. ' . $output
            );
        }

        $this->assertSame(1, $this->activeRelationshipCountFor($clientWorkspaceId));
        $this->assertSame(
            1,
            DB::table('agency_client_workspace_relationships')->where('client_workspace_id', $clientWorkspaceId)->count(),
            'The loser must not have written a row of any status.'
        );

        $winner = $first->getExitCode() === 0 ? $first : $second;
        $loser = $first->getExitCode() === 0 ? $second : $first;

        preg_match('/agency_workspace_id=(\d+)/', $winner->getOutput(), $winnerMatch);
        preg_match('/existing_agency_workspace_id=(\d+)/', $loser->getOutput(), $loserMatch);

        $this->assertNotEmpty($winnerMatch, 'The winning process did not report the Agency it linked: ' . $winner->getOutput());
        $this->assertNotEmpty($loserMatch, 'The refused process did not name the Agency that beat it: ' . $loser->getOutput());
        $this->assertSame($winnerMatch[1], $loserMatch[1], 'The refused process must name the winner as the existing managing Agency.');

        $row = DB::table('agency_client_workspace_relationships')
            ->where('client_workspace_id', $clientWorkspaceId)
            ->first();

        $this->assertSame((int) $winnerMatch[1], (int) $row->agency_workspace_id);

        // The row records the REAL actor of whichever racer won — the Agency
        // owner for a product create, the operator for a migration create.
        $winningRacer = (int) $winnerMatch[1] === $firstRacer['agency'] ? $firstRacer : $secondRacer;

        preg_match('/established_by_user_id=(\d+)/', $winner->getOutput(), $actorMatch);

        $this->assertNotEmpty($actorMatch, 'The winning process did not report who established the relationship: ' . $winner->getOutput());
        $this->assertSame($winningRacer['actor'], (int) $actorMatch[1]);
        $this->assertSame($winningRacer['actor'], (int) $row->established_by_user_id);
    }

    /**
     * Waits until both children announce that they have entered create().
     *
     * InnoDB's own lock bookkeeping (performance_schema.data_locks) would be
     * the most direct signal, but reading it needs a privilege the disposable
     * test database user deliberately does not have. The children's own
     * announcement plus their reported create() durations prove the same
     * thing without asking for one: they cannot report a duration longer than
     * the parent's remaining hold unless they genuinely waited for the lock.
     */
    private function waitForBothChildrenToEnterCreate(Process $first, Process $second): bool
    {
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            $entered = str_contains($first->getOutput(), 'WAITING')
                && str_contains($second->getOutput(), 'WAITING');

            if ($entered) {
                return true;
            }

            if (! $first->isRunning() && ! $second->isRunning()) {
                return false;
            }

            usleep(100_000);
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int} each child's own create() duration, in milliseconds
     */
    private function reportedCreateDurations(Process $first, Process $second): array
    {
        return [$this->reportedCreateDuration($first), $this->reportedCreateDuration($second)];
    }

    private function reportedCreateDuration(Process $process): int
    {
        preg_match('/elapsed_ms=(\d+)/', $process->getOutput(), $match);

        $this->assertNotEmpty($match, 'A child process did not report how long its create() took: ' . $process->getOutput() . $process->getErrorOutput());

        return (int) $match[1];
    }

    private function setUpProbeConnection(): Connection
    {
        config(['database.connections.' . self::PROBE_CONNECTION => config('database.connections.mysql')]);
        DB::purge(self::PROBE_CONNECTION);

        return DB::connection(self::PROBE_CONNECTION);
    }

    private function tearDownProbeConnection(): void
    {
        DB::purge(self::PROBE_CONNECTION);
        config(['database.connections.' . self::PROBE_CONNECTION => null]);
    }

    private function assertConnectionsAreGenuinelyDistinct(Connection $probe): void
    {
        $default = DB::connection();

        $this->assertSame('mysql', $default->getDriverName());
        $this->assertSame('mysql', $probe->getDriverName());
        $this->assertSame($default->getDatabaseName(), $probe->getDatabaseName());
        $this->assertNotSame($default->getPdo(), $probe->getPdo());
    }

    /**
     * Runs $callback with the default connection's session
     * innodb_lock_wait_timeout temporarily lowered, so a genuine lock-wait
     * fails deterministically instead of using the (much longer) server
     * default — restoring the original value afterward regardless of outcome.
     */
    private function withShortLockWaitTimeout(callable $callback): void
    {
        $original = DB::connection()->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;

        DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT_SECONDS);

        try {
            $callback();
        } finally {
            DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = ' . (int) $original);
        }
    }
}
