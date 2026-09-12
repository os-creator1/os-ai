<?php

namespace Tests\Feature\Automations\Workflow\Runtime\Support;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;

/**
 * A deterministic stand-in for a step type whose real executor has not shipped.
 *
 * It exists so the runtime can be proven end to end — progression, claiming,
 * at-most-once, failure handling — without waiting for the SMS, wait or If/Else
 * slices, and without this slice pretending to implement any of them. It records
 * every call, so "was this step executed exactly once?" is directly answerable
 * rather than inferred from side effects.
 *
 * It registers for `send_sms` purely because that type exists in the compiled
 * enum and has no executor yet. It sends nothing.
 */
class RecordingNodeExecutor implements NodeExecutor
{
    /** @var list<array{node: int, enrollment: int, contact: int}> */
    public array $calls = [];

    public ?NodeExecutionOutcome $nextOutcome = null;

    /** @var (\Closure(): void)|null fires inside execute(), before returning. */
    public ?\Closure $duringExecute = null;

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendSms;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $this->calls[] = [
            'node' => (int) $node->getKey(),
            'enrollment' => (int) $enrollment->getKey(),
            'contact' => (int) $contact->getKey(),
        ];

        if ($this->duringExecute !== null) {
            ($this->duringExecute)();
        }

        $outcome = $this->nextOutcome ?? NodeExecutionOutcome::succeeded('recorded');
        $this->nextOutcome = null;

        return $outcome;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function callsForNode(int $nodeId): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c['node'] === $nodeId));
    }
}
