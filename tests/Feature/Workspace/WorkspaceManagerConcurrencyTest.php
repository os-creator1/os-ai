<?php

namespace Tests\Feature\Workspace;

use App\Library\Workspace\WorkspaceManager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
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
        $this->assertSame('ultimatesms_testing', DB::connection()->getDatabaseName());

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

        $slow = new Process([$phpBinary, $runnerScript, 'slow', $holdSeconds, (string) $ownerUserId]);
        $slow->start();

        // Give the slow process enough time to connect and acquire the
        // users-row lock before the fast attempt races it.
        usleep(500_000);

        $start = microtime(true);
        $fast = new Process([$phpBinary, $runnerScript, 'plain', '0', (string) $ownerUserId]);
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
}
