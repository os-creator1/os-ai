<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\OpportunityActionExecution;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class OpportunityExecutionClaimConcurrencyTest extends TestCase
{
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseSafety::activeTestDatabase();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        TestDatabaseSafety::activeTestDatabase();
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_two_database_connections_allow_one_claim_and_one_business_effect(): void
    {
        TestDatabaseSafety::activeTestDatabase();
        config()->set('opportunity.enabled', true);
        $business = $this->createBusinessForOpportunities();
        $action = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];
        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);
        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $business->customer);
        $execution = $manager->confirmApproval($opportunity, $business->customer);

        $originalConnection = DB::getDefaultConnection();
        $probeName = 'mysql_opportunity_execution_probe';
        config(['database.connections.'.$probeName => config('database.connections.'.$originalConnection)]);
        DB::purge($probeName);
        $probe = DB::connection($probeName);
        $this->assertNotSame(DB::connection($originalConnection)->getPdo(), $probe->getPdo());
        $this->assertSame(DB::connection($originalConnection)->getDatabaseName(), $probe->getDatabaseName());

        try {
            $probe->beginTransaction();
            DB::setDefaultConnection($probeName);
            $winningAttempt = $manager->beginExecutionAttempt($execution);
            $this->assertNotNull($winningAttempt);
            $this->assertNotNull($winningAttempt->execution->started_at);
            $this->assertNull($business->phone);

            DB::setDefaultConnection($originalConnection);
            $default = DB::connection($originalConnection);
            $timeout = (int) $default->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
            $default->statement('SET SESSION innodb_lock_wait_timeout = 2');
            try {
                $this->expectSecondClaimToWaitOnDatabaseLock($manager, $execution);
            } finally {
                $default->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
            }

            DB::setDefaultConnection($probeName);
            $summary = app(OpportunityActionExecutor::class)->execute($winningAttempt->opportunity, $winningAttempt->execution);
            $manager->recordExecutionResult($winningAttempt->execution, true, $summary);
            $probe->commit();

            DB::setDefaultConnection($originalConnection);
            $this->assertNull($manager->beginExecutionAttempt($execution));
            (new ExecuteOpportunityAction((int) $execution->id))->handle($manager, app(OpportunityActionExecutor::class));
            $this->assertSame('+15551234567', $business->fresh()->phone);
            $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
            $this->assertSame(1, OpportunityActionExecution::query()->where('opportunity_id', $opportunity->id)->count());
            $this->assertSame(1, DB::table('opportunity_transitions')->where('opportunity_id', $opportunity->id)
                ->where('reason_code', 'execution_succeeded')->count());
            $this->assertNotNull($execution->fresh()->started_at);
        } finally {
            DB::setDefaultConnection($originalConnection);
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge($probeName);
            config(['database.connections.'.$probeName => null]);
        }
    }

    private function expectSecondClaimToWaitOnDatabaseLock(OpportunityManager $manager, OpportunityActionExecution $execution): void
    {
        try {
            $manager->beginExecutionAttempt($execution);
            $this->fail('A second connection claimed the pending execution before the first claim committed.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null);
        }
    }
}
