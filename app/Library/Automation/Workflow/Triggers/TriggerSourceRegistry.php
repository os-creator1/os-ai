<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\TriggerSource;

/**
 * Automations V2 §9 — which source feeds which trigger type.
 *
 * The same shape as NodeExecutorRegistry, and for the same reason: each lane
 * registers what it owns, in one place, and nothing needs editing when the next
 * lane arrives. V2-C registers the three sources it builds; V2-F adds
 * `message_received` with one more register() call.
 *
 * A TRIGGER WITH NO SOURCE IS NOT SILENTLY DEAD. `for()` returns null and
 * `available()` reports false, which is the honest answer to "can a workflow on
 * this trigger ever fire?" — the question V2-0's validator already asks through
 * WorkflowTriggerType::isIngestableInThisSlice(). Keeping both truthful means a
 * publish is refused rather than a customer waiting for a trigger nothing can
 * report.
 */
class TriggerSourceRegistry
{
    /** @var array<string, TriggerSource> */
    private array $sources = [];

    public function register(TriggerSource $source): void
    {
        $this->sources[$source->triggerType()->value] = $source;
    }

    public function for(WorkflowTriggerType $type): ?TriggerSource
    {
        return $this->sources[$type->value] ?? null;
    }

    public function available(WorkflowTriggerType $type): bool
    {
        $source = $this->for($type);

        return $source !== null && $source->isAvailable();
    }

    /** @return list<string> the trigger types that can enroll today. */
    public function registeredTypes(): array
    {
        return array_keys($this->sources);
    }
}
