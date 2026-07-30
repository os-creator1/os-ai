<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\Workspace\WorkspaceBackfillConflictException;
use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
use App\Library\Workspace\Migration\WorkspaceBackfillResult;
use App\Library\Workspace\Migration\WorkspaceBackfillV1;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Workspace\Support\HistoricalWorkspaceTestCase;

#[Group('historical-m1a')]
class BackfillWorkspacesCommandTest extends HistoricalWorkspaceTestCase
{
    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Legacy',
            'last_name' => 'Customer',
            'email' => 'legacy-' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ], $overrides));
    }

    private function createNullWorkspaceBusiness(int $customerId, array $overrides = []): Business
    {
        return Business::create(array_merge([
            'customer_id' => $customerId,
            'name' => 'Legacy Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function bindMockAction(): Mockery\MockInterface
    {
        $mock = Mockery::mock(WorkspaceBackfillV1::class);
        $this->app->instance(WorkspaceBackfillV1::class, $mock);

        return $mock;
    }

    // 7. the command is auto-discovered as workspaces:backfill.
    public function test_command_is_auto_discovered(): void
    {
        $this->assertArrayHasKey('workspaces:backfill', Artisan::all());
    }

    // 8. a successful command returns SUCCESS. Uses the real action (no mock).
    public function test_successful_run_returns_success(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $this->artisan('workspaces:backfill')->assertExitCode(Command::SUCCESS);
    }

    // 9. successful output contains all five counters. Uses the real action.
    public function test_successful_output_contains_all_five_counters(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $this->artisan('workspaces:backfill')
            ->expectsOutputToContain('groups processed=1')
            ->expectsOutputToContain('workspaces created=1')
            ->expectsOutputToContain('workspaces reused=0')
            ->expectsOutputToContain('businesses assigned=1')
            ->expectsOutputToContain('remaining null count=0')
            ->assertExitCode(Command::SUCCESS);
    }

    // 10. a real successful run updates eligible Business rows.
    public function test_successful_run_updates_eligible_business_rows(): void
    {
        $user = $this->createUser();
        $business = $this->createNullWorkspaceBusiness($user->id);

        $this->artisan('workspaces:backfill')->assertExitCode(Command::SUCCESS);

        $this->assertNotNull(DB::table('businesses')->where('id', $business->id)->value('workspace_id'));
    }

    // 11. a second successful command run is idempotent.
    public function test_second_run_is_idempotent(): void
    {
        $user = $this->createUser();
        $this->createNullWorkspaceBusiness($user->id);

        $this->artisan('workspaces:backfill')->assertExitCode(Command::SUCCESS);

        $this->artisan('workspaces:backfill')
            ->expectsOutputToContain('groups processed=0')
            ->expectsOutputToContain('workspaces created=0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, DB::table('workspaces')->where('owner_user_id', $user->id)->count());
    }

    // 12. WorkspaceBackfillConflictException produces FAILURE and actionable error output.
    public function test_conflict_exception_produces_failure_and_actionable_output(): void
    {
        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()
            ->andThrow(new WorkspaceBackfillConflictException(42, [7, 8]));

        // Uses Artisan::call()/output() rather than $this->artisan(): the
        // latter's expectsOutputToContain() checks each internal
        // SymfonyStyle write call separately, and its error-block
        // rendering can split one long line across several such calls in
        // the PHPUnit console mock (confirmed not to happen in a real
        // terminal run) — Artisan::output() instead returns the fully
        // buffered string, so the exact message can be asserted intact.
        $exitCode = Artisan::call('workspaces:backfill');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('customer_id [42]', $output);
        $this->assertStringContainsString('Workspaces [7, 8]', $output);
    }

    // 13. WorkspaceBackfillIncompleteException produces FAILURE and actionable error output.
    public function test_incomplete_exception_produces_failure_and_actionable_output(): void
    {
        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()
            ->andThrow(new WorkspaceBackfillIncompleteException(5));

        $this->artisan('workspaces:backfill')
            ->expectsOutputToContain('5 Business row(s)')
            ->assertExitCode(Command::FAILURE);
    }

    // 14. unexpected exceptions are not converted into SUCCESS. Proven by
    // letting it propagate all the way out of Artisan::call() uncaught —
    // stronger than checking a non-zero exit code, since it shows the
    // command never even converts it into an exit code at all.
    public function test_unexpected_exception_is_not_converted_to_success(): void
    {
        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('unexpected boom'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected boom');

        Artisan::call('workspaces:backfill');
    }

    // 15. the command delegates to WorkspaceBackfillV1 rather than duplicating the algorithm.
    public function test_command_delegates_to_workspace_backfill_v1(): void
    {
        $result = new WorkspaceBackfillResult(
            groupsProcessed: 9,
            workspacesCreated: 8,
            workspacesReused: 7,
            businessesAssigned: 6,
            remainingNullCount: 0,
        );

        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()->andReturn($result);

        $this->artisan('workspaces:backfill')
            ->expectsOutputToContain('groups processed=9')
            ->expectsOutputToContain('workspaces created=8')
            ->expectsOutputToContain('workspaces reused=7')
            ->expectsOutputToContain('businesses assigned=6')
            ->expectsOutputToContain('remaining null count=0')
            ->assertExitCode(Command::SUCCESS);
    }
}
