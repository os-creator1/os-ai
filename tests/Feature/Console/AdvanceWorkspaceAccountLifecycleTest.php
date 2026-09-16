<?php

namespace Tests\Feature\Console;

use App\Console\Kernel;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspaceAccessRestored;
use App\Events\Entitlement\WorkspaceEnteredGracePeriod;
use App\Events\Entitlement\WorkspaceLocked;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementTransition;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Contract 03 §6/§7 (Slice 4) — the scheduled command, and the five
 * trial/scheduler invariants the remediation exists to guarantee.
 *
 * The sweeps are the only automatic writers of lifecycle state, so the
 * questions this file answers are the dangerous ones: does an expired trial
 * enter Grace exactly once; can a customer who already paid ever be dragged
 * back into Grace by an old trial date; does re-running the command a second
 * time change anything; does an administrative suspension survive both
 * sweeps.
 */
class AdvanceWorkspaceAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'workspaces:advance-account-lifecycle';

    private function createAdmin(): int
    {
        return (int) User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function assignedWorkspace(?\Carbon\CarbonInterface $trialEndsAt = null): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Lifecycle Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace,
            WorkspacePlanTier::Core,
            $this->createAdmin(),
            'Fixture.',
            true,
            0,
            $trialEndsAt,
        );

        return $workspace->fresh();
    }

    private function assignment(Workspace $workspace): WorkspacePlanAssignment
    {
        return WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->firstOrFail();
    }

    /**
     * Writes lifecycle columns through the repository (not a raw query) so
     * the per-request assignment cache is invalidated exactly as production
     * writes invalidate it.
     */
    private function storeLifecycle(Workspace $workspace, array $columns): void
    {
        app(WorkspacePlanAssignmentRepository::class)->update($this->assignment($workspace), $columns);
    }

    private function countTransitions(Workspace $workspace, WorkspaceEntitlementTransitionType $type): int
    {
        return WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', $type)
            ->count();
    }

    private function runSweep(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
    }

    // =====================================================================
    // Scheduler registration
    // =====================================================================

    private function scheduledEvent(): ?ScheduledEvent
    {
        $kernel = app(Kernel::class);
        $schedule = app(Schedule::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, self::COMMAND)) {
                return $event;
            }
        }

        return null;
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $event = $this->scheduledEvent();

        $this->assertNotNull($event);
        // subscription:check's own billing-state cadence (§3/§7).
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // =====================================================================
    // Sweep 1 — expired trial into Grace
    // =====================================================================

    public function test_sweep_moves_an_expired_trial_into_grace_exactly_once(): void
    {
        $workspace = $this->assignedWorkspace(now()->subHour());

        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertNotNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $assignment->status);
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));

        // Required regression 2: run it again — the window must not restart
        // and no second audit row may appear.
        $graceStartedAt = $assignment->grace_started_at->toDateTimeString();
        $this->runSweep();

        $this->assertSame($graceStartedAt, $this->assignment($workspace)->grace_started_at->toDateTimeString());
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
    }

    public function test_sweep_leaves_a_running_trial_alone(): void
    {
        $workspace = $this->assignedWorkspace(now()->addDays(5));
        $trialEndsAt = $this->assignment($workspace)->trial_ends_at->toDateTimeString();

        $this->runSweep();

        // §13 required test 1: all three columns unchanged, and no lifecycle
        // transition of ANY kind — a sweep that cleared the trial (dropping
        // the customer to plain Active) must fail this, not only one that
        // started Grace.
        $assignment = $this->assignment($workspace);
        $this->assertSame($trialEndsAt, $assignment->trial_ends_at?->toDateTimeString());
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccessRestored));
    }

    public function test_sweep_ignores_a_workspace_that_never_had_a_trial(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->runSweep();

        $this->assertNull($this->assignment($workspace)->grace_started_at);
    }

    public function test_sweep_ignores_an_expired_trial_that_is_already_in_grace(): void
    {
        $workspace = $this->assignedWorkspace(now()->subDay());
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Already handled.');
        $graceStartedAt = $this->assignment($workspace)->grace_started_at->toDateTimeString();

        $this->runSweep();

        $this->assertSame($graceStartedAt, $this->assignment($workspace)->grace_started_at->toDateTimeString());
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
    }

    public function test_sweep_ignores_an_inactive_workspace_with_an_expired_trial(): void
    {
        $workspace = $this->assignedWorkspace(now()->subDay());
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), 'Deactivated.');

        $this->runSweep();

        $this->assertNull($this->assignment($workspace)->grace_started_at);
    }

    // =====================================================================
    // Sweep 2 — elapsed Grace into Locked
    // =====================================================================

    public function test_sweep_locks_a_grace_period_that_has_fully_elapsed(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertNotNull($assignment->locked_at);
        $this->assertNotNull($assignment->grace_started_at);
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));

        // Re-running must not move the lock or add a second audit row.
        $lockedAt = $assignment->locked_at->toDateTimeString();
        $this->runSweep();

        $this->assertSame($lockedAt, $this->assignment($workspace)->locked_at->toDateTimeString());
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
    }

    public function test_sweep_locks_at_exactly_the_grace_boundary(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS)]);

        $this->runSweep();

        $this->assertNotNull($this->assignment($workspace)->locked_at);
    }

    public function test_sweep_leaves_a_grace_period_still_inside_its_window_alone(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDay()]);

        $this->runSweep();

        $this->assertNull($this->assignment($workspace)->locked_at);
        // And the customer still has full access while it runs.
        $this->assertFalse(app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked());
    }

    public function test_a_workspace_is_never_advanced_twice_in_one_run(): void
    {
        $workspace = $this->assignedWorkspace(now()->subDays(30));

        $this->runSweep();

        // Sweep 1 requires grace_started_at IS NULL, sweep 2 requires it IS
        // NOT NULL, so the Workspace sweep 1 just moved into Grace cannot
        // also be locked by sweep 2 in the same invocation.
        $assignment = $this->assignment($workspace);
        $this->assertNotNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
    }

    // =====================================================================
    // Required regression 1 — an early conversion can never fall into Grace
    // =====================================================================

    public function test_a_trial_converted_before_expiry_never_enters_grace_afterwards(): void
    {
        $workspace = $this->assignedWorkspace(now()->addDays(7));

        app(EntitlementManager::class)->recoverAccess($workspace, $this->createAdmin(), 'Converted early.');
        $this->assertNull($this->assignment($workspace)->trial_ends_at);

        // Well past the original trial expiry, and several sweeps later.
        $this->travel(30)->days();
        $this->runSweep();
        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertNull($assignment->trial_ends_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        $this->assertSame(CustomerAccountAccessState::Usable, app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->state);
    }

    // =====================================================================
    // Required regression 3 — Trial -> Grace -> payment -> never again
    // =====================================================================

    public function test_a_workspace_recovered_out_of_grace_never_re_enters_grace(): void
    {
        $workspace = $this->assignedWorkspace(now()->subHour());

        // Trial expires into Grace.
        $this->runSweep();
        $this->assertNotNull($this->assignment($workspace)->grace_started_at);

        // The customer pays.
        app(EntitlementManager::class)->recoverAccess($workspace, $this->createAdmin(), 'Payment received.');
        $recovered = $this->assignment($workspace);
        $this->assertNull($recovered->trial_ends_at);
        $this->assertNull($recovered->grace_started_at);
        $this->assertNull($recovered->locked_at);

        // The old trial date is now weeks in the past. Without the cleared
        // column, sweep 1 would drop this paying customer straight back into
        // Grace on the very next run — the exact contradiction this contract
        // exists to close.
        $this->travel(30)->days();
        $this->runSweep();
        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        $this->assertSame(CustomerAccountAccessState::Usable, app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->state);
    }

    public function test_a_workspace_recovered_out_of_a_lock_never_re_enters_grace_or_lock(): void
    {
        $workspace = $this->assignedWorkspace(now()->subDays(10));
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $this->runSweep();
        $this->assertNotNull($this->assignment($workspace)->locked_at);

        app(EntitlementManager::class)->recoverAccess($workspace, $this->createAdmin(), 'Payment received.');

        $this->travel(30)->days();
        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertNull($assignment->trial_ends_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertFalse(app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked());
    }

    // =====================================================================
    // Required regression 4 — whole-run idempotency
    // =====================================================================

    public function test_running_the_whole_sweep_twice_changes_nothing_the_second_time(): void
    {
        $expiredTrial = $this->assignedWorkspace(now()->subDay());
        $elapsedGrace = $this->assignedWorkspace();
        $this->storeLifecycle($elapsedGrace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 2)]);
        $healthy = $this->assignedWorkspace();

        $this->runSweep();

        // The first run must actually have advanced both candidates, or an
        // unchanged second run would prove nothing.
        $this->assertNotNull($this->assignment($expiredTrial)->grace_started_at);
        $this->assertNotNull($this->assignment($elapsedGrace)->locked_at);
        $this->assertNull($this->assignment($healthy)->grace_started_at);

        $snapshot = WorkspacePlanAssignment::query()
            ->orderBy('workspace_id')
            ->get(['workspace_id', 'status', 'trial_ends_at', 'grace_started_at', 'locked_at'])
            ->toJson();
        $transitionCount = WorkspaceEntitlementTransition::query()->count();

        // Zero writes, zero transition rows AND zero events on the second
        // run — the contract requires all three, so fake the events between
        // the runs rather than only counting rows.
        Event::fake([WorkspaceEnteredGracePeriod::class, WorkspaceLocked::class, WorkspaceAccessRestored::class]);

        $this->runSweep();

        Event::assertNotDispatched(WorkspaceEnteredGracePeriod::class);
        Event::assertNotDispatched(WorkspaceLocked::class);
        Event::assertNotDispatched(WorkspaceAccessRestored::class);
        $this->assertSame($snapshot, WorkspacePlanAssignment::query()
            ->orderBy('workspace_id')
            ->get(['workspace_id', 'status', 'trial_ends_at', 'grace_started_at', 'locked_at'])
            ->toJson());
        $this->assertSame($transitionCount, WorkspaceEntitlementTransition::query()->count());
        $this->assertNull($this->assignment($healthy)->grace_started_at);
    }

    // =====================================================================
    // Required regression 5 — Suspended precedence
    // =====================================================================

    public function test_a_suspended_workspace_is_never_advanced_by_either_sweep(): void
    {
        $workspace = $this->assignedWorkspace(now()->subDays(20));
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 5)]);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspended.');

        $this->runSweep();

        $assignment = $this->assignment($workspace);
        $this->assertSame(WorkspacePlanAssignmentStatus::Suspended, $assignment->status);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
        // And the customer still reads as suspended, not locked-for-payment.
        $this->assertSame(
            CustomerAccountAccessState::LockedSuspended,
            app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->state,
        );
    }

    // =====================================================================
    // Per-Workspace isolation (§7) and reporting
    // =====================================================================

    public function test_one_unadvanceable_workspace_does_not_stop_the_rest_of_the_sweep(): void
    {
        $blocked = $this->assignedWorkspace(now()->subDay());
        $healthy = $this->assignedWorkspace(now()->subDay());

        // The one failure mode the sweep can actually hit in production: the
        // Workspace row cannot be locked when the writer reaches it.
        $real = app(WorkspaceRepository::class);
        $blockedId = (int) $blocked->id;
        $mock = Mockery::mock(WorkspaceRepository::class);
        $mock->shouldReceive('findById')->andReturnUsing(fn (int $id) => $real->findById($id));
        $mock->shouldReceive('findForUpdate')->andReturnUsing(
            fn (int $id) => $id === $blockedId ? null : $real->findForUpdate($id),
        );
        $this->instance(WorkspaceRepository::class, $mock);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Expired trials moved into grace: 1 (no longer eligible: 0, failed: 1).')
            ->assertSuccessful();

        $this->assertNull($this->assignment($blocked)->grace_started_at);
        $this->assertNotNull($this->assignment($healthy)->grace_started_at);
    }

    public function test_the_command_reports_both_sweeps_when_there_is_nothing_to_do(): void
    {
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Expired trials moved into grace: 0 (no longer eligible: 0, failed: 0).')
            ->expectsOutputToContain('Elapsed grace periods locked: 0 (no longer eligible: 0, failed: 0).')
            ->assertSuccessful();
    }

    public function test_both_automatic_transitions_record_the_contract_reason_strings(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class, WorkspaceLocked::class]);
        $expiredTrial = $this->assignedWorkspace(now()->subDay());
        $elapsedGrace = $this->assignedWorkspace();
        $this->storeLifecycle($elapsedGrace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $this->runSweep();

        // §6 case B and case D's exact audit text, with the null system actor.
        $grace = WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $expiredTrial->id)
            ->where('transition_type', WorkspaceEntitlementTransitionType::GraceStarted)
            ->sole();
        $this->assertSame('Trial ended without conversion', $grace->reason);
        $this->assertNull($grace->actor_user_id);

        $lock = WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $elapsedGrace->id)
            ->where('transition_type', WorkspaceEntitlementTransitionType::AccountLocked)
            ->sole();
        $this->assertSame('Grace period elapsed without payment', $lock->reason);
        $this->assertNull($lock->actor_user_id);

        Event::assertDispatched(
            WorkspaceEnteredGracePeriod::class,
            fn (WorkspaceEnteredGracePeriod $event): bool => $event->workspaceId === (int) $expiredTrial->id
                && $event->actorUserId === null
                && $event->reason === 'Trial ended without conversion',
        );
        Event::assertDispatched(
            WorkspaceLocked::class,
            fn (WorkspaceLocked $event): bool => $event->workspaceId === (int) $elapsedGrace->id
                && $event->actorUserId === null
                && $event->reason === 'Grace period elapsed without payment',
        );
    }

    // =====================================================================
    // The candidate list goes stale mid-run (§7) — a customer who pays
    // between the sweep's query and its write must not be advanced
    // =====================================================================

    public function test_a_trial_converted_after_the_candidate_query_is_not_moved_into_grace(): void
    {
        $manager = app(EntitlementManager::class);
        $workspace = $this->assignedWorkspace(now()->subHour());

        // The sweep's candidate list is read first...
        $this->assertContains((int) $workspace->id, $manager->findWorkspaceIdsWithExpiredOutstandingTrial());

        // ...then the conversion commits before the sweep reaches this row.
        $manager->recoverAccess($workspace, $this->createAdmin(), 'Converted.');

        $advanced = $manager->advanceExpiredTrialIntoGrace($workspace, 'Trial ended without conversion');

        // Re-checked under the lock: trial_ends_at is now NULL, so §7 sweep
        // 1's predicate no longer holds and nothing is written.
        $this->assertFalse($advanced);
        $this->assertNull($this->assignment($workspace)->grace_started_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
    }

    public function test_a_grace_period_recovered_after_the_candidate_query_is_not_locked(): void
    {
        $manager = app(EntitlementManager::class);
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $this->assertContains((int) $workspace->id, $manager->findWorkspaceIdsWithElapsedGracePeriod());

        $manager->recoverAccess($workspace, $this->createAdmin(), 'Payment received.');

        $locked = $manager->lockElapsedGracePeriod($workspace, 'Grace period elapsed without payment');

        $this->assertFalse($locked);
        $this->assertNull($this->assignment($workspace)->locked_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
        $this->assertFalse(app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked());
    }

    public function test_a_grace_window_not_yet_elapsed_at_lock_time_is_not_locked(): void
    {
        $manager = app(EntitlementManager::class);
        $workspace = $this->assignedWorkspace();
        // Still inside the window, so only the elapsed-time clause of the
        // under-lock re-check can refuse it — the other clauses all hold.
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS)->addHour()]);

        $this->assertFalse($manager->lockElapsedGracePeriod($workspace, 'Grace period elapsed without payment'));
        $this->assertNull($this->assignment($workspace)->locked_at);
        $this->assertSame(0, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
    }

    /**
     * Fix (b), for the sweep steps themselves: another process converts the
     * trial after this console process has already memoized the assignment.
     * The raw write deliberately bypasses the repository so the memo is NOT
     * invalidated — a sweep step deciding on that memo would still see an
     * expired trial and push a paying customer into Grace.
     */
    public function test_a_sweep_step_decides_on_the_current_row_not_a_memoized_one(): void
    {
        $manager = app(EntitlementManager::class);
        $converted = $this->assignedWorkspace(now()->subHour());
        $recovered = $this->assignedWorkspace();
        $this->storeLifecycle($recovered, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $manager->getWorkspaceEntitlementSummary($converted->fresh());
        $manager->getWorkspaceEntitlementSummary($recovered->fresh());

        WorkspacePlanAssignment::query()->where('workspace_id', $converted->id)->update(['trial_ends_at' => null]);
        WorkspacePlanAssignment::query()->where('workspace_id', $recovered->id)->update(['grace_started_at' => null]);

        $this->assertFalse($manager->advanceExpiredTrialIntoGrace($converted, 'Trial ended without conversion'));
        $this->assertFalse($manager->lockElapsedGracePeriod($recovered, 'Grace period elapsed without payment'));
        $this->assertNull($this->assignment($converted)->grace_started_at);
        $this->assertNull($this->assignment($recovered)->locked_at);
    }

    public function test_a_candidate_suspended_mid_run_is_reported_as_no_longer_eligible_not_failed(): void
    {
        $manager = app(EntitlementManager::class);
        $workspace = $this->assignedWorkspace(now()->subHour());
        $this->assertContains((int) $workspace->id, $manager->findWorkspaceIdsWithExpiredOutstandingTrial());

        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspended.');

        $this->assertFalse($manager->advanceExpiredTrialIntoGrace($workspace, 'Trial ended without conversion'));
        $this->assertNull($this->assignment($workspace)->grace_started_at);
    }

    public function test_a_payment_that_lands_while_the_command_is_running_is_respected_end_to_end(): void
    {
        $paysMidRun = $this->assignedWorkspace(now()->subHour());
        $doesNotPay = $this->assignedWorkspace(now()->subHour());
        $admin = $this->createAdmin();

        // The command has already read its candidate list (both Workspaces)
        // when it goes to load $paysMidRun; the payment commits at exactly
        // that moment, before the writer takes its lock.
        $real = app(WorkspaceRepository::class);
        $payingId = (int) $paysMidRun->id;
        $paid = false;
        $mock = Mockery::mock(WorkspaceRepository::class);
        $mock->shouldReceive('findById')->andReturnUsing(function (int $id) use ($real, $payingId, $admin, &$paid) {
            $workspace = $real->findById($id);

            if ($id === $payingId && ! $paid) {
                $paid = true;
                app(EntitlementManager::class)->recoverAccess($workspace, $admin, 'Card charged mid-run.');
            }

            return $workspace;
        });
        $mock->shouldReceive('findForUpdate')->andReturnUsing(fn (int $id) => $real->findForUpdate($id));
        $this->instance(WorkspaceRepository::class, $mock);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Expired trials moved into grace: 1 (no longer eligible: 1, failed: 0).')
            ->assertSuccessful();

        $this->assertTrue($paid);
        $paying = $this->assignment($paysMidRun);
        $this->assertNull($paying->trial_ends_at);
        $this->assertNull($paying->grace_started_at);
        $this->assertSame(0, $this->countTransitions($paysMidRun, WorkspaceEntitlementTransitionType::GraceStarted));
        $this->assertNotNull($this->assignment($doesNotPay)->grace_started_at);
    }

    // =====================================================================
    // End-to-end: what the customer actually sees at each step
    // =====================================================================

    public function test_the_full_sweep_driven_lifecycle_is_visible_through_the_resolver(): void
    {
        $resolver = app(CustomerAccountAccessResolver::class);
        $workspace = $this->assignedWorkspace(now()->addDay());

        // Trial — usable, with the trial hint.
        $decision = $resolver->resolve($workspace->fresh());
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertTrue($decision->isInTrial());

        // The trial runs out and the sweep moves it into Grace: still usable.
        $this->travel(2)->days();
        $this->runSweep();
        $decision = $resolver->resolve($workspace->fresh());
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertTrue($decision->isInGracePeriod());
        $this->assertFalse($decision->isLocked());
        $this->assertNotNull($this->assignment($workspace)->grace_started_at);

        // Grace elapses and the next sweep locks it. Assert the durable
        // write, not only the resolver: after this much time the resolver's
        // defensive derivation would read Locked even if the sweep had done
        // nothing.
        $this->travel(EntitlementManager::GRACE_PERIOD_DAYS + 1)->days();
        $this->runSweep();
        $this->assertNotNull($this->assignment($workspace)->locked_at);
        $this->assertSame(1, $this->countTransitions($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
        $decision = $resolver->resolve($workspace->fresh());
        $this->assertSame(CustomerAccountAccessState::Locked, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertSame('plan_locked', $decision->reason);

        // The customer pays: immediate unlock, clean Active, nothing left
        // outstanding.
        app(EntitlementManager::class)->recoverAccess($workspace, $this->createAdmin(), 'Payment received.');
        $decision = $resolver->resolve($workspace->fresh());
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isInTrial());
        $this->assertFalse($decision->isInGracePeriod());

        // And no later sweep can undo that: a command run inserted here
        // makes zero further writes for this Workspace.
        $recoveredAt = $this->assignment($workspace)->updated_at->toDateTimeString();
        $transitionCount = WorkspaceEntitlementTransition::query()->where('workspace_id', $workspace->id)->count();

        $this->travel(30)->days();
        $this->runSweep();

        $this->assertSame($recoveredAt, $this->assignment($workspace)->updated_at->toDateTimeString());
        $this->assertSame($transitionCount, WorkspaceEntitlementTransition::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(CustomerAccountAccessState::Usable, $resolver->resolve($workspace->fresh())->state);

        // Finally, the canonical status authority closes the account (§6
        // case F) — no fourth lifecycle method, and the customer reads as
        // Inactive rather than locked-for-payment.
        app(EntitlementManager::class)->changePlanStatus(
            $workspace,
            WorkspacePlanAssignmentStatus::Inactive,
            $this->createAdmin(),
            'Closed after the recoverable window.',
        );

        $this->assertSame(CustomerAccountAccessState::LockedInactive, $resolver->resolve($workspace->fresh())->state);
    }
}
