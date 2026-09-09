<?php

namespace Tests\Feature\Workspace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Workspace\Support\HistoricalWorkspaceConcurrencyTestCase;

/**
 * Real cross-process concurrency test for WorkspaceBackfillV1 (RFC-003
 * §10.4, §18, §21.4): proves two concurrent attempts for the same legacy
 * customer_id serialize on the users-row lock and produce exactly one
 * Workspace, never two.
 *
 * Deliberately does NOT use DatabaseTransactions/RefreshDatabase. A
 * genuine cross-process test requires the fixture rows to be visible to a
 * second, wholly independent PHP process and database connection — an
 * open, never-committed transaction would hide them from it entirely.
 * Fixture rows are inserted directly (auto-committed) and explicitly
 * removed in tearDown().
 */
#[Group('historical-m1a')]
class WorkspaceBackfillV1ConcurrencyTest extends HistoricalWorkspaceConcurrencyTestCase
{
    /**
     * Failsafe only. The barrier is the holder's own "LOCKED" line; this
     * bound exists so a wedged child cannot hang the suite, and is set
     * well above the worst observed child boot time under whole-suite
     * load rather than being tuned to make a race pass.
     */
    private const LOCK_SIGNAL_TIMEOUT_SECONDS = 60;

    private array $createdUserIds = [];
    private array $createdBusinessIds = [];

    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('workspaces')->whereIn('owner_user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    public function test_two_concurrent_attempts_for_the_same_legacy_customer_create_exactly_one_workspace(): void
    {
        $expectedDatabase = getenv('EXPECTED_TEST_DATABASE');
        $this->assertSame($expectedDatabase, DB::connection()->getDatabaseName());

        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Concurrency',
            'last_name' => 'Test',
            'email' => 'concurrency-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $userId;

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $userId,
            'workspace_id' => null,
            'name' => 'Concurrency Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'draft',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        $runnerScript = __DIR__ . '/Support/concurrent_backfill_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $holdSeconds = '2';

        // Forward this already-verified historical environment explicitly
        // rather than relying on ambient process-env inheritance — the
        // sub-child must resolve the same historical database this test
        // itself is running against, never ultimatesms_testing.
        $childEnv = [
            'DB_DATABASE' => getenv('DB_DATABASE'),
            'EXPECTED_TEST_DATABASE' => $expectedDatabase,
        ];

        $slow = new Process([$phpBinary, $runnerScript, 'slow', $holdSeconds], null, $childEnv);
        $slow->start();

        // Wait for the holder's OWN "LOCKED" signal, not for a guessed
        // duration. SlowWorkspaceBackfillV1 prints it immediately after
        // lockOwnerRow() returns and before it starts holding, so this
        // returns only once the users-row lock is genuinely held and the
        // race window below is guaranteed to be reached.
        //
        // The previous usleep(500_000) was a guess: under whole-suite
        // load a child needs longer than that just to boot Laravel, so
        // the "racing" process could start after the holder had already
        // finished, and the test then turned on scheduler luck rather
        // than on the invariant.
        $this->awaitLockSignal($slow);

        $start = microtime(true);
        $fast = new Process([$phpBinary, $runnerScript, 'plain', '0'], null, $childEnv);
        $fast->run();
        $elapsed = microtime(true) - $start;

        $slow->wait();

        $this->assertTrue($slow->isSuccessful(), 'slow process failed: ' . $slow->getErrorOutput());
        $this->assertTrue($fast->isSuccessful(), 'fast process failed: ' . $fast->getErrorOutput());

        // The fast attempt must have genuinely blocked on the lock for
        // roughly the slow process's hold duration — not merely run to
        // completion after the slow one finished by coincidence.
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'Fast attempt did not appear to block on the lock.');

        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $userId)->pluck('id');
        $this->assertCount(1, $workspaceIds, 'Expected exactly one Workspace after two concurrent attempts.');

        $this->assertSame(
            $workspaceIds->first(),
            DB::table('businesses')->where('id', $businessId)->value('workspace_id')
        );
    }

    /**
     * The runner process re-verifies its own resolved database connection
     * before doing anything else — APP_ENV=testing alone isn't proof
     * enough. Rather than pointing a child process at a real wrong
     * database (which the instructions forbid, and which would require
     * separate credentials), this overrides DB_DATABASE via the child's
     * environment while forwarding this test's own real, already-verified
     * EXPECTED_TEST_DATABASE: Laravel's Dotenv loader is immutable, so a
     * pre-set OS env var wins over .env.testing's value, and the resolved
     * database name changes without ever attempting a real connection to
     * it — the guard checks getDatabaseName() (config-only) against the
     * genuinely expected value before any query runs, so the mismatch
     * between the bogus DB_DATABASE and the real EXPECTED_TEST_DATABASE is
     * exactly what triggers the refusal.
     */
    public function test_runner_refuses_to_execute_against_an_unexpected_resolved_database(): void
    {
        $runnerScript = __DIR__ . '/Support/concurrent_backfill_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $bogusDatabase = 'deliberately_wrong_test_db_does_not_exist';

        $process = new Process(
            [$phpBinary, $runnerScript, 'plain', '0'],
            null,
            ['DB_DATABASE' => $bogusDatabase, 'EXPECTED_TEST_DATABASE' => getenv('EXPECTED_TEST_DATABASE')]
        );
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(3, $process->getExitCode());
        $this->assertStringContainsString($bogusDatabase, $process->getErrorOutput());
        $this->assertStringContainsString('Refusing to run WorkspaceBackfillV1', $process->getErrorOutput());

        // WorkspaceBackfillV1 only ever prints its "OK ..." success line
        // after a completed run() call; an empty stdout proves the guard
        // aborted before the action was even instantiated, let alone run.
        $this->assertSame('', trim($process->getOutput()));
    }

    /**
     * Block until the holder process has PROVEN it holds its row lock.
     *
     * The synchronisation is the child's own "LOCKED" line, printed after
     * the lock is acquired and before it is held — not a duration. The
     * deadline is a failsafe so a wedged child cannot hang the suite, and
     * is deliberately generous because it is not what the test waits on.
     * A child that exits before signalling is a real failure and is
     * reported at once, with its exit code and stderr, rather than being
     * swallowed until the deadline expires.
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
