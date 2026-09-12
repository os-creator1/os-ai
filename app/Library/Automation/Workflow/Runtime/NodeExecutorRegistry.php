<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;

/**
 * Automations V2 §5.2 — which executor runs which step type.
 *
 * The registry is how the parallel lanes meet. This slice registers the two
 * structural executors it owns (trigger and end); the wait and If/Else executors
 * arrive with their own slice, and the action executors with theirs. Nothing here
 * needs to change when they do — each lane registers its own type.
 *
 * A TYPE WITH NO EXECUTOR IS NOT SILENTLY SKIPPED. `for()` returns null and the
 * advancer refuses to claim the step, leaving the enrollment exactly where it is.
 * That matters: skipping would quietly drop a step out of a customer's journey,
 * and failing would end the journey over a gap that is temporary by design. A
 * held enrollment simply continues the moment its executor ships.
 */
class NodeExecutorRegistry
{
    /** @var array<string, NodeExecutor> */
    private array $executors = [];

    public function register(NodeExecutor $executor): void
    {
        $this->executors[$executor->handles()->value] = $executor;
    }

    public function for(WorkflowNodeType $type): ?NodeExecutor
    {
        return $this->executors[$type->value] ?? null;
    }

    public function has(WorkflowNodeType $type): bool
    {
        return isset($this->executors[$type->value]);
    }

    /** @return list<string> the node types that can run today. */
    public function registeredTypes(): array
    {
        return array_keys($this->executors);
    }
}
