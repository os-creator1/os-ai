<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * Automations V2 — the root step.
 *
 * A trigger node does no work at execution time: whatever it describes already
 * happened, which is why this enrollment exists at all. It is still a real step
 * with a real step run, because the execution log should show where a journey
 * started, and because giving the root the same claim treatment as every other
 * node means the advancer has no special case for "the first one".
 *
 * Side-effect class None, so an interrupted trigger step is safe for the recovery
 * sweep to re-derive.
 */
class TriggerNodeExecutor implements NodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::Trigger;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        return NodeExecutionOutcome::succeeded('Started');
    }
}
