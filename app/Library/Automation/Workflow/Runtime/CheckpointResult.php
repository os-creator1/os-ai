<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Workspace;

/**
 * Automations V2 §7.3 — the outcome of an eligibility checkpoint.
 *
 * A checkpoint answers more than yes/no, because the three "no" answers need
 * different handling and conflating them is how journeys get silently lost:
 *
 *   HELD    the workflow is paused, or its entitlement is currently denied. The
 *           enrollment is left exactly as it is, at its cursor, and Resume (or a
 *           later entitlement restoration) picks it up. Nothing is claimed.
 *   EXITED  the contact or Business is gone, or the contact left the audience.
 *           The journey is over and must be closed honestly.
 *   OK      carries the freshly re-read objects the executor must use — never
 *           anything loaded before the claim.
 */
final readonly class CheckpointResult
{
    private function __construct(
        public bool $ok,
        public bool $shouldExit,
        public ?string $reason = null,
        public ?AutomationWorkflow $workflow = null,
        public ?AutomationWorkflowVersion $version = null,
        public ?Business $business = null,
        public ?Workspace $workspace = null,
        public ?Contacts $contact = null,
    ) {
    }

    public static function ok(
        AutomationWorkflow $workflow,
        AutomationWorkflowVersion $version,
        Business $business,
        Workspace $workspace,
        Contacts $contact,
    ): self {
        return new self(true, false, null, $workflow, $version, $business, $workspace, $contact);
    }

    /**
     * A temporary refusal. The enrollment keeps its place in the journey — this
     * is what "held" means, and it is the state Resume re-dispatches from.
     */
    public static function held(string $reason): self
    {
        return new self(false, false, $reason);
    }

    /** A permanent refusal: close the journey with this reason. */
    public static function exit(string $reason): self
    {
        return new self(false, true, $reason);
    }
}
