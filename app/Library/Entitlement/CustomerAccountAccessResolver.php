<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Models\Workspace;

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
    public function __construct(private readonly EntitlementManager $entitlementManager)
    {
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
}
