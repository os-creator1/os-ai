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
 * Today's canonical WorkspacePlanAssignmentStatus is only Active/Inactive/
 * Suspended — there is no Trialing/TrialExpired case, so this resolver does
 * not invent one. A future status (trial expired, past due) plugs in as one
 * more arm of the match below; nothing else in the request pipeline needs
 * to change.
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
            WorkspacePlanAssignmentStatus::Active => CustomerAccountAccessDecision::usable(),

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
