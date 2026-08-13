<?php

namespace Tests\Feature\Entitlement;

use App\Exceptions\Entitlement\WorkspaceEntitlementBackfillIncompleteException;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillResult;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Mirrors RFC-003's BackfillWorkspacesCommandTest exactly — the command is
 * a thin wrapper with no algorithm of its own.
 */
class BackfillWorkspaceEntitlementsCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function bindMockAction(): Mockery\MockInterface
    {
        $mock = Mockery::mock(WorkspaceEntitlementBackfillV1::class);
        $this->app->instance(WorkspaceEntitlementBackfillV1::class, $mock);

        return $mock;
    }

    // 1. the command is auto-discovered as workspaces:backfill-entitlements.
    public function test_command_is_auto_discovered(): void
    {
        $this->assertArrayHasKey('workspaces:backfill-entitlements', Artisan::all());
    }

    // 2. a successful command returns SUCCESS. Uses the real action (no mock).
    public function test_successful_run_returns_success(): void
    {
        $this->createWorkspace($this->createCustomer()->user);

        $this->artisan('workspaces:backfill-entitlements')->assertExitCode(Command::SUCCESS);
    }

    // 3. successful output contains all three counters. Uses the real action.
    public function test_successful_output_contains_all_three_counters(): void
    {
        $this->createWorkspace($this->createCustomer()->user);

        $this->artisan('workspaces:backfill-entitlements')
            ->expectsOutputToContain('workspaces processed=1')
            ->expectsOutputToContain('assignments created=1')
            ->expectsOutputToContain('remaining unassigned count=0')
            ->assertExitCode(Command::SUCCESS);
    }

    // 4. a real successful run creates the assignment row.
    public function test_successful_run_creates_the_assignment_row(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->artisan('workspaces:backfill-entitlements')->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());
    }

    // 5. a second successful command run is idempotent.
    public function test_second_run_is_idempotent(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->artisan('workspaces:backfill-entitlements')->assertExitCode(Command::SUCCESS);

        $this->artisan('workspaces:backfill-entitlements')
            ->expectsOutputToContain('workspaces processed=0')
            ->expectsOutputToContain('assignments created=0')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());
    }

    // 6. WorkspaceEntitlementBackfillIncompleteException produces FAILURE and actionable error output.
    public function test_incomplete_exception_produces_failure_and_actionable_output(): void
    {
        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()
            ->andThrow(new WorkspaceEntitlementBackfillIncompleteException(3));

        $this->artisan('workspaces:backfill-entitlements')
            ->expectsOutputToContain('3 Workspace(s)')
            ->assertExitCode(Command::FAILURE);
    }

    // 7. unexpected exceptions are not converted into SUCCESS — proven by
    // letting it propagate all the way out of Artisan::call() uncaught.
    public function test_unexpected_exception_is_not_converted_to_success(): void
    {
        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('unexpected boom'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected boom');

        Artisan::call('workspaces:backfill-entitlements');
    }

    // 8. the command delegates to WorkspaceEntitlementBackfillV1 rather than duplicating the algorithm.
    public function test_command_delegates_to_workspace_entitlement_backfill_v1(): void
    {
        $result = new WorkspaceEntitlementBackfillResult(
            workspacesProcessed: 9,
            assignmentsCreated: 8,
            remainingUnassignedCount: 0,
        );

        $mock = $this->bindMockAction();
        $mock->shouldReceive('run')->once()->andReturn($result);

        $this->artisan('workspaces:backfill-entitlements')
            ->expectsOutputToContain('workspaces processed=9')
            ->expectsOutputToContain('assignments created=8')
            ->expectsOutputToContain('remaining unassigned count=0')
            ->assertExitCode(Command::SUCCESS);
    }
}
