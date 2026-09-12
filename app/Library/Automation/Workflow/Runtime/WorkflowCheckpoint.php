<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Workspace;

/**
 * Automations V2 §7.3 — the authoritative eligibility re-read.
 *
 * This is B4's AutomationEligibility (§5.5) carried forward to a multi-step
 * world, and the reasoning is unchanged: a disable, an entitlement revocation or
 * a Business change can land between one step and the next, so eligibility is
 * re-derived from the database immediately before every step rather than trusted
 * from whatever was loaded when the job was queued.
 *
 * TWO DELIBERATE PROPERTIES:
 *
 *   It distinguishes HELD from EXITED. A paused workflow must leave the journey
 *   intact so Resume can continue it; a deleted contact must end it. B4 had no
 *   such distinction because a single-step execution had nothing to hold.
 *
 *   It runs BEFORE the claim (cheaply, without locks) and the advancer runs it
 *   AGAIN after the claim against the locked rows. The contract is explicit that
 *   this is a checkpoint guarantee, not atomicity with external revocation: an
 *   entitlement withdrawn between the two lets exactly one more step run, and
 *   that bounded window is accepted rather than papered over.
 */
class WorkflowCheckpoint
{
    public function __construct(private readonly EntitlementManager $entitlementManager)
    {
    }

    /**
     * Re-read everything this enrollment needs, and decide whether its next step
     * may run now.
     */
    public function resolve(AutomationEnrollment $enrollment): CheckpointResult
    {
        $workflow = AutomationWorkflow::query()->find($enrollment->workflow_id);

        if ($workflow === null) {
            return CheckpointResult::exit('workflow_missing');
        }

        // Archived is permanent; paused is temporary. Telling them apart is the
        // difference between cancelling a journey and holding it.
        if ($workflow->status->value === 'archived') {
            return CheckpointResult::exit('workflow_archived');
        }

        if (! $workflow->permitsExecution()) {
            return CheckpointResult::held('workflow_paused');
        }

        // THE PIN. The runtime executes the version the enrollment started on —
        // never the workflow's current published version — so a republish cannot
        // change what a journey already underway is doing.
        $version = AutomationWorkflowVersion::query()->find($enrollment->version_id);

        if ($version === null) {
            return CheckpointResult::exit('version_missing');
        }

        if ($version->state === WorkflowVersionState::Draft) {
            // A pinned version can only be published or superseded. A draft here
            // would mean something re-pointed the pin, and executing an editable
            // definition is exactly what versioning exists to prevent.
            return CheckpointResult::exit('version_not_published');
        }

        if ((int) $version->workflow_id !== (int) $workflow->id) {
            return CheckpointResult::exit('version_workflow_mismatch');
        }

        $business = Business::query()->find($enrollment->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            return CheckpointResult::exit('business_inactive');
        }

        if ((int) $workflow->business_id !== (int) $business->id) {
            return CheckpointResult::exit('business_mismatch');
        }

        $workspace = $business->workspace_id !== null
            ? Workspace::query()->find($business->workspace_id)
            : null;

        if ($workspace === null || ! $workspace->is_active) {
            return CheckpointResult::exit('workspace_inactive');
        }

        // An entitlement that is currently denied is a HOLD, not an exit: plans
        // change back, and cancelling half-finished journeys over a lapsed
        // subscription would be destructive and surprising.
        if (! $this->entitled($workspace, $business)) {
            return CheckpointResult::held('not_entitled');
        }

        $contact = Contacts::query()->find($enrollment->contact_id);

        if ($contact === null) {
            return CheckpointResult::exit('contact_missing');
        }

        if ($contact->business_id === null || (int) $contact->business_id !== (int) $business->id) {
            return CheckpointResult::exit('contact_not_in_business');
        }

        return CheckpointResult::ok($workflow, $version, $business, $workspace, $contact);
    }

    /**
     * The Automations entitlement, decided exactly as B4 §2.5 requires: the
     * actor is the Business's own persistence owner, never a browser session
     * user, and never Auth::id() from a job. The argument is structurally
     * required and behaviourally inert — EntitlementManager::decide() never reads
     * it — so it can never smuggle an authorization decision into a queue worker.
     */
    public function entitled(Workspace $workspace, Business $business): bool
    {
        try {
            $decision = $this->entitlementManager->decide(
                $workspace,
                $business,
                PlatformFeature::Automations->value,
                (int) $business->customer_id,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        return $decision->allowed;
    }
}
