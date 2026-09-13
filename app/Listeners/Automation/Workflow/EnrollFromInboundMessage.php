<?php

namespace App\Listeners\Automation\Workflow;

use App\Events\Conversation\InboundMessageReceived;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2-F — carries an attributed inbound message to its trigger source,
 * off the webhook request.
 *
 * QUEUED, because the provider is waiting on the webhook's response, and working
 * out which workflows a message enrolls into is not the provider's problem. The
 * event is dispatched after commit, so by the time this runs every row the
 * inbound path wrote exists.
 *
 * `$tries = 1`, matching every automation job (Lane F §6.1): a redelivery is
 * already harmless, because the occurrence key is the message's own identity and
 * the second attempt loses the unique enrollment claim.
 *
 * THE LOG LINE is the record of what did not enroll — V2 has no trigger-level
 * skip table, and an ambiguous contact creates no enrollment to record it on. It
 * carries the Business id, the occurrence key and the counts, and deliberately
 * NOT the sender's number or the message: a log is not the place for either.
 */
class EnrollFromInboundMessage implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly MessageReceivedTriggerSource $source)
    {
    }

    public function handle(InboundMessageReceived $event): void
    {
        $result = $this->source->handleInboundMessage($event);

        if ($result['skipped'] === []) {
            return;
        }

        Log::info('automation.message_received.skipped', [
            'business_id' => $event->businessId,
            'occurrence_key' => $event->occurrenceKey,
            'enrolled' => $result['enrolled'],
            'skipped' => $result['skipped'],
        ]);
    }
}
