<?php

namespace Tests\Feature\Workspace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real cross-process concurrency test for WorkspaceBackfillV1 (RFC-003
 * §10.4, §18, §21.4): proves two concurrent attempts for the same legacy
 * customer_id serialize on the users-row lock and produce exactly one
 * Workspace, never two.
 *
 * Deliberately does NOT use RefreshDatabase. A genuine cross-process test
 * requires the fixture rows to be visible to a second, wholly independent
 * PHP process and database connection — an open, never-committed
 * RefreshDatabase transaction would hide them from it entirely. Fixture
 * rows are inserted directly (auto-committed) and explicitly removed in
 * tearDown().
 */
class WorkspaceBackfillV1ConcurrencyTest extends TestCase
{
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
        $this->assertSame('ultimatesms_testing', DB::connection()->getDatabaseName());

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

        $slow = new Process([$phpBinary, $runnerScript, 'slow', $holdSeconds]);
        $slow->start();

        // Give the slow process enough time to connect and acquire the
        // users-row lock before the fast attempt races it.
        usleep(500_000);

        $start = microtime(true);
        $fast = new Process([$phpBinary, $runnerScript, 'plain', '0']);
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
}
