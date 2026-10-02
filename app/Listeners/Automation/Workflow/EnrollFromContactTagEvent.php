<?php

namespace App\Listeners\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagEvent;
use App\Library\Automation\Workflow\Triggers\ContactTagTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2 — hands a Contact Tag membership event to the trigger source
 * registered for it. The Tags domain emits; Automations consumes; neither calls
 * the other.
 *
 * Queued on `automation`, after TagManager's own commit (the events are
 * ShouldDispatchAfterCommit). One try, like the CRM listener: a redelivered or
 * retried event composes the same occurrence key and enrolls nobody twice.
 */
class EnrollFromContactTagEvent implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly TriggerSourceRegistry $sources)
    {
    }

    public function handle(ContactTagEvent $event): void
    {
        $type = $event instanceof ContactTagAdded
            ? WorkflowTriggerType::ContactTagAdded
            : WorkflowTriggerType::ContactTagRemoved;

        $source = $this->sources->for($type);

        if (! $source instanceof ContactTagTriggerSource) {
            return;
        }

        $result = $source->handle($event);

        if ($result['skipped'] === []) {
            return;
        }

        Log::info('automation.contact_tag.skipped', [
            'business_id' => $event->businessId,
            'trigger_type' => $type->value,
            'occurrence_key' => $event->occurrenceKey(),
            'enrolled' => $result['enrolled'],
            'skipped' => $result['skipped'],
        ]);
    }
}
