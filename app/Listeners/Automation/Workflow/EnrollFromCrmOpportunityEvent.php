<?php

namespace App\Listeners\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Crm\CrmOpportunityEvent;
use App\Library\Automation\Workflow\Triggers\CrmOpportunityTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2 — hands a CRM sales opportunity event to the trigger source
 * registered for it. The CRM emits; Automations consumes; neither calls the
 * other.
 *
 * Queued on `automation`, after the CRM's own commit (the events are
 * ShouldDispatchAfterCommit). One try, like the inbound-message listener: a
 * redelivered or retried event composes the same occurrence key and enrolls
 * nobody twice, so a retry buys nothing but duplicate work.
 *
 * The event's canonical name IS the trigger type, so the source is found through
 * the registry rather than a second mapping kept here.
 */
class EnrollFromCrmOpportunityEvent implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly TriggerSourceRegistry $sources)
    {
    }

    public function handle(CrmOpportunityEvent $event): void
    {
        $type = WorkflowTriggerType::tryFrom($event->name());
        $source = $type === null ? null : $this->sources->for($type);

        if (! $source instanceof CrmOpportunityTriggerSource) {
            return;
        }

        $result = $source->handle($event);

        if ($result['skipped'] === []) {
            return;
        }

        Log::info('automation.crm_opportunity.skipped', [
            'business_id' => $event->businessId,
            'trigger_type' => $type->value,
            'occurrence_key' => $event->occurrenceKey(),
            'enrolled' => $result['enrolled'],
            'skipped' => $result['skipped'],
        ]);
    }
}
