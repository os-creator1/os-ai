<?php

namespace App\Listeners\Automation\Workflow;

use App\Events\Calendar\AppointmentScheduled;
use App\Events\Conversation\InboundMessageReceived;
use App\Library\Automation\Workflow\Runtime\LeadFollowUpStopper;
use App\Library\Contacts\ContactPhone;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Revenue journey — "stop when the customer replies, opts out, or books".
 *
 * Queued on `automation`, after the emitting transaction committed. Re-reads
 * everything by id inside the event's own Business; an unattributable message
 * never produces an event, so there is no cross-tenant path here. Failures are
 * logged and swallowed: stopping a follow-up must never break a webhook or a
 * booking, and the per-send STOP/unsubscribe gate still protects the customer.
 */
class StopFollowUpsOnCustomerOutcome implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly LeadFollowUpStopper $stopper)
    {
    }

    public function handleInbound(InboundMessageReceived $event): void
    {
        try {
            $business = Business::query()->find($event->businessId);

            if ($business === null) {
                return;
            }

            $forms = ContactPhone::candidates($event->senderPhone, ContactPhone::regionFor($business));

            if ($forms === []) {
                return;
            }

            $contacts = Contacts::query()
                ->where('business_id', (int) $business->id)
                ->whereIn('phone', $forms)
                ->get(['id', 'status']);

            // An unsubscribed contact who just wrote is an opt-out (STOP) or a reply to a
            // blocked channel; either way no further automated follow-up is wanted.
            $optedOut = $contacts->where('status', Contacts::STATUS_UNSUBSCRIBE)->pluck('id')->all();
            $replied = $contacts->where('status', '!=', Contacts::STATUS_UNSUBSCRIBE)->pluck('id')->all();

            $this->stopper->stop((int) $business->id, $optedOut, LeadFollowUpStopper::OPTED_OUT);
            $this->stopper->stop((int) $business->id, $replied, LeadFollowUpStopper::REPLIED);
        } catch (\Throwable $e) {
            Log::warning('automation.stop_followups.failed', ['business_id' => $event->businessId, 'event' => 'inbound', 'error' => $e::class]);
        }
    }

    public function handleBooked(AppointmentScheduled $event): void
    {
        try {
            $this->stopper->stop($event->businessId, [$event->contactId], LeadFollowUpStopper::BOOKED);
        } catch (\Throwable $e) {
            Log::warning('automation.stop_followups.failed', ['business_id' => $event->businessId, 'event' => 'booked', 'error' => $e::class]);
        }
    }
}
