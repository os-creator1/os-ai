<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BookingType;
use App\Models\Contacts;

/**
 * Automations V2 — the Send booking link action.
 *
 * THE CALENDAR'S OWN PAGE. A booking type has one public address, minted by the
 * Calendar and routed as `public.booking.show`; the step links to exactly that — it
 * never builds a scheduler, a slot list or a second URL.
 *
 * WHICH BOOKING TYPE, AND WHOSE. The booking type is looked up INSIDE the journey's
 * Business, through the Location it belongs to, and must still be active at an
 * active Location. A booking type belongs to exactly one Location, and a journey
 * pinned to another Location never sends it (`resource_outside_workflow_location`):
 * the contact would be booking at a place the workflow was never about.
 *
 * Words and delivery are LinkActionNodeExecutor's.
 */
class SendBookingLinkNodeExecutor extends LinkActionNodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendBookingLink;
    }

    protected function resolveLink(array $config, AutomationEnrollment $enrollment, Business $business, Contacts $contact): array|NodeExecutionOutcome
    {
        $bookingTypeId = (int) ($config['booking_type_id'] ?? 0);

        if ($bookingTypeId <= 0) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $bookingType = BookingType::query()
            ->whereKey($bookingTypeId)
            ->whereIn('business_location_id', BusinessLocation::query()->where('business_id', (int) $business->id)->select('id'))
            ->first();

        if ($bookingType === null || ! $bookingType->isActive() || ! ($bookingType->location?->isActive() ?? false)) {
            return NodeExecutionOutcome::failed('booking_type_unavailable');
        }

        $outside = PinnedRunLocation::resourceViolation($enrollment, (int) $bookingType->business_location_id);

        if ($outside !== null) {
            return NodeExecutionOutcome::skipped($outside);
        }

        return [
            'url' => route('public.booking.show', [$bookingType->public_booking_uuid]),
            'subject' => 'Book your appointment',
            'text' => 'Here is the link to book your appointment:',
        ];
    }
}
