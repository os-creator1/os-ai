<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Contract 03 §5 (Slice 4) — the canonical truth table, row by row.
 *
 * Every `(assignment-existence, status, trial_ends_at, grace_started_at,
 * locked_at)` combination the contract names, asserted against the one
 * authority that reads them. The point of covering the whole table rather
 * than the interesting rows is precedence: Suspended over stale timestamps,
 * `locked_at` over a running Grace window, Grace over a trial — each of those
 * is a rule that only shows up when two inputs disagree.
 *
 * Timestamps are written directly onto the assignment here, not through the
 * writers, precisely so this file tests the READER in isolation: it must
 * produce the contract's state for any stored combination, including ones no
 * writer would currently produce.
 */
class CustomerAccountAccessResolverGraceLockedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function platformAdminId(): int
    {
        return (int) User::create([
            'first_name' => 'Platform', 'last_name' => 'Owner',
            'email' => 'platform-owner-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function assignedWorkspace(?WorkspacePlanAssignmentStatus $status = WorkspacePlanAssignmentStatus::Active): Workspace
    {
        $customer = $this->createCustomer();
        $workspace = Workspace::create(['name' => 'Lifecycle Workspace', 'owner_user_id' => $customer->user_id, 'is_active' => true]);

        if ($status !== null) {
            $admin = $this->platformAdminId();
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Fixture assignment.', true, 0);

            if ($status !== WorkspacePlanAssignmentStatus::Active) {
                app(EntitlementManager::class)->changePlanStatus($workspace, $status, $admin, 'Fixture status change.');
            }
        }

        return $workspace->fresh();
    }

    /** Stores a raw lifecycle combination, bypassing the writers on purpose. */
    private function storeLifecycle(Workspace $workspace, array $columns): void
    {
        WorkspacePlanAssignment::query()
            ->where('workspace_id', $workspace->id)
            ->update($columns);
    }

    private function resolve(Workspace $workspace): \App\Library\Entitlement\CustomerAccountAccessDecision
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
    }

    // =====================================================================
    // Row 1 — no assignment row at all
    // =====================================================================

    public function test_row_1_no_assignment_row_is_usable_and_is_not_read_as_a_trial(): void
    {
        $decision = $this->resolve($this->assignedWorkspace(null));

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
        // §5 point 2: a missing row is Unassigned, explicitly NOT Trial.
        $this->assertFalse($decision->isInTrial());
        $this->assertFalse($decision->isInGracePeriod());
        $this->assertSame('usable', $decision->reason);
    }

    // =====================================================================
    // Row 2 — Active + outstanding trial, nothing else set
    // =====================================================================

    public function test_row_2_active_with_a_running_trial_is_usable_with_a_trial_hint(): void
    {
        $workspace = $this->assignedWorkspace();
        $endsAt = now()->addDays(5);
        $this->storeLifecycle($workspace, ['trial_ends_at' => $endsAt]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
        $this->assertTrue($decision->isInTrial());
        $this->assertFalse($decision->isInGracePeriod());
        $this->assertSame('plan_trial', $decision->reason);
        $this->assertSame($endsAt->toDateTimeString(), $decision->trialEndsAt->toDateTimeString());
    }

    public function test_row_2_an_expired_but_unprocessed_trial_reads_identically_to_a_running_one(): void
    {
        $workspace = $this->assignedWorkspace();
        // The real, brief window between natural expiry and the sweep: still
        // Usable, still a trial — never a silent lockout the moment the
        // clock passes.
        $this->storeLifecycle($workspace, ['trial_ends_at' => now()->subHours(2)]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
        $this->assertTrue($decision->isInTrial());
        $this->assertSame('plan_trial', $decision->reason);
    }

    // =====================================================================
    // Row 3 — Active, all three NULL
    // =====================================================================

    public function test_row_3_active_with_no_lifecycle_timestamps_is_plain_active(): void
    {
        $decision = $this->resolve($this->assignedWorkspace());

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
        $this->assertFalse($decision->isInTrial());
        $this->assertFalse($decision->isInGracePeriod());
        $this->assertSame('usable', $decision->reason);
    }

    // =====================================================================
    // Row 4 — Active + Grace running (trial column irrelevant)
    // =====================================================================

    public function test_row_4_active_within_grace_is_usable_with_a_grace_hint(): void
    {
        $workspace = $this->assignedWorkspace();
        $startedAt = now()->subDay();
        $this->storeLifecycle($workspace, ['grace_started_at' => $startedAt]);

        $decision = $this->resolve($workspace);

        // Blueprint §27: Grace keeps FULL access. Only the prompt changes.
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
        $this->assertTrue($decision->isInGracePeriod());
        $this->assertSame('plan_grace', $decision->reason);
        $this->assertSame(
            $startedAt->copy()->addDays(EntitlementManager::GRACE_PERIOD_DAYS)->toDateTimeString(),
            $decision->graceEndsAt->toDateTimeString(),
        );
        $this->assertSame('customer.workspaces.plan.show', $decision->recoveryRouteName);
    }

    public function test_row_4_grace_wins_over_an_outstanding_trial_timestamp(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, [
            'trial_ends_at' => now()->subDay(),
            'grace_started_at' => now()->subHours(6),
        ]);

        $decision = $this->resolve($workspace);

        // The table's "trial_ends_at irrelevant" column, asserted: a trial
        // that ended into Grace must read as Grace, never as a trial.
        $this->assertSame('plan_grace', $decision->reason);
        $this->assertTrue($decision->isInGracePeriod());
        $this->assertFalse($decision->isInTrial());
    }

    // =====================================================================
    // Row 5 — Active + Grace elapsed, locked_at still NULL (defensive)
    // =====================================================================

    public function test_row_5_elapsed_grace_without_a_written_lock_is_still_locked(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 1)]);

        $decision = $this->resolve($workspace);

        // A missed scheduled run must never leave a delinquent account
        // usable. This is the safety net, not the mechanism.
        $this->assertSame(CustomerAccountAccessState::Locked, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertSame('plan_locked', $decision->reason);
    }

    public function test_row_5_grace_is_locked_at_exactly_the_window_boundary(): void
    {
        $workspace = $this->assignedWorkspace();
        // Exactly GRACE_PERIOD_DAYS old: the reader and the sweep predicate
        // (grace_started_at <= now - 3 days) must flip at the same instant,
        // or an account would read Usable while the sweep locks it.
        $this->storeLifecycle($workspace, ['grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS)]);

        $this->assertSame(CustomerAccountAccessState::Locked, $this->resolve($workspace)->state);
    }

    public function test_row_5_grace_one_minute_before_the_boundary_is_still_usable(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, [
            'grace_started_at' => now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS)->addMinute(),
        ]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertTrue($decision->isInGracePeriod());
    }

    // =====================================================================
    // Row 6 — Active + locked_at written
    // =====================================================================

    public function test_row_6_a_written_lock_is_locked_with_a_truthful_billing_recovery_action(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, [
            'grace_started_at' => now()->subDays(4),
            'locked_at' => now()->subDay(),
        ]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Locked, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertSame('plan_locked', $decision->reason);
        $this->assertNotNull($decision->heading);
        $this->assertNotNull($decision->message);
        // A payment genuinely does restore access here, so — unlike
        // Suspended — offering the billing page is truthful.
        $this->assertSame('customer.workspaces.plan.show', $decision->recoveryRouteName);
        $this->assertNotNull($decision->recoveryLabel);
        $this->assertFalse($decision->isInGracePeriod());
        $this->assertFalse($decision->isInTrial());
    }

    public function test_row_6_a_written_lock_wins_over_a_still_running_grace_window(): void
    {
        $workspace = $this->assignedWorkspace();
        $this->storeLifecycle($workspace, [
            'grace_started_at' => now()->subMinutes(5),
            'locked_at' => now(),
        ]);

        $this->assertSame(CustomerAccountAccessState::Locked, $this->resolve($workspace)->state);
    }

    // =====================================================================
    // Rows 7 and 8 — Inactive and Suspended ignore every lifecycle timestamp
    // =====================================================================

    public function test_row_7_inactive_is_unchanged_whatever_the_lifecycle_timestamps_hold(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanAssignmentStatus::Inactive);
        $this->storeLifecycle($workspace, [
            'trial_ends_at' => now()->addDays(10),
            'grace_started_at' => now(),
            'locked_at' => now(),
        ]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::LockedInactive, $decision->state);
        $this->assertSame('plan_inactive', $decision->reason);
    }

    public function test_row_8_suspended_always_wins_over_grace_and_lock_timestamps(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanAssignmentStatus::Suspended);
        $this->storeLifecycle($workspace, [
            'trial_ends_at' => now()->addDays(10),
            'grace_started_at' => now()->subDays(10),
            'locked_at' => now()->subDays(5),
        ]);

        $decision = $this->resolve($workspace);

        // An administrative suspension can never be masked — or softened —
        // by stale payment-lifecycle timestamps.
        $this->assertSame(CustomerAccountAccessState::LockedSuspended, $decision->state);
        $this->assertSame('plan_suspended', $decision->reason);
        $this->assertNull($decision->recoveryRouteName);
    }

    public function test_suspended_is_not_softened_by_a_running_trial(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanAssignmentStatus::Suspended);
        $this->storeLifecycle($workspace, ['trial_ends_at' => now()->addDays(10)]);

        $decision = $this->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::LockedSuspended, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertFalse($decision->isInTrial());
    }

    // =====================================================================
    // The resolver stays read-only
    // =====================================================================

    public function test_resolving_never_writes_a_lifecycle_timestamp(): void
    {
        $workspace = $this->assignedWorkspace();
        $graceStartedAt = now()->subDays(EntitlementManager::GRACE_PERIOD_DAYS + 2);
        $this->storeLifecycle($workspace, ['trial_ends_at' => now()->subDays(9), 'grace_started_at' => $graceStartedAt]);

        $before = WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->first();

        $this->assertSame(CustomerAccountAccessState::Locked, $this->resolve($workspace)->state);

        $after = WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->first();

        // The resolver derived Locked. It must not have PERSISTED it — the
        // durable write is EntitlementManager's job, and a resolver that
        // wrote here would be the second authority the contract forbids.
        $this->assertNull($after->locked_at);
        $this->assertSame($before->trial_ends_at->toDateTimeString(), $after->trial_ends_at->toDateTimeString());
        $this->assertSame($before->grace_started_at->toDateTimeString(), $after->grace_started_at->toDateTimeString());
        $this->assertSame($before->updated_at->toDateTimeString(), $after->updated_at->toDateTimeString());
    }
}
