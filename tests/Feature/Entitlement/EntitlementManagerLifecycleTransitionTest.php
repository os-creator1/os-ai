<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspaceAccessRestored;
use App\Events\Entitlement\WorkspaceEnteredGracePeriod;
use App\Events\Entitlement\WorkspaceLocked;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementTransition;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Contract 03 §5/§6 (Slice 4) — the three lifecycle WRITERS.
 *
 * These are the only methods that may ever write trial_ends_at,
 * grace_started_at or locked_at, so this file pins the properties the rest of
 * the slice depends on: an Active-only target, one audit row and one event per
 * real change, no audit row or event for a no-op, a nullable actor for the
 * scheduled system path but a real administrator check for a human one — and,
 * above all, that recoverAccess() clears all three columns in one write.
 */
class EntitlementManagerLifecycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return (int) User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return (int) User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function workspace(): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        return Workspace::create(['name' => 'Lifecycle Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
    }

    private function assignedWorkspace(?\Carbon\CarbonInterface $trialEndsAt = null): Workspace
    {
        $workspace = $this->workspace();
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

    private function transitionsOfType(Workspace $workspace, WorkspaceEntitlementTransitionType $type): \Illuminate\Support\Collection
    {
        return WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', $type)
            ->get();
    }

    public function test_the_grace_window_is_exactly_three_days(): void
    {
        // Blueprint §27 / Contract 03 §7's `NOW() - INTERVAL 3 DAY`. Every
        // other boundary test is expressed relative to this constant, so
        // without this literal pin the window could silently become 30 days
        // with a fully green suite.
        $this->assertSame(3, EntitlementManager::GRACE_PERIOD_DAYS);
    }

    // =====================================================================
    // assignFirstPlan()'s new optional trailing trial parameter (§5, case A)
    // =====================================================================

    public function test_assign_first_plan_without_a_trial_leaves_every_lifecycle_column_null(): void
    {
        $assignment = $this->assignment($this->assignedWorkspace());

        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $assignment->status);
        $this->assertNull($assignment->trial_ends_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
    }

    public function test_assign_first_plan_persists_a_granted_trial_expiry(): void
    {
        $endsAt = now()->addDays(14);

        $assignment = $this->assignment($this->assignedWorkspace($endsAt));

        // Still plain Active — a trial is not a fourth status (Addendum §7).
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $assignment->status);
        $this->assertSame($endsAt->toDateTimeString(), $assignment->trial_ends_at->toDateTimeString());
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
    }

    // =====================================================================
    // enterGracePeriod() — §6 case B/C
    // =====================================================================

    public function test_enter_grace_period_writes_the_timestamp_one_transition_and_one_event(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class]);
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();

        $updated = app(EntitlementManager::class)->enterGracePeriod($workspace, $admin, 'Renewal failed.');

        $this->assertNotNull($updated->grace_started_at);
        $this->assertNull($updated->locked_at);
        // The base status is untouched: Grace lives in the timestamps.
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $updated->status);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $this->assignment($workspace)->status);

        $transitions = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted);
        $this->assertCount(1, $transitions);
        $this->assertSame($admin, (int) $transitions->first()->actor_user_id);
        $this->assertSame('Renewal failed.', $transitions->first()->reason);

        Event::assertDispatched(
            WorkspaceEnteredGracePeriod::class,
            fn (WorkspaceEnteredGracePeriod $event): bool => $event->workspaceId === (int) $workspace->id
                && $event->actorUserId === $admin
                && $event->reason === 'Renewal failed.',
        );
    }

    public function test_enter_grace_period_accepts_a_null_actor_as_the_trusted_system_path(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class]);
        $workspace = $this->assignedWorkspace();

        $updated = app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Trial ended without conversion');

        $this->assertNotNull($updated->grace_started_at);
        $transition = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted)->first();
        // A null actor is recorded as a null actor — never a fake sentinel id.
        $this->assertNull($transition->actor_user_id);
        Event::assertDispatched(
            WorkspaceEnteredGracePeriod::class,
            fn (WorkspaceEnteredGracePeriod $event): bool => $event->actorUserId === null,
        );
    }

    public function test_enter_grace_period_is_idempotent_and_never_extends_the_window(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class]);
        $workspace = $this->assignedWorkspace();

        $first = app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'First failure.');
        $startedAt = $first->grace_started_at->toDateTimeString();

        $second = app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Retry failed too.');

        // Re-entering would silently push the lock further away on every
        // failed retry — the exact bug that would stop a delinquent account
        // ever locking.
        $this->assertSame($startedAt, $second->grace_started_at->toDateTimeString());
        $this->assertCount(1, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        Event::assertDispatchedTimes(WorkspaceEnteredGracePeriod::class, 1);
    }

    public function test_enter_grace_period_is_a_no_op_on_an_already_locked_workspace(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class]);
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Locked.');

        $updated = app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Too late.');

        $this->assertNotNull($updated->locked_at);
        $this->assertNull($updated->grace_started_at);
        $this->assertCount(0, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        Event::assertNotDispatched(WorkspaceEnteredGracePeriod::class);
    }

    public function test_enter_grace_period_refuses_a_suspended_workspace(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspended.');

        $this->expectException(SuspendedWorkspacePlanException::class);
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Payment failed.');
    }

    public function test_enter_grace_period_refuses_an_inactive_workspace(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), 'Deactivated.');

        $this->expectException(InactiveWorkspacePlanException::class);
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Payment failed.');
    }

    public function test_enter_grace_period_refuses_an_unassigned_workspace(): void
    {
        $this->expectException(WorkspacePlanUnassignedException::class);
        app(EntitlementManager::class)->enterGracePeriod($this->workspace(), null, 'Payment failed.');
    }

    public function test_enter_grace_period_denies_a_non_administrator_actor(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->enterGracePeriod($workspace, $this->createNonAdmin(), 'Payment failed.');
    }

    public function test_a_denied_actor_writes_nothing(): void
    {
        $workspace = $this->assignedWorkspace();

        try {
            app(EntitlementManager::class)->enterGracePeriod($workspace, $this->createNonAdmin(), 'Payment failed.');
            $this->fail('A non-administrator actor must not be able to start a grace period.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertNull($this->assignment($workspace)->grace_started_at);
        $this->assertCount(0, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
    }

    // =====================================================================
    // lockForNonPayment() — §6 case D
    // =====================================================================

    public function test_lock_for_non_payment_writes_the_lock_one_transition_and_one_event(): void
    {
        Event::fake([WorkspaceLocked::class]);
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Payment failed.');
        $graceStartedAt = $this->assignment($workspace)->grace_started_at->toDateTimeString();

        $updated = app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Grace period elapsed without payment');

        $this->assertNotNull($updated->locked_at);
        // grace_started_at is the audit of WHY this lock exists; clearing it
        // would erase that. Only recoverAccess() clears either column.
        $this->assertSame($graceStartedAt, $updated->grace_started_at->toDateTimeString());
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $updated->status);

        $transitions = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccountLocked);
        $this->assertCount(1, $transitions);
        $this->assertSame('Grace period elapsed without payment', $transitions->first()->reason);
        Event::assertDispatched(
            WorkspaceLocked::class,
            fn (WorkspaceLocked $event): bool => $event->workspaceId === (int) $workspace->id && $event->actorUserId === null,
        );
    }

    public function test_lock_for_non_payment_records_an_administrator_actor_on_the_audit_row_and_event(): void
    {
        Event::fake([WorkspaceLocked::class]);
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();

        app(EntitlementManager::class)->lockForNonPayment($workspace, $admin, 'Locked manually after a failed renewal.');

        $transition = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccountLocked)->first();
        $this->assertSame($admin, (int) $transition->actor_user_id);
        $this->assertSame('Locked manually after a failed renewal.', $transition->reason);
        Event::assertDispatched(
            WorkspaceLocked::class,
            fn (WorkspaceLocked $event): bool => $event->workspaceId === (int) $workspace->id
                && $event->actorUserId === $admin
                && $event->reason === 'Locked manually after a failed renewal.',
        );
    }

    public function test_lock_for_non_payment_is_idempotent_and_never_moves_the_lock_forward(): void
    {
        Event::fake([WorkspaceLocked::class]);
        $workspace = $this->assignedWorkspace();

        $first = app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Locked.');
        $lockedAt = $first->locked_at->toDateTimeString();

        $second = app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Locked again.');

        $this->assertSame($lockedAt, $second->locked_at->toDateTimeString());
        $this->assertCount(1, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccountLocked));
        Event::assertDispatchedTimes(WorkspaceLocked::class, 1);
    }

    public function test_lock_for_non_payment_refuses_a_suspended_workspace(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspended.');

        $this->expectException(SuspendedWorkspacePlanException::class);
        app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Grace elapsed.');
    }

    public function test_lock_for_non_payment_refuses_an_inactive_workspace(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), 'Deactivated.');

        $this->expectException(InactiveWorkspacePlanException::class);
        app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Grace elapsed.');
    }

    public function test_lock_for_non_payment_refuses_an_unassigned_workspace(): void
    {
        $this->expectException(WorkspacePlanUnassignedException::class);
        app(EntitlementManager::class)->lockForNonPayment($this->workspace(), null, 'Grace elapsed.');
    }

    public function test_lock_for_non_payment_denies_a_non_administrator_actor(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->lockForNonPayment($workspace, $this->createNonAdmin(), 'Grace elapsed.');
    }

    // =====================================================================
    // recoverAccess() — §6 case E, and the contract's central invariant
    // =====================================================================

    public function test_recover_access_clears_all_three_lifecycle_timestamps_in_one_write(): void
    {
        Event::fake([WorkspaceAccessRestored::class]);
        $workspace = $this->assignedWorkspace(now()->subDays(10));
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Trial ended without conversion');
        app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Grace period elapsed without payment');

        $before = $this->assignment($workspace);
        $this->assertNotNull($before->trial_ends_at);
        $this->assertNotNull($before->grace_started_at);
        $this->assertNotNull($before->locked_at);

        $admin = $this->createAdmin();
        $updated = app(EntitlementManager::class)->recoverAccess($workspace, $admin, 'Payment received.');

        // All three, together — never a partial clear.
        $this->assertNull($updated->trial_ends_at);
        $this->assertNull($updated->grace_started_at);
        $this->assertNull($updated->locked_at);

        $persisted = $this->assignment($workspace);
        $this->assertNull($persisted->trial_ends_at);
        $this->assertNull($persisted->grace_started_at);
        $this->assertNull($persisted->locked_at);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $persisted->status);

        $transitions = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccessRestored);
        $this->assertCount(1, $transitions);
        $this->assertSame($admin, (int) $transitions->first()->actor_user_id);
        Event::assertDispatched(
            WorkspaceAccessRestored::class,
            fn (WorkspaceAccessRestored $event): bool => $event->workspaceId === (int) $workspace->id
                && $event->reason === 'Payment received.',
        );
    }

    public function test_recover_access_converts_an_early_trial_without_touching_anything_else(): void
    {
        $workspace = $this->assignedWorkspace(now()->addDays(9));

        $updated = app(EntitlementManager::class)->recoverAccess($workspace, $this->createAdmin(), 'Converted early.');

        // §6 case E's "one writer, both cases": clearing the two already-null
        // columns is a harmless no-op, so an early conversion needs no
        // second method.
        $this->assertNull($updated->trial_ends_at);
        $this->assertNull($updated->grace_started_at);
        $this->assertNull($updated->locked_at);
        $this->assertCount(1, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccessRestored));
    }

    public function test_recover_access_accepts_a_null_actor_as_the_trusted_system_path(): void
    {
        Event::fake([WorkspaceAccessRestored::class]);
        $workspace = $this->assignedWorkspace(now()->subDay());
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Trial ended without conversion');

        // A write that actually clears something, with no human actor — the
        // shape a future payment-provider confirmation will call.
        $updated = app(EntitlementManager::class)->recoverAccess($workspace, null, 'Payment confirmed by provider.');

        $this->assertNull($updated->trial_ends_at);
        $this->assertNull($updated->grace_started_at);
        $this->assertNull($updated->locked_at);

        $transition = $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccessRestored)->sole();
        $this->assertNull($transition->actor_user_id);
        $this->assertSame('Payment confirmed by provider.', $transition->reason);
        Event::assertDispatched(
            WorkspaceAccessRestored::class,
            fn (WorkspaceAccessRestored $event): bool => $event->workspaceId === (int) $workspace->id
                && $event->actorUserId === null
                && $event->reason === 'Payment confirmed by provider.',
        );
    }

    public function test_recover_access_on_a_clean_active_workspace_is_a_true_no_op(): void
    {
        Event::fake([WorkspaceAccessRestored::class]);
        $workspace = $this->assignedWorkspace();
        $updatedAt = $this->assignment($workspace)->updated_at->toDateTimeString();

        $updated = app(EntitlementManager::class)->recoverAccess($workspace, null, 'Nothing outstanding.');

        $this->assertNull($updated->trial_ends_at);
        $this->assertSame($updatedAt, $this->assignment($workspace)->updated_at->toDateTimeString());
        $this->assertCount(0, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccessRestored));
        Event::assertNotDispatched(WorkspaceAccessRestored::class);
    }

    public function test_recover_access_refuses_a_suspended_workspace(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Payment failed.');
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspended.');

        // A payment must never lift an administrative suspension — only
        // changePlanStatus() can.
        $this->expectException(SuspendedWorkspacePlanException::class);
        app(EntitlementManager::class)->recoverAccess($workspace, null, 'Payment received.');
    }

    public function test_recover_access_refuses_an_inactive_workspace_and_clears_nothing(): void
    {
        $workspace = $this->assignedWorkspace();
        app(EntitlementManager::class)->lockForNonPayment($workspace, null, 'Locked.');
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), 'Closed.');

        try {
            app(EntitlementManager::class)->recoverAccess($workspace, null, 'Payment received.');
            $this->fail('recoverAccess() must refuse an Inactive assignment (§5: Active-only target).');
        } catch (InactiveWorkspacePlanException) {
            // expected
        }

        $this->assertNotNull($this->assignment($workspace)->locked_at);
        $this->assertCount(0, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::AccessRestored));
    }

    public function test_recover_access_refuses_an_unassigned_workspace(): void
    {
        $this->expectException(WorkspacePlanUnassignedException::class);
        app(EntitlementManager::class)->recoverAccess($this->workspace(), null, 'Payment received.');
    }

    /**
     * Documents the one recovery route Contract 03 allows for an account that
     * was locked and then closed: reactivate through the unchanged status
     * authority (§4: changePlanStatus() "reused unchanged"), then recover.
     *
     * The intermediate assertion is deliberate. changePlanStatus() writes
     * only `status`, so reactivation alone leaves `locked_at` in place and the
     * customer still reads Locked — the contract does not say reactivation
     * clears lifecycle state, and this slice does not invent that rule. The
     * gap is reported to the contract owner rather than papered over here.
     */
    public function test_a_locked_then_closed_account_is_restored_by_reactivating_then_recovering(): void
    {
        $manager = app(EntitlementManager::class);
        $resolver = app(CustomerAccountAccessResolver::class);
        $admin = $this->createAdmin();
        $workspace = $this->assignedWorkspace();

        $manager->lockForNonPayment($workspace, null, 'Grace period elapsed without payment');
        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $admin, 'Closed.');
        $this->assertSame(CustomerAccountAccessState::LockedInactive, $resolver->resolve($workspace->fresh())->state);

        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Active, $admin, 'Customer returned.');
        $this->assertSame(CustomerAccountAccessState::Locked, $resolver->resolve($workspace->fresh())->state);

        $manager->recoverAccess($workspace, $admin, 'Payment received.');

        $assignment = $this->assignment($workspace);
        $this->assertNull($assignment->trial_ends_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        $this->assertSame(CustomerAccountAccessState::Usable, $resolver->resolve($workspace->fresh())->state);
    }

    // =====================================================================
    // Writers decide on the row as it is NOW, under the lock (§7)
    // =====================================================================

    /**
     * The assignment repository memoizes findByWorkspaceId() for the life of
     * a request (and of a whole console process). A writer that trusted that
     * memo would take the Workspace lock and then decide on a snapshot read
     * BEFORE the lock — exactly the read §7 forbids. Here another process
     * starts Grace after this process has already memoized the row.
     */
    public function test_a_writer_ignores_a_stale_memoized_assignment_read_before_the_lock(): void
    {
        Event::fake([WorkspaceEnteredGracePeriod::class]);
        $workspace = $this->assignedWorkspace();

        // Memoize the assignment (grace_started_at NULL) for this request.
        app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh());

        // "Another process" starts Grace, bypassing this request's memo.
        $graceStartedAt = now()->subHours(3);
        WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->update(['grace_started_at' => $graceStartedAt]);

        app(EntitlementManager::class)->enterGracePeriod($workspace, null, 'Payment failed.');

        // The current row was already in Grace, so this must be the no-op.
        $this->assertSame($graceStartedAt->toDateTimeString(), $this->assignment($workspace)->grace_started_at->toDateTimeString());
        $this->assertCount(0, $this->transitionsOfType($workspace, WorkspaceEntitlementTransitionType::GraceStarted));
        Event::assertNotDispatched(WorkspaceEnteredGracePeriod::class);
    }

    public function test_recover_access_denies_a_non_administrator_actor(): void
    {
        $workspace = $this->assignedWorkspace(now()->addDays(3));

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->recoverAccess($workspace, $this->createNonAdmin(), 'Payment received.');
    }

    // =====================================================================
    // The full lifecycle walk (§13) — one Workspace, every transition
    // =====================================================================

    public function test_end_to_end_lifecycle_walk_trial_grace_locked_recovered_inactive(): void
    {
        $manager = app(EntitlementManager::class);
        $admin = $this->createAdmin();
        $workspace = $this->assignedWorkspace(now()->addDays(7));

        // Trial
        $this->assertNotNull($this->assignment($workspace)->trial_ends_at);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $this->assignment($workspace)->status);

        // Trial -> Grace
        $manager->enterGracePeriod($workspace, null, 'Trial ended without conversion');
        $this->assertNotNull($this->assignment($workspace)->grace_started_at);

        // Grace -> Locked
        $manager->lockForNonPayment($workspace, null, 'Grace period elapsed without payment');
        $this->assertNotNull($this->assignment($workspace)->locked_at);

        // Locked -> clean Active
        $manager->recoverAccess($workspace, $admin, 'Payment received.');
        $recovered = $this->assignment($workspace);
        $this->assertNull($recovered->trial_ends_at);
        $this->assertNull($recovered->grace_started_at);
        $this->assertNull($recovered->locked_at);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $recovered->status);

        // Active -> Inactive, through the unchanged canonical status
        // authority (§6 case F) — not a fourth lifecycle method.
        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $admin, 'Closed after the recoverable window.');
        $this->assertSame(WorkspacePlanAssignmentStatus::Inactive, $this->assignment($workspace)->status);

        // One durable audit row per real transition, in order.
        $types = WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->pluck('transition_type')
            ->map(fn ($type) => $type instanceof WorkspaceEntitlementTransitionType ? $type->value : $type)
            ->all();

        $this->assertSame([
            WorkspaceEntitlementTransitionType::PlanAssigned->value,
            WorkspaceEntitlementTransitionType::GraceStarted->value,
            WorkspaceEntitlementTransitionType::AccountLocked->value,
            WorkspaceEntitlementTransitionType::AccessRestored->value,
            WorkspaceEntitlementTransitionType::PlanStatusChanged->value,
        ], $types);
    }
}
