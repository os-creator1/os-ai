<?php

namespace App\Listeners\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Library\Automation\Workflow\Triggers\AppointmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2 — hands a Calendar appointment lifecycle event to the trigger
 * source registered for it. The Calendar emits; Automations consumes; neither
 * calls the other.
 *
 * Queued on `automation`, after the booking transaction's own commit. One try: a
 * redelivered event composes the same occurrence key and enrolls nobody twice.
 */
class EnrollFromAppointmentEvent implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly TriggerSourceRegistry $sources)
    {
    }

    public function handle(AppointmentScheduled|AppointmentCancelled|AppointmentRescheduled $event): void
    {
        $type = match (true) {
            $event instanceof AppointmentScheduled => WorkflowTriggerType::AppointmentScheduled,
            $event instanceof AppointmentCancelled => WorkflowTriggerType::AppointmentCancelled,
            default => WorkflowTriggerType::AppointmentRescheduled,
        };

        $source = $this->sources->for($type);

        if (! $source instanceof AppointmentTriggerSource) {
            return;
        }

        $result = $source->handle($event);

        if ($result['skipped'] === []) {
            return;
        }

        Log::info('automation.appointment.skipped', [
            'business_id' => $event->businessId,
            'trigger_type' => $type->value,
            'occurrence_key' => $event->occurrenceKey(),
            'enrolled' => $result['enrolled'],
            'skipped' => $result['skipped'],
        ]);
    }
}
