<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * Automations V2 §5.2 — what one step type knows how to do.
 *
 * Implemented by V2-A for the logic steps (wait, if/else, end) and by V2-B for
 * the action steps (send SMS, update field, notification). V2-0 declares the
 * interface only, so those two lanes can proceed in parallel.
 *
 * CONTRACT FOR EVERY IMPLEMENTATION:
 *
 *   1. `execute()` is called OUTSIDE any database transaction, after the step
 *      has already been claimed and the final checkpoint has passed. An executor
 *      must never open a long transaction or hold a lock across a provider call.
 *   2. Every object it receives was re-read after the claim. An executor must
 *      not load the enrollment, workflow or contact again and act on a different
 *      copy.
 *   3. Consent is re-checked HERE, at the action boundary, never cached from
 *      claim time (Lane F §6.1): an unsubscribed contact is a skip.
 *   4. It returns an outcome. It never writes the step run, never moves the
 *      cursor and never decides whether the journey continues.
 *   5. It never calls another workflow, enrolls anybody, or dispatches work.
 */
interface NodeExecutor
{
    /** The single node type this executor is registered for. */
    public function handles(): \App\Enums\Automation\Workflow\WorkflowNodeType;

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome;
}
