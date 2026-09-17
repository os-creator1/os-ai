<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 10 — real concurrency for
 * AgencyBusinessMigrationV1, corrected for the per-Agency-Workspace
 * transaction boundary (§7 review correction): two genuinely independent
 * OS processes race to migrate the SAME legacy Agency Workspace's ENTIRE
 * batch of client Businesses (deliberately more than one, to prove the
 * whole-batch atomicity survives a real race, not just a single-Business
 * one). Exactly one process's Agency-level transaction must commit —
 * migrating every client Business in one shot — and the other must observe
 * a clean "no work left" outcome, never a duplicate Client Workspace,
 * relationship, or contradictory payer conversion.
 *
 * Deliberately does NOT use RefreshDatabase — mirrors
 * AgencyClientRelationshipConcurrencyTest/AgencyClientProvisioningConcurrencyTest's
 * own proven strategy: fixture rows are committed for real and explicitly
 * removed in tearDown().
 */
class AgencyBusinessMigrationV1ConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_agency_business_migration_lock_probe';

    private const ALREADY_MIGRATED_EXIT_CODE = 6;

    private const HELD_AFTER_BOTH_ENTERED_MICROSECONDS = 1_500_000;

    private const MINIMUM_BLOCKED_MILLISECONDS = 1_000;

    private array $createdUserIds = [];

    private array $createdWorkspaceIds = [];

    protected function setUp(): void
    {
        parent::setUp();

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
            $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id')->all();

            DB::table('workspace_transitions')
                ->whereIn('workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('from_workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('business_id', $businessIds)
                ->delete();

            if ($businessIds !== []) {
                DB::table('business_payer_assignments')->whereIn('business_id', $businessIds)->delete();
                DB::table('business_locations')->whereIn('business_id', $businessIds)->delete();
                DB::table('workspace_membership_businesses')->whereIn('business_id', $businessIds)->delete();
                DB::table('businesses')->whereIn('id', $businessIds)->delete();
            }

            DB::table('agency_client_workspace_relationships')
                ->whereIn('agency_workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('client_workspace_id', $this->createdWorkspaceIds)
                ->delete();

            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
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

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    private function insertUser(string $label, bool $isAdmin = false, bool $isCustomer = true): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Migration',
            'last_name' => $label,
            'email' => 'agency-business-migration-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => $isAdmin,
            'is_customer' => $isCustomer,
            'active_portal' => $isAdmin ? 'admin' : 'customer',
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

    /**
     * A real platform-relationship-operator: is_admin plus the dedicated
     * Role permission createForMigration() requires.
     */
    private function platformOperator(): int
    {
        $userId = $this->insertUser('Operator', isAdmin: true, isCustomer: false);

        $roleId = DB::table('roles')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'relationship-operator-' . uniqid('', true),
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function insertBusiness(int $customerUserId, int $workspaceId, string $name, bool $isPrimary): int
    {
        return DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customerUserId,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'industry' => 'other',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'active',
            'is_primary' => $isPrimary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: int} agency workspace id, first legacy client Business id, second legacy client Business id
     */
    private function legacyAgencyWithTwoClients(): array
    {
        $ownerUserId = $this->insertUser('Owner');
        $this->insertCustomer($ownerUserId);
        $agencyWorkspaceId = $this->insertWorkspace($ownerUserId, 'Racing Migration Agency');

        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $agencyWorkspaceId,
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')
                ->where('tier', WorkspacePlanTier::Agency->value)
                ->value('id'),
            'status' => 'active',
            'is_complimentary' => false,
            'additional_business_slots' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primaryId = $this->insertBusiness($ownerUserId, $agencyWorkspaceId, 'Agency Primary', true);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $primaryId,
            'payer_type' => PayerType::Business->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clientOneId = $this->insertBusiness($ownerUserId, $agencyWorkspaceId, 'Contested Client One', false);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $clientOneId,
            'payer_type' => PayerType::Workspace->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clientTwoId = $this->insertBusiness($ownerUserId, $agencyWorkspaceId, 'Contested Client Two', false);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $clientTwoId,
            'payer_type' => PayerType::Business->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$agencyWorkspaceId, $clientOneId, $clientTwoId];
    }

    public function test_two_racing_migration_attempts_leave_exactly_one_agency_batch_committed(): void
    {
        [$agencyWorkspaceId, $clientOneId, $clientTwoId] = $this->legacyAgencyWithTwoClients();
        $operatorUserId = $this->platformOperator();
        $workspaceCountBefore = Workspace::count();

        $runnerScript = __DIR__ . '/Support/concurrent_agency_business_migration_runner.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $probe = $this->setUpProbeConnection();
        $probe->beginTransaction();

        $first = new Process(
            [$phpBinary, $runnerScript, (string) $agencyWorkspaceId, (string) $operatorUserId],
            null,
            $this->childEnvironment(),
        );
        $second = new Process(
            [$phpBinary, $runnerScript, (string) $agencyWorkspaceId, (string) $operatorUserId],
            null,
            $this->childEnvironment(),
        );

        try {
            $this->assertConnectionsAreGenuinelyDistinct($probe);

            // Holding the Agency Workspace row — the very first lock
            // migrateOneBusiness() itself takes — makes the race
            // deterministic: both children queue behind this one lock,
            // whatever order they boot in.
            $probe->select('SELECT * FROM workspaces WHERE id = ? FOR UPDATE', [$agencyWorkspaceId]);

            $first->start();
            $second->start();

            $bothEntered = $this->waitForBothChildrenToEnterRun($first, $second);

            usleep(self::HELD_AFTER_BOTH_ENTERED_MICROSECONDS);
        } finally {
            $probe->rollBack();
            $this->tearDownProbeConnection();
        }

        $first->wait();
        $second->wait();

        $this->assertTrue(
            $bothEntered,
            'Both child processes were expected to reach run() while the Agency Workspace row was still locked; the race never actually overlapped.'
        );

        $exitCodes = [$first->getExitCode(), $second->getExitCode()];
        $output = 'first: ' . $first->getOutput() . $first->getErrorOutput()
            . ' second: ' . $second->getOutput() . $second->getErrorOutput();

        sort($exitCodes);

        $this->assertSame(
            [0, self::ALREADY_MIGRATED_EXIT_CODE],
            $exitCodes,
            'Exactly one process must migrate the Agency\'s entire batch for real and exactly one must observe no work left. ' . $output
        );

        foreach ([$first, $second] as $process) {
            if ($process->getExitCode() === 0) {
                preg_match('/business_count=(\d+)/', $process->getOutput(), $countMatch);
                $this->assertNotEmpty($countMatch, 'The winning process did not report its business_count: ' . $process->getOutput());
                $this->assertSame('2', $countMatch[1], 'The winner must have migrated BOTH client Businesses in its one Agency-level transaction. ' . $output);

                preg_match('/elapsed_ms=(\d+)/', $process->getOutput(), $match);
                $this->assertNotEmpty($match, 'The winning process did not report its elapsed time: ' . $process->getOutput());
                $this->assertGreaterThanOrEqual(
                    self::MINIMUM_BLOCKED_MILLISECONDS,
                    (int) $match[1],
                    'The winner must have genuinely waited on the locked Agency Workspace row. ' . $output
                );
            }
        }

        // ---- Durable DB-state proof: only ONE Agency batch ever committed ----

        $businessOne = Business::find($clientOneId);
        $businessTwo = Business::find($clientTwoId);

        $this->assertNotSame($agencyWorkspaceId, (int) $businessOne->workspace_id, 'The first Business must have actually moved.');
        $this->assertNotSame($agencyWorkspaceId, (int) $businessTwo->workspace_id, 'The second Business must have actually moved.');
        $this->assertNotSame(
            (int) $businessOne->workspace_id,
            (int) $businessTwo->workspace_id,
            'Each Business gets its OWN independent Client Workspace — never merged.'
        );
        $this->createdWorkspaceIds[] = (int) $businessOne->workspace_id;
        $this->createdWorkspaceIds[] = (int) $businessTwo->workspace_id;

        $this->assertSame(
            $workspaceCountBefore + 2,
            Workspace::count(),
            'Exactly two new Client Workspaces must exist after the race (one per Business, from the single winning Agency batch), never four (no duplicate batch commit).'
        );

        $relationships = AgencyClientWorkspaceRelationship::where('agency_workspace_id', $agencyWorkspaceId)->get();
        $this->assertCount(2, $relationships, 'Exactly two Active relationships must exist (one per migrated Business), never a duplicate set from a second committed batch.');
        $this->assertTrue($relationships->every(fn (AgencyClientWorkspaceRelationship $r) => $r->status === AgencyClientRelationshipStatus::Active));

        $relationshipByClientWorkspace = $relationships->keyBy('client_workspace_id');
        $this->assertTrue($relationshipByClientWorkspace->has((int) $businessOne->workspace_id));
        $this->assertTrue($relationshipByClientWorkspace->has((int) $businessTwo->workspace_id));

        // No contradictory payer conversion: Business One was a legacy
        // `workspace` payer (must convert to pre-consent agency_rebill),
        // Business Two was already a `business` payer (must stay
        // untouched) — proving the winner's batch applied the payer
        // matrix correctly to BOTH, not just one.
        $assignmentOne = BusinessPayerAssignment::where('business_id', $clientOneId)->first();
        $this->assertSame(PayerType::AgencyRebill, $assignmentOne->payer_type);
        $this->assertSame(
            (int) $relationshipByClientWorkspace[(int) $businessOne->workspace_id]->id,
            (int) $assignmentOne->managing_agency_relationship_id,
        );
        $this->assertNull($assignmentOne->agency_rebill_consented_at);
        $this->assertNull($assignmentOne->agency_rebill_consented_by_user_id);

        $assignmentTwo = BusinessPayerAssignment::where('business_id', $clientTwoId)->first();
        $this->assertSame(PayerType::Business, $assignmentTwo->payer_type);
        $this->assertNull($assignmentTwo->managing_agency_relationship_id);
    }

    private function waitForBothChildrenToEnterRun(Process $first, Process $second): bool
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
