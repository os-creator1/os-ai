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
 * Automations V2 — the explicit End step.
 *
 * It succeeds and does nothing else. Completion is not decided here: the advancer
 * completes an enrollment when the step it just ran has no successor, which is
 * true of an End node by construction (the compiler refuses to place anything
 * after one) and equally true of the last step of any lane.
 *
 * Keeping completion in one place means a journey ends the same way whether the
 * customer drew an explicit End or simply stopped adding steps — there is no
 * second completion path to keep in step with the first.
 */
class EndNodeExecutor implements NodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::End;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        return NodeExecutionOutcome::succeeded('Finished');
    }
}
