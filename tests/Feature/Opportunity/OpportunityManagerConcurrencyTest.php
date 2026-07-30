<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OpportunityRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Genuine second-MySQL-connection row-lock serialization proof for
 * OpportunityManager::beginRun() (RFC-002 §25, Phase 2C.3). Deliberately
 * does NOT use RefreshDatabase/DatabaseTransactions: those wrap the default
 * connection's writes in an uncommitted transaction, which a genuinely
 * separate PDO connection would never see. Every fixture row here is a
 * real, committed write, explicitly deleted in reverse foreign-key order in
 * a finally block regardless of pass/fail.
 */
class OpportunityManagerConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_opportunity_lock_probe';

    private const LOCK_WAIT_TIMEOUT_SECONDS = 2;

    private const MAX_ELAPSED_SECONDS = 8.0;

    private const MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE = 1205;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_business_row_lock_serializes_begin_run(): void
    {
        $probe = $this->setUpProbeConnection();
        $fixture = null;

        try {
            $fixture = $this->createFixtureBusiness();
            $business = $fixture['business'];

            $this->assertConnectionsAreGenuinelyDistinct($probe);

            $probe->beginTransaction();

            try {
                // Genuine row lock on the Business row, held open on the
                // probe connection — never released until rollBack() below.
                $probe->select('SELECT * FROM businesses WHERE id = ? FOR UPDATE', [$business->id]);

                $caught = null;
                $start = microtime(true);

                $this->withShortLockWaitTimeout(function () use ($business, &$caught) {
                    try {
                        app(OpportunityManager::class)->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
                    } catch (QueryException $e) {
                        $caught = $e;
                    }
                });

                $elapsed = microtime(true) - $start;

                $this->assertLessThan(
                    self::MAX_ELAPSED_SECONDS,
                    $elapsed,
                    'beginRun() did not fail within the bounded lock-wait window — it may have hung.'
                );
                $this->assertNotNull(
                    $caught,
                    'Expected beginRun() to fail with a MySQL lock-wait-timeout QueryException while the Business row was locked by another connection.'
                );
                $this->assertSame(self::MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE, $caught->errorInfo[1] ?? null);

                $this->assertSame(0, OpportunityRun::where('business_id', $business->id)->count());
            } finally {
                $probe->rollBack();
            }
        } finally {
            if ($fixture !== null) {
                $this->cleanUpFixture($fixture);
            }

            $this->tearDownProbeConnection();
        }
    }

    public function test_run_lock_serializes_begin_run_after_business_is_available(): void
    {
        $probe = $this->setUpProbeConnection();
        $fixture = null;
        $runIds = [];

        try {
            $fixture = $this->createFixtureBusiness();
            $business = $fixture['business'];

            $run = OpportunityRun::create([
                'business_id' => $business->id,
                'worker_key' => OpportunityWorkerKey::BusinessAdvisor->value,
                'producer_version' => 1,
                'status' => OpportunityRunStatus::Running->value,
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $runIds[] = $run->id;
            $originalHeartbeat = $run->heartbeat_at;

            $this->assertConnectionsAreGenuinelyDistinct($probe);

            // The Business row is deliberately left unlocked — beginRun()
            // must acquire it immediately and only then block on the run lock.
            $probe->beginTransaction();

            try {
                $probe->select('SELECT * FROM opportunity_runs WHERE id = ? FOR UPDATE', [$run->id]);

                $caught = null;
                $start = microtime(true);

                $this->withShortLockWaitTimeout(function () use ($business, &$caught) {
                    try {
                        app(OpportunityManager::class)->beginRun($business, OpportunityWorkerKey::BusinessAdvisor, 1);
                    } catch (QueryException $e) {
                        $caught = $e;
                    }
                });

                $elapsed = microtime(true) - $start;

                $this->assertLessThan(
                    self::MAX_ELAPSED_SECONDS,
                    $elapsed,
                    'beginRun() did not fail within the bounded lock-wait window — it may have hung.'
                );
                $this->assertNotNull(
                    $caught,
                    'Expected beginRun() to fail with a MySQL lock-wait-timeout QueryException (not RunAlreadyActiveException) while the running run was locked by another connection.'
                );
                $this->assertSame(self::MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE, $caught->errorInfo[1] ?? null);

                $run->refresh();
                $this->assertSame(OpportunityRunStatus::Running, $run->status);
                $this->assertTrue($originalHeartbeat->equalTo($run->heartbeat_at));
                $this->assertNull($run->completed_at);
                $this->assertNull($run->abandoned_at);
                $this->assertNull($run->reason_code);
                $this->assertSame(1, OpportunityRun::where('business_id', $business->id)->count());
            } finally {
                $probe->rollBack();
            }
        } finally {
            if ($fixture !== null) {
                $this->cleanUpFixture($fixture, $runIds);
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
     * Mirrors CreatesBusinessTestData/CreatesOpportunityTestData's minimal
     * User -> Customer -> Business shape exactly, but — since this test
     * cannot rely on RefreshDatabase — every created row is returned here so
     * the caller can delete it explicitly afterward.
     *
     * @return array{business: Business, customer: Customer, user: User, workspace: Workspace}
     */
    private function createFixtureBusiness(): array
    {
        $user = User::create([
            'first_name' => 'Concurrency',
            'last_name' => 'Test',
            'email' => 'concurrency-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        $customer = Customer::create(['user_id' => $user->id]);

        $workspace = Workspace::create([
            'name' => 'Concurrency Test Workspace',
            'owner_user_id' => $user->id,
            'is_active' => true,
        ]);

        // Constructed the same way EloquentBusinessRepository::createForCustomerInWorkspace()
        // does: is_primary/status/workspace_id are set as direct properties, not mass-assigned.
        $business = new Business([
            'name' => 'Concurrency Test Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);
        $business->customer_id = $user->id;
        $business->workspace_id = $workspace->id;
        $business->is_primary = true;
        $business->status = BusinessStatus::Draft;
        $business->save();

        return ['business' => $business, 'customer' => $customer, 'user' => $user, 'workspace' => $workspace];
    }

    /**
     * @param  array{business: Business, customer: Customer, user: User, workspace: Workspace}  $fixture
     * @param  array<int, int>  $runIds
     */
    private function cleanUpFixture(array $fixture, array $runIds = []): void
    {
        if ($runIds !== []) {
            OpportunityRun::whereIn('id', $runIds)->delete();
        }

        $fixture['business']->delete();
        $fixture['workspace']->delete();
        $fixture['customer']->delete();
        $fixture['user']->delete();
    }
}
