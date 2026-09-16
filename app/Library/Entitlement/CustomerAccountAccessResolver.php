<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\WorkspaceCandidate;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;

/**
 * Chat F — Customer Account Access Gate foundation.
 *
 * The ONE authority for "can this customer use the normal product right
 * now, for this Workspace" — CustomerAccountAccessGate (middleware) enforces
 * what this decides, and the locked-account screen renders what this says,
 * but neither one re-derives the decision from WorkspacePlanAssignmentStatus
 * itself. That duplication is exactly what this class exists to prevent.
 *
 * READS ONLY. This never mutates anything, never calls a payment provider,
 * and never marks an account active — it answers "is the paid software
 * access currently usable", nothing more.
 *
 * Delegates the actual status read to EntitlementManager::getWorkspaceEntitlementSummary()
 * — RFC-004's sole authority for WorkspacePlanAssignment data — rather than
 * querying the assignment table directly, so this gate can never drift from
 * the same entitlement precedence decide() itself applies.
 *
 * Canonical WorkspacePlanAssignmentStatus is still only Active/Inactive/
 * Suspended, and Contract 03 (Slice 4) deliberately keeps it that way
 * (Addendum §7): Trial, Grace and Locked are derived HERE, from that status
 * plus the assignment's three lifecycle timestamps, rather than from new
 * status cases. Trial and Grace stay Usable with a non-blocking hint
 * (Blueprint §27 keeps full access through both); only Locked blocks.
 *
 * Still READ-ONLY after Slice 4: every lifecycle timestamp this class reads
 * is written by EntitlementManager's own writers (enterGracePeriod(),
 * lockForNonPayment(), recoverAccess()), never here.
 */
final class CustomerAccountAccessResolver
{
    public function __construct(
        private readonly EntitlementManager $entitlementManager,
        private readonly WorkspaceRepository $workspaceRepository,
    ) {
    }

    /**
     * $workspace is null when the request has no Workspace to evaluate at
     * all (no {workspaceUid} on the route and the actor's context resolves
     * none — a brand-new customer who has not created an account yet). That
     * is the onboarding/workspace-creation surface's own concern, not this
     * gate's: nothing is locked for a Workspace that does not exist yet.
     */
    public function resolve(?Workspace $workspace): CustomerAccountAccessDecision
    {
        if ($workspace === null) {
            return CustomerAccountAccessDecision::usable();
        }

        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        // An unassigned Workspace (no plan at all yet) is a distinct,
        // pre-existing state this gate does not touch — onboarding/plan
        // selection owns it, and locking it here would block a brand-new
        // account before it ever reaches a plan.
        if (! $summary->isAssigned || $summary->status === null) {
            return CustomerAccountAccessDecision::usable();
        }

        return match ($summary->status) {
            // Contract 03 §5 — Active is where the lifecycle lives: Trial,
            // Active, Grace and Locked are all derived from this one status
            // plus the assignment's timestamps, never from a fourth status.
            WorkspacePlanAssignmentStatus::Active => $this->resolveActiveLifecycle($summary),

            WorkspacePlanAssignmentStatus::Inactive => new CustomerAccountAccessDecision(
                state: CustomerAccountAccessState::LockedInactive,
                reason: 'plan_inactive',
                heading: 'Welcome back',
                message: 'Your account is currently inactive, but your setup and data are still saved.',
                // Truthful CTA (product decision, Chat F): there is no
                // self-service reactivation write path today — plan.show is
                // the existing, real, read-only Plan & subscription page.
                // Never a fake "Reactivate" button that flips status on a
                // click.
                recoveryRouteName: 'customer.workspaces.plan.show',
                recoveryLabel: 'Continue to billing',
            ),

            WorkspacePlanAssignmentStatus::Suspended => new CustomerAccountAccessDecision(
                state: CustomerAccountAccessState::LockedSuspended,
                reason: 'plan_suspended',
                heading: 'Account suspended',
                message: 'Your account is currently suspended. This is not necessarily related to payment — please contact support for help restoring access.',
                // Deliberately no recovery route: a suspension is
                // administrative (RFC-004 §14's own 'plan_suspended' denial
                // reason), and this gate never promises a payment fixes it.
                recoveryRouteName: null,
                recoveryLabel: null,
            ),
        };
    }

    /**
     * Contract 03 §5's canonical truth table, for an assignment whose base
     * status is Active. Read-only: every timestamp here was written by one of
     * EntitlementManager's lifecycle writers, never by this class.
     *
     * Order matters, and it is the table's own order:
     *
     *  1. `locked_at` set                  -> Locked.
     *  2. `grace_started_at` elapsed        -> Locked, even though no job has
     *     written `locked_at` yet. This is the defensive arm: a missed
     *     scheduled run must never leave a delinquent account Usable. It is a
     *     second safety net, not the mechanism — the durable writer still runs.
     *  3. `grace_started_at` still running  -> Usable, with the Grace hint.
     *     Blueprint §27 keeps full access during Grace; only the billing
     *     prompt changes.
     *  4. `trial_ends_at` set               -> Usable, with the trial hint.
     *     A past-but-uncleared value reads identically to a running trial:
     *     that window (expired, not yet swept or converted) is real and brief,
     *     and the sweep or a conversion resolves it.
     *  5. everything null                   -> plain Active.
     *
     * Suspended and Inactive never reach here, which is exactly how an
     * administrative suspension wins over stale Grace/Locked timestamps.
     */
    private function resolveActiveLifecycle(WorkspaceEntitlementSummary $summary): CustomerAccountAccessDecision
    {
        if ($summary->lockedAt !== null) {
            return $this->lockedDecision();
        }

        if ($summary->graceStartedAt !== null) {
            $graceEndsAt = $summary->graceStartedAt->copy()->addDays(EntitlementManager::GRACE_PERIOD_DAYS);

            if (! $graceEndsAt->isFuture()) {
                return $this->lockedDecision();
            }

            return new CustomerAccountAccessDecision(
                state: CustomerAccountAccessState::Usable,
                reason: 'plan_grace',
                heading: 'Payment needed',
                message: 'We could not take your latest payment, so your account is in a short grace period. Everything still works — please update your billing details to keep it that way.',
                recoveryRouteName: 'customer.workspaces.plan.show',
                recoveryLabel: 'Continue to billing',
                graceEndsAt: $graceEndsAt,
            );
        }

        if ($summary->trialEndsAt !== null) {
            return new CustomerAccountAccessDecision(
                state: CustomerAccountAccessState::Usable,
                reason: 'plan_trial',
                heading: 'Trial',
                message: 'Your trial is running. Add your billing details whenever you are ready — nothing is interrupted until it ends.',
                recoveryRouteName: 'customer.workspaces.plan.show',
                recoveryLabel: 'Continue to billing',
                trialEndsAt: $summary->trialEndsAt,
            );
        }

        return CustomerAccountAccessDecision::usable();
    }

    /**
     * The one Locked decision, shared by both routes into it (a written
     * `locked_at`, and the defensive elapsed-Grace derivation) so the customer
     * reads the same thing either way. Truthful CTA, exactly like the Inactive
     * arm's: the Plan & subscription page is real and a payment does restore
     * access here (Blueprint §27's "immediate unlock on confirmed payment"),
     * but nothing on this screen flips the state by itself.
     */
    private function lockedDecision(): CustomerAccountAccessDecision
    {
        return new CustomerAccountAccessDecision(
            state: CustomerAccountAccessState::Locked,
            reason: 'plan_locked',
            heading: 'Account locked',
            message: 'Your account is locked because a payment is still outstanding. Your setup and data are saved — completing payment restores access right away.',
            recoveryRouteName: 'customer.workspaces.plan.show',
            recoveryLabel: 'Continue to billing',
        );
    }

    /**
     * PR #302 correction 2 — the multiple-accessible-Workspaces, no-
     * explicit-selection case: a workspace-agnostic request from an actor
     * who can see more than one Workspace and has not (yet) picked one.
     *
     * This never guesses which Workspace the request is "really" for.
     * Instead it evaluates every one of them through resolve() — the same
     * single per-Workspace policy above, called once per candidate — and
     * combines the results conservatively:
     *
     * - every candidate locked -> the customer must not reach a
     *   workspace-agnostic operational route either; one of the locked
     *   decisions is returned (all Inactive candidates carry the same
     *   'plan_inactive' reason and message, all Suspended candidates carry
     *   'plan_suspended' — this never mixes the two present at once, and
     *   never names which specific Workspace produced it).
     * - at least one candidate usable -> usable. An explicit selection
     *   (frameWorkspace(), handled by the caller before this is ever
     *   reached) is what commits to a specific Workspace; this method must
     *   not let a locked Workspace contaminate a genuinely reachable
     *   Active one, and must not invent a selection of its own to decide
     *   otherwise.
     *
     * @param array<int, Workspace> $workspaces
     */
    public function resolveAmbiguous(array $workspaces): CustomerAccountAccessDecision
    {
        if ($workspaces === []) {
            return CustomerAccountAccessDecision::usable();
        }

        $decisions = array_map(fn (Workspace $workspace): CustomerAccountAccessDecision => $this->resolve($workspace), $workspaces);

        foreach ($decisions as $decision) {
            if (! $decision->isLocked()) {
                return CustomerAccountAccessDecision::usable();
            }
        }

        return $decisions[0];
    }

    /**
     * PR #302 correction 2 — the ONE workspace-agnostic decision, shared by
     * every caller that has no routed {workspaceUid} to evaluate instead
     * (CustomerAccountAccessGate for an ordinary request, AccountLockedController
     * for the locked screen's own self-correcting re-check). Before this
     * method existed each caller re-derived "which Workspace(s) does a
     * workspace-agnostic request apply to" by hand from CustomerContext,
     * and the two copies had already drifted: the locked screen still used
     * frameWorkspace() alone, so a customer with several Workspaces and no
     * selection — all of them locked — would have been resolved as usable()
     * here and bounced back to the dashboard, which would immediately
     * re-lock and redirect back, forever. One authoritative method is what
     * closes that seam for both callers at once.
     *
     * frameWorkspace() already encodes the existing explicit-selection
     * model correctly: an explicit selection or the sole Workspace, either
     * way a single concrete candidate to evaluate directly. It returns null
     * for two different reasons this method must not conflate —
     * hasMultipleWorkspaces() tells them apart.
     */
    public function resolveForContext(CustomerContext $context): CustomerAccountAccessDecision
    {
        $frame = $context->frameWorkspace();

        if ($frame !== null) {
            return $this->resolve($this->workspaceRepository->findByUid($frame->uid));
        }

        if (! $context->hasMultipleWorkspaces()) {
            // Zero accessible Workspaces: nothing to lock (unchanged).
            return CustomerAccountAccessDecision::usable();
        }

        $workspaces = array_values(array_filter(array_map(
            fn (WorkspaceCandidate $candidate): ?Workspace => $this->workspaceRepository->findByUid($candidate->uid),
            $context->workspaces,
        )));

        return $this->resolveAmbiguous($workspaces);
    }
}
