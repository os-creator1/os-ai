<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves WorkspaceEntitlementBackfillV1's concurrency safety (RFC-004
 * §25.2, "two simultaneous backfill attempts for the same never-before-
 * assigned Workspace create exactly one workspace_plan_assignments row")
 * with a real two-process race of the actual public run() method — not a
 * simulated raw-insert stand-in.
 *
 * Deliberately does NOT use RefreshDatabase — its per-test wrapping
 * transaction on the default connection would hide an uncommitted insert
 * from a genuinely separate child process, defeating the point of this
 * test (mirroring WorkspaceBackfillV1ConcurrencyTest's identical rationale,
 * RFC-003). Fixture rows are inserted directly (auto-committed) and
 * explicitly removed in tearDown().
 *
 * Architectural precedent: tests/Feature/Workspace/WorkspaceBackfillV1ConcurrencyTest.php
 * (RFC-003), which spawns a separate runner *file*. No new file is
 * authorized here, so both child processes run inline `php -r` code built
 * by buildRunnerCode() instead of a Support/ script — same Symfony Process
 * tooling, same strict test-database guard (a child refuses to run unless
 * its own resolved connection matches the parent's explicitly-forwarded
 * EXPECTED_TEST_DATABASE), same before-any-mutation ordering.
 *
 * Genuine overlap (not sequential coincidence) is forced by the parent: it
 * holds a real FOR UPDATE lock on the one fixture Workspace's row before
 * starting either child. Both children's own WorkspaceEntitlementBackfillV1::run()
 * independently select that same still-unassigned Workspace (the
 * candidate-selection query takes no lock), then both attempt their own
 * insert into workspace_plan_assignments — and MySQL/InnoDB requires an
 * implicit shared lock on the referenced workspaces row to satisfy that
 * insert's own foreign key, which blocks behind the parent's held
 * exclusive lock. Only once the parent commits do both children's inserts
 * unblock together and genuinely race the workspace_id unique constraint.
 */
class WorkspaceEntitlementBackfillV1ConcurrencyTest extends TestCase
{
    private array $createdWorkspaceIds = [];
    private array $createdUserIds = [];

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

    /**
     * Inline `php -r` source for each child process: boots Laravel exactly
     * as tests/Feature/Workspace/Support/concurrent_backfill_runner.php
     * does (RFC-003 precedent), refuses to run against any database other
     * than the one explicitly forwarded by the parent, emits a READY
     * marker immediately before calling the real backfill, and reports its
     * outcome on stdout/stderr with a distinguishing exit code.
     */
    private function buildRunnerCode(): string
    {
        $basePath = var_export(str_replace('\\', '/', base_path()), true);

        return <<<PHP
require {$basePath} . '/vendor/autoload.php';
putenv('APP_ENV=testing');
\$_ENV['APP_ENV'] = 'testing';
\$_SERVER['APP_ENV'] = 'testing';
\$app = require {$basePath} . '/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

\$expected = getenv('EXPECTED_TEST_DATABASE');
\$resolved = Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName();

if (\$expected === false || \$expected === '' || \$resolved !== \$expected) {
    fwrite(STDERR, "Refusing to run WorkspaceEntitlementBackfillV1: resolved database [{\$resolved}] does not match expected [{\$expected}]. Aborting before any database write.\\n");
    exit(3);
}

fwrite(STDOUT, "READY\\n");
fflush(STDOUT);

try {
    \$result = (new App\\Library\\Entitlement\\Migration\\WorkspaceEntitlementBackfillV1())->run();
    fwrite(STDOUT, sprintf("OK workspacesProcessed=%d assignmentsCreated=%d\\n", \$result->workspacesProcessed, \$result->assignmentsCreated));
    exit(0);
} catch (Throwable \$e) {
    fwrite(STDERR, get_class(\$e) . ': ' . \$e->getMessage() . "\\n");
    exit(1);
}
PHP;
    }

    private function waitForReady(Process $process, string $label, float $timeoutSeconds = 10.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! str_contains($process->getOutput(), "READY\n")) {
            if (! $process->isRunning()) {
                $this->fail("Child process [{$label}] exited before emitting READY. stderr: " . $process->getErrorOutput());
            }

            if (microtime(true) > $deadline) {
                $this->fail("Timed out waiting for child process [{$label}] READY marker. stderr: " . $process->getErrorOutput());
            }

            usleep(20_000);
        }
    }

    public function test_two_real_concurrent_backfill_runs_for_the_same_workspace_create_exactly_one_assignment(): void
    {
        $expectedDatabase = DB::connection()->getDatabaseName();
        $workspaceId = $this->insertWorkspace();

        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $runnerCode = $this->buildRunnerCode();
        $childEnv = [
            'DB_DATABASE' => getenv('DB_DATABASE') ?: null,
            'EXPECTED_TEST_DATABASE' => $expectedDatabase,
        ];

        $processA = new Process([$phpBinary, '-r', $runnerCode], null, $childEnv);
        $processB = new Process([$phpBinary, '-r', $runnerCode], null, $childEnv);

        // Force genuine overlap: hold an exclusive lock on the one fixture
        // Workspace row before either child starts, so both independently
        // select it as a candidate and then both block on the same
        // implicit foreign-key shared-lock request when attempting their
        // own workspace_plan_assignments insert.
        DB::beginTransaction();
        DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();

        $processA->start();
        $processB->start();

        $this->waitForReady($processA, 'A');
        $this->waitForReady($processB, 'B');

        // Short controlled interval so both children genuinely reach the
        // locked insert path before the parent releases the lock.
        usleep(300_000);

        $this->assertTrue($processA->isRunning(), 'Expected child A to still be blocked before the lock releases.');
        $this->assertTrue($processB->isRunning(), 'Expected child B to still be blocked before the lock releases.');

        DB::commit();

        $processA->wait();
        $processB->wait();

        $this->assertTrue($processA->isSuccessful(), 'child A failed: ' . $processA->getErrorOutput());
        $this->assertTrue($processB->isSuccessful(), 'child B failed: ' . $processB->getErrorOutput());

        $this->assertSame(
            1,
            DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceId)->count(),
            'Expected exactly one workspace_plan_assignments row after two real concurrent backfill runs.'
        );
        $this->assertSame(
            1,
            DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceId)
                ->where('transition_type', WorkspaceEntitlementTransitionType::PlanAssigned->value)
                ->count(),
            'Expected exactly one plan_assigned transition row after two real concurrent backfill runs.'
        );
    }

    // Idempotent full rerun: a second complete run against an
    // already-fully-assigned database performs zero work and reports zero
    // remaining, per the contract's own required assertions.
    public function test_full_rerun_after_complete_first_run_is_a_true_no_op(): void
    {
        $workspaceId = $this->insertWorkspace();

        $first = (new WorkspaceEntitlementBackfillV1())->run();
        $second = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(1, $first->assignmentsCreated);

        $this->assertSame(0, $second->workspacesProcessed);
        $this->assertSame(0, $second->assignmentsCreated);
        $this->assertSame(0, $second->remainingUnassignedCount);

        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceId)->count());
        $this->assertSame(
            1,
            DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceId)
                ->where('transition_type', WorkspaceEntitlementTransitionType::PlanAssigned->value)
                ->count()
        );
    }

    // Partial-rerun safety: a Workspace already committed by a prior
    // (simulated interrupted) run is never reprocessed or duplicated, while
    // a still-unassigned sibling Workspace is correctly picked up by the
    // next normal run() call, ending with zero remaining unassigned.
    public function test_partial_rerun_does_not_reprocess_an_already_committed_workspace(): void
    {
        $workspaceAId = $this->insertWorkspace();
        $workspaceBId = $this->insertWorkspace();
        $coreCatalogId = $this->coreCatalogId();

        // Simulate a prior run that committed Workspace A's assignment but
        // was interrupted before reaching Workspace B — using the
        // backfill's own real per-Workspace unit of work
        // (processWorkspace(), accessed via reflection since it is
        // protected), not a hand-rolled reimplementation.
        $backfill = new WorkspaceEntitlementBackfillV1();
        $method = new ReflectionMethod(WorkspaceEntitlementBackfillV1::class, 'processWorkspace');
        $method->setAccessible(true);
        $createdA = $method->invoke($backfill, $workspaceAId, $coreCatalogId);

        $this->assertTrue($createdA);
        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceAId)->count());
        $this->assertSame(0, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceBId)->count());

        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(0, $result->remainingUnassignedCount);

        // Workspace A: still exactly one assignment and one transition —
        // never reprocessed or duplicated.
        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceAId)->count());
        $this->assertSame(
            1,
            DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceAId)
                ->where('transition_type', WorkspaceEntitlementTransitionType::PlanAssigned->value)
                ->count()
        );

        // Workspace B: correctly picked up and completed by the resumed run.
        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceBId)->count());
        $this->assertSame(
            1,
            DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceBId)
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
