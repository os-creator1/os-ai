<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\ClientInvitationStatus;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\ClientWorkspaceInvitation;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 07, final correction round item 4 — real
 * concurrency for AgencyClientProvisioningManager::accept(): two genuinely
 * independent OS processes racing to accept the SAME Pending invitation
 * must leave exactly one successful acceptance and one Client Workspace,
 * never two.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process
 * needs committed rows, which an open RefreshDatabase transaction would
 * hide entirely. Mirrors AgencyClientRelationshipConcurrencyTest's own
 * proven strategy: fixture rows are inserted directly (committed for
 * real) and explicitly removed in tearDown().
 */
class AgencyClientProvisioningConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_client_invitation_acceptance_lock_probe';

    private const NOT_PENDING_EXIT_CODE = 5;

    /** Held for a further, known interval after both children are inside accept(). */
    private const HELD_AFTER_BOTH_ENTERED_MICROSECONDS = 1_500_000;

    /** Allows for clock granularity; still an order of magnitude above an unblocked accept(). */
    private const MINIMUM_BLOCKED_MILLISECONDS = 1_000;

    private array $createdUserIds = [];

    private array $createdWorkspaceIds = [];

    private ?string $createdInvitationUid = null;

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

    /**
     * Deletion order respects every FK this feature's own migrations
     * declare restrictOnDelete() on (client_workspace_invitations ->
     * workspaces, business_payer_assignments -> businesses, etc.) —
     * invitation and Business-owned rows first, then the Workspaces they
     * point at, exactly like AgencyClientRelationshipConcurrencyTest's own
     * deleteFixtures() orders relationships before workspace_plan_assignments
     * before workspaces.
     */
    private function deleteFixtures(): void
    {
        if ($this->createdInvitationUid !== null) {
            DB::table('client_workspace_invitations')->where('uid', $this->createdInvitationUid)->delete();
            $this->createdInvitationUid = null;
        }

        if ($this->createdWorkspaceIds !== []) {
            $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id')->all();

            if ($businessIds !== []) {
                DB::table('business_usage_wallet_billing_status_transitions')->whereIn('business_id', $businessIds)->delete();
                DB::table('business_usage_wallets')->whereIn('business_id', $businessIds)->delete();
                DB::table('business_payer_assignments')->whereIn('business_id', $businessIds)->delete();
                DB::table('business_locations')->whereIn('business_id', $businessIds)->delete();
                DB::table('businesses')->whereIn('id', $businessIds)->delete();
            }

            DB::table('agency_client_workspace_relationships')
                ->whereIn('agency_workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('client_workspace_id', $this->createdWorkspaceIds)
                ->delete();

            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();

            $this->createdWorkspaceIds = [];
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();

            $this->createdUserIds = [];
        }
    }

    /**
     * Forwarded explicitly to every spawned runner process, mirroring the
     * proven pattern in AgencyClientRelationshipConcurrencyTest — the child
     * must resolve the very same validated disposable database this parent
     * process is running against, never a hardcoded literal.
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function insertUser(string $label, bool $isCustomer = true): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Provisioning',
            'last_name' => $label,
            'email' => 'client-invitation-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => $isCustomer,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    private function insertCustomer(int $userId): void
    {
        DB::table('customers')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
     * @return array{0: int, 1: int} the Agency Workspace id and its owner's user id
     */
    private function agencyWorkspace(string $name): array
    {
        $ownerUserId = $this->insertUser('Agency Owner');
        $this->insertCustomer($ownerUserId);
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

    /**
     * A real, authenticatable accepting User + Customer, plus a Pending
     * invitation addressed to their exact (normalized) email — written
     * directly, exactly like ClientInvitationManager::send() would leave
     * it: a hashed token, never plaintext, at rest.
     *
     * @return array{0: string, 1: string, 2: int} invitation uid, plaintext token, accepting user id
     */
    private function pendingInvitationForRealUser(int $agencyWorkspaceId, int $invitedByUserId, string $businessName): array
    {
        $email = 'invited-client-' . uniqid('', true) . '@example.test';
        $acceptingUserId = $this->insertUser('Accepting Client');
        $this->insertCustomer($acceptingUserId);

        DB::table('users')->where('id', $acceptingUserId)->update(['email' => $email]);

        $plaintextToken = Str::random(64);
        $uid = (string) Str::uuid();

        DB::table('client_workspace_invitations')->insert([
            'uid' => $uid,
            'agency_workspace_id' => $agencyWorkspaceId,
            'invited_by_user_id' => $invitedByUserId,
            'email' => $email,
            'token_hash' => Hash::make($plaintextToken),
            'intended_business_name' => $businessName,
            'status' => ClientInvitationStatus::Pending->value,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdInvitationUid = $uid;

        return [$uid, $plaintextToken, $acceptingUserId];
    }

    public function test_two_racing_acceptance_attempts_leave_exactly_one_client_workspace(): void
    {
        [$agencyWorkspaceId, $agencyOwnerId] = $this->agencyWorkspace('Racing Provisioning Agency');
        [$invitationUid, $plaintextToken, $acceptingUserId] = $this->pendingInvitationForRealUser(
            $agencyWorkspaceId,
            $agencyOwnerId,
            'Contested New Co',
        );

        $runnerScript = __DIR__ . '/Support/concurrent_client_invitation_acceptance_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $invitationId = DB::table('client_workspace_invitations')->where('uid', $invitationUid)->value('id');

        $probe = $this->setUpProbeConnection();
        $probe->beginTransaction();

        // Both children attempt to accept the exact SAME invitation with
        // the exact same accepting User — the real-world double-submit /
        // double-click hazard this lock exists to close.
        $first = new Process(
            [$phpBinary, $runnerScript, $invitationUid, $plaintextToken, (string) $acceptingUserId],
            null,
            $this->childEnvironment(),
        );
        $second = new Process(
            [$phpBinary, $runnerScript, $invitationUid, $plaintextToken, (string) $acceptingUserId],
            null,
            $this->childEnvironment(),
        );

        try {
            $this->assertConnectionsAreGenuinelyDistinct($probe);

            // Holding the contested invitation row makes the race
            // deterministic: both children must queue behind this one
            // lock, whatever order they boot in -- the exact row
            // findByUidForUpdate() locks as the first statement in
            // accept()'s own transaction.
            $probe->select('SELECT * FROM client_workspace_invitations WHERE id = ? FOR UPDATE', [$invitationId]);

            $first->start();
            $second->start();

            $bothEnteredAccept = $this->waitForBothChildrenToEnterAccept($first, $second);

            usleep(self::HELD_AFTER_BOTH_ENTERED_MICROSECONDS);
        } finally {
            $probe->rollBack();
            $this->tearDownProbeConnection();
        }

        $first->wait();
        $second->wait();

        $this->assertTrue(
            $bothEnteredAccept,
            'Both child processes were expected to reach accept() while the contested invitation row was still locked; the race never actually overlapped.'
        );

        $exitCodes = [$first->getExitCode(), $second->getExitCode()];
        $output = 'first: ' . $first->getOutput() . $first->getErrorOutput()
            . ' second: ' . $second->getOutput() . $second->getErrorOutput();

        sort($exitCodes);

        // If MariaDB's exact exception text is nondeterministic across a
        // lock-wait vs. a clean re-check, the exit codes/DB state below are
        // the durable proof, not any error-message string.
        $this->assertSame(
            [0, self::NOT_PENDING_EXIT_CODE],
            $exitCodes,
            'Exactly one process must accept the invitation and exactly one must fail closed as already/not-Pending. ' . $output
        );

        foreach ($this->reportedAcceptDurations($first, $second) as $elapsedMilliseconds) {
            $this->assertGreaterThanOrEqual(
                self::MINIMUM_BLOCKED_MILLISECONDS,
                $elapsedMilliseconds,
                'Each accept() must have genuinely waited on the contested invitation row lock, not merely run after it was released. ' . $output
            );
        }

        // ---- Durable DB-state proof (never brittle error-message text) ----

        $invitation = ClientWorkspaceInvitation::query()->where('uid', $invitationUid)->first();

        $this->assertSame(ClientInvitationStatus::Accepted, $invitation->status);
        $this->assertNotNull($invitation->created_client_workspace_id);
        $this->assertNotNull($invitation->accepted_at);

        $winner = $first->getExitCode() === 0 ? $first : $second;
        preg_match('/created_client_workspace_id=(\d+)/', $winner->getOutput(), $winnerMatch);
        $this->assertNotEmpty($winnerMatch, 'The winning process did not report the Client Workspace id it created: ' . $winner->getOutput());
        $winningWorkspaceId = (int) $winnerMatch[1];

        $this->assertSame($winningWorkspaceId, (int) $invitation->created_client_workspace_id);
        $this->createdWorkspaceIds[] = $winningWorkspaceId;

        // Exactly one Client Workspace -- never two, whatever the race outcome.
        $this->assertSame(
            1,
            Workspace::where('owner_user_id', $acceptingUserId)->count(),
            'The accepting User must own exactly one new Client Workspace after the race, never two.'
        );

        // Exactly one Business, one Primary Location.
        $business = Business::where('workspace_id', $winningWorkspaceId)->first();
        $this->assertNotNull($business);
        $this->assertSame(1, Business::where('workspace_id', $winningWorkspaceId)->count());
        $this->assertSame(
            1,
            DB::table('business_locations')->where('business_id', $business->id)->where('is_primary', true)->count()
        );

        // Exactly one Active Agency<->Client relationship.
        $relationships = AgencyClientWorkspaceRelationship::where('agency_workspace_id', $agencyWorkspaceId)
            ->where('client_workspace_id', $winningWorkspaceId)
            ->get();
        $this->assertCount(1, $relationships);
        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationships->first()->status);

        // The loser created nothing of its own -- no second Workspace
        // exists anywhere pointing at this same invitation or agency race.
        $this->assertSame(
            1,
            AgencyClientWorkspaceRelationship::where('agency_workspace_id', $agencyWorkspaceId)->count(),
            'The losing process must not have left behind a second relationship row.'
        );
    }

    /**
     * Mirrors AgencyClientRelationshipConcurrencyTest's own
     * waitForBothChildrenToEnterCreate(): performance_schema.data_locks
     * would be the most direct signal, but reading it needs a privilege
     * the disposable test database user deliberately does not have.
     */
    private function waitForBothChildrenToEnterAccept(Process $first, Process $second): bool
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
     * @return array{0: int, 1: int} each child's own accept() duration, in milliseconds
     */
    private function reportedAcceptDurations(Process $first, Process $second): array
    {
        return [$this->reportedAcceptDuration($first), $this->reportedAcceptDuration($second)];
    }

    private function reportedAcceptDuration(Process $process): int
    {
        preg_match('/elapsed_ms=(\d+)/', $process->getOutput(), $match);

        $this->assertNotEmpty($match, 'A child process did not report how long its accept() took: ' . $process->getOutput() . $process->getErrorOutput());

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
}
