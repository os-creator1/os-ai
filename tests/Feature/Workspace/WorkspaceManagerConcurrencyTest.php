<?php

namespace Tests\Feature\Workspace;

use App\Library\Workspace\WorkspaceManager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Real concurrency coverage for WorkspaceManager::resolveLegacyOnboardingWorkspace()
 * (RFC-003 §13.1, §18): two independent proofs, neither using a mocked
 * lockForUpdate().
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process
 * or connection needs committed rows, which an open RefreshDatabase
 * transaction would hide entirely (same rationale as
 * WorkspaceBackfillV1ConcurrencyTest). Fixture rows are inserted directly
 * (auto-committed) and explicitly removed in tearDown()/finally blocks.
 */
class WorkspaceManagerConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_workspace_manager_lock_probe';

    private const LOCK_WAIT_TIMEOUT_SECONDS = 2;

    /**
     * Failsafe only. The barrier is the holder's own "LOCKED" line; this
     * bound exists so a wedged child cannot hang the suite, and is set
     * well above the worst observed child boot time under whole-suite
     * load rather than being tuned to make a race pass.
     */
    private const LOCK_SIGNAL_TIMEOUT_SECONDS = 60;

    private const MAX_ELAPSED_SECONDS = 8.0;

    private const MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE = 1205;

    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $this->deleteWorkspacesAndM2EntitlementChildrenForOwners($this->createdUserIds);
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Under RFC-004 M2, a brand-new Workspace provisioned by
     * resolveLegacyOnboardingWorkspace()'s zero-candidate auto-provisioning
     * path now legitimately receives a narrow complimentary Core
     * workspace_plan_assignments row and its plan_assigned
     * workspace_entitlement_transitions row — both restrictOnDelete()
     * against workspaces.id, so they must be cleared before the Workspace
     * row itself can be deleted. Scoped strictly to Workspaces owned by the
     * given owner IDs; never a global truncate.
     */
    private function deleteWorkspacesAndM2EntitlementChildrenForOwners(array $ownerUserIds): void
    {
        if ($ownerUserIds === []) {
            return;
        }

        $workspaceIds = DB::table('workspaces')->whereIn('owner_user_id', $ownerUserIds)->pluck('id');

        if ($workspaceIds->isNotEmpty()) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $workspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $workspaceIds)->delete();
        }

        DB::table('workspaces')->whereIn('owner_user_id', $ownerUserIds)->delete();
    }

    // A. Real two-process outcome test.
    public function test_two_concurrent_resolver_attempts_for_the_same_owner_create_exactly_one_workspace(): void
    {
        // A disposable test database — the canonical one or a
        // clearly-derived isolated sibling. TestDatabaseSafety throws,
        // naming the offending value, for anything else.
        $activeDatabase = TestDatabaseSafety::activeTestDatabase();
        $this->assertSame(DB::connection()->getDatabaseName(), $activeDatabase);

        $ownerUserId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Concurrency',
            'last_name' => 'Resolver',
            'email' => 'resolver-concurrency-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $ownerUserId;

        $runnerScript = __DIR__ . '/Support/concurrent_workspace_resolver_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $holdSeconds = '2';

        $childEnv = ['EXPECTED_TEST_DATABASE' => $activeDatabase];

        $slow = new Process([$phpBinary, $runnerScript, 'slow', $holdSeconds, (string) $ownerUserId], null, $childEnv);
        $slow->start();

        // Wait for the holder's OWN "LOCKED" signal, not for a guessed
        // duration. SlowWorkspaceManager prints it immediately after
        // lockOwnerRow() returns and before it starts holding, so this
        // returns only once the users-row lock is genuinely held and the
        // race window below is guaranteed to be reached.
        //
        // The previous usleep(500_000) was a guess: under whole-suite load
        // a child needs longer than that just to boot Laravel, so the
        // "racing" process could start after the holder had already
        // finished — and the test then passed or failed on scheduler luck
        // rather than on the invariant. The deadline here is only a
        // failsafe against hanging forever; it is never the thing being
        // waited on, and a child that dies before signalling is reported
        // immediately with its exit code and stderr instead of timing out.
        $this->awaitLockSignal($slow);

        $start = microtime(true);
        $fast = new Process([$phpBinary, $runnerScript, 'plain', '0', (string) $ownerUserId], null, $childEnv);
        $fast->run();
        $elapsed = microtime(true) - $start;

        $slow->wait();

        $this->assertTrue($slow->isSuccessful(), 'slow process failed: ' . $slow->getErrorOutput());
        $this->assertTrue($fast->isSuccessful(), 'fast process failed: ' . $fast->getErrorOutput());

        // The fast attempt must have genuinely blocked on the lock for
        // roughly the slow process's hold duration — not merely run to
        // completion after the slow one finished by coincidence.
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'Fast attempt did not appear to block on the lock.');

        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $ownerUserId)->pluck('id');
        $this->assertCount(1, $workspaceIds, 'Expected exactly one Workspace after two concurrent attempts.');

        preg_match('/workspace_id=(\d+)/', $slow->getOutput(), $slowMatch);
        preg_match('/workspace_id=(\d+)/', $fast->getOutput(), $fastMatch);

        $this->assertNotEmpty($slowMatch, 'slow process did not report a resolved workspace_id: ' . $slow->getOutput());
        $this->assertNotEmpty($fastMatch, 'fast process did not report a resolved workspace_id: ' . $fast->getOutput());
        $this->assertSame($slowMatch[1], $fastMatch[1], 'Both processes must resolve the same Workspace ID.');
        $this->assertSame((string) $workspaceIds->first(), $slowMatch[1]);
    }

    // B. Runner database guard test.
    public function test_runner_refuses_to_execute_against_an_unexpected_resolved_database(): void
    {
        $runnerScript = __DIR__ . '/Support/concurrent_workspace_resolver_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $bogusDatabase = 'deliberately_wrong_test_db_does_not_exist';

        $process = new Process(
            [$phpBinary, $runnerScript, 'plain', '0', '1'],
            null,
            ['DB_DATABASE' => $bogusDatabase]
        );
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(3, $process->getExitCode());
        $this->assertStringContainsString($bogusDatabase, $process->getErrorOutput());
        $this->assertStringContainsString('Refusing to run WorkspaceManager', $process->getErrorOutput());

        // The resolver only ever prints its "OK ..." success line after a
        // completed resolveLegacyOnboardingWorkspace() call; an empty
        // stdout proves the guard aborted before the manager was even
        // constructed, let alone run, and no connection to the bogus
        // database was ever attempted (getDatabaseName() is config-only).
        $this->assertSame('', trim($process->getOutput()));
    }

    /**
     * C. Lock-probe test — fast, same-process, isolated proof that
     * resolveLegacyOnboardingWorkspace() genuinely blocks on the
     * users-row lock, independent of the real two-process test's
     * subprocess-timing variance. Mirrors
     * OpportunityManagerConcurrencyTest's exact second-connection
     * technique; no production code changes were needed to support it.
     */
    public function test_users_row_lock_serializes_resolve_legacy_onboarding_workspace(): void
    {
        $probe = $this->setUpProbeConnection();
        $ownerUserId = null;

        try {
            $ownerUserId = DB::table('users')->insertGetId([
                'uid' => (string) Str::uuid(),
                'first_name' => 'LockProbe',
                'last_name' => 'Resolver',
                'email' => 'resolver-lock-probe-' . uniqid('', true) . '@example.test',
                'status' => true,
                'is_admin' => false,
                'is_customer' => true,
                'active_portal' => 'customer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertConnectionsAreGenuinelyDistinct($probe);

            $probe->beginTransaction();

            try {
                // Genuine row lock on the users row, held open on the
                // probe connection — never released until rollBack() below.
                $probe->select('SELECT * FROM users WHERE id = ? FOR UPDATE', [$ownerUserId]);

                $caught = null;
                $start = microtime(true);

                $this->withShortLockWaitTimeout(function () use ($ownerUserId, &$caught) {
                    try {
                        app(WorkspaceManager::class)->resolveLegacyOnboardingWorkspace($ownerUserId);
                    } catch (QueryException $e) {
                        $caught = $e;
                    }
                });

                $elapsed = microtime(true) - $start;

                $this->assertLessThan(
                    self::MAX_ELAPSED_SECONDS,
                    $elapsed,
                    'resolveLegacyOnboardingWorkspace() did not fail within the bounded lock-wait window — it may have hung.'
                );
                $this->assertNotNull(
                    $caught,
                    'Expected resolveLegacyOnboardingWorkspace() to fail with a MySQL lock-wait-timeout QueryException while the users row was locked by another connection.'
                );
                $this->assertSame(self::MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE, $caught->errorInfo[1] ?? null);

                $this->assertSame(0, DB::table('workspaces')->where('owner_user_id', $ownerUserId)->count());
            } finally {
                $probe->rollBack();
            }
        } finally {
            if ($ownerUserId !== null) {
                // Defensive: this test's own assertion above requires zero
                // Workspaces to have been created (the resolver call is
                // expected to fail on the lock-wait timeout), but the
                // same FK-safe M2 cleanup is used here too in case that
                // assumption is ever violated by a future change.
                $this->deleteWorkspacesAndM2EntitlementChildrenForOwners([$ownerUserId]);
                DB::table('users')->where('id', $ownerUserId)->delete();
            }

            $this->tearDownProbeConnection();
        }
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
     * default — restoring the original value afterward regardless of
     * outcome.
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

    /**
     * Block until the holder process has PROVEN it holds its row lock.
     *
     * The synchronisation is the child's own "LOCKED" line, printed after
     * the lock is acquired and before it is held — not a duration. The
     * deadline is a failsafe so a wedged child cannot hang the suite
     * forever, and it is deliberately generous because it is not what the
     * test is waiting on. A child that exits before signalling is a real
     * failure and is reported immediately, with its exit code and stderr,
     * rather than being swallowed until the deadline expires.
     */
    private function awaitLockSignal(Process $holder): void
    {
        $deadline = microtime(true) + self::LOCK_SIGNAL_TIMEOUT_SECONDS;

        while (! str_contains($holder->getOutput(), 'LOCKED')) {
            if (! $holder->isRunning()) {
                $this->fail(sprintf(
                    "Holder process exited (code %s) before signalling that it held its lock.\nstdout: %s\nstderr: %s",
                    var_export($holder->getExitCode(), true),
                    $holder->getOutput(),
                    $holder->getErrorOutput()
                ));
            }

            if (microtime(true) >= $deadline) {
                $holder->stop(0);

                $this->fail(sprintf(
                    "Holder process never signalled that it held its lock within %ds.\nstdout: %s\nstderr: %s",
                    self::LOCK_SIGNAL_TIMEOUT_SECONDS,
                    $holder->getOutput(),
                    $holder->getErrorOutput()
                ));
            }

            usleep(20_000);
        }
    }
}
