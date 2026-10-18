<?php

namespace App\Listeners\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Library\Automation\Workflow\Triggers\DocumentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2 — hands a document or payment lifecycle event to the trigger source
 * registered for it. The Documents and Payments domains emit after commit;
 * Automations consumes; neither calls the other.
 *
 * Queued on `automation`. One try: a redelivered event composes the same
 * occurrence key and enrolls nobody twice.
 */
class EnrollFromDocumentEvent implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly TriggerSourceRegistry $sources)
    {
    }

    public function handle(DocumentSent|DocumentSigned|DocumentPaymentSucceeded|DocumentPaymentFailed $event): void
    {
        $type = match (true) {
            $event instanceof DocumentSent => WorkflowTriggerType::DocumentSent,
            $event instanceof DocumentSigned => WorkflowTriggerType::DocumentSigned,
            $event instanceof DocumentPaymentSucceeded => WorkflowTriggerType::PaymentSucceeded,
            default => WorkflowTriggerType::PaymentFailed,
        };

        $source = $this->sources->for($type);

        if (! $source instanceof DocumentTriggerSource) {
            return;
        }

        $result = $source->handle($event);

        if ($result['skipped'] === []) {
            return;
        }

        Log::info('automation.document.skipped', [
            'business_id' => $event->businessId,
            'trigger_type' => $type->value,
            'document_id' => $event->documentId,
            'enrolled' => $result['enrolled'],
            'skipped' => $result['skipped'],
        ]);
    }
}
