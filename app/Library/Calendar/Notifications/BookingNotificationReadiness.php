<?php

namespace App\Library\Calendar\Notifications;

use App\Library\Messaging\BusinessSmsSendingPath;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * Whether each customer-notification channel could actually deliver for a Booking
 * Type right now. Presentation only — the sender re-decides at send time — but it
 * answers with the SAME authority the sender uses (the Location-aware
 * BusinessSmsSendingPath), so the editor never says "ready" for a text the sender
 * would refuse. A channel that is not ready never blocks a booking: the booking
 * succeeds and the ledger records the skip.
 */
class BookingNotificationReadiness
{
    public function __construct(private readonly BusinessSmsSendingPath $paths)
    {
    }

    /** @return array{email: array{ready: bool, reason: ?string}, sms: array{ready: bool, reason: ?string}} */
    public function forBookingType(BookingType $type): array
    {
        $location = BusinessLocation::query()->find($type->business_location_id);
        $business = $location === null ? null : Business::query()->find($location->business_id);

        return [
            'email' => $this->email(),
            'sms' => $business === null
                ? ['ready' => false, 'reason' => 'This business could not be found.']
                : $this->sms($business, (int) $type->business_location_id),
        ];
    }

    public function smsReady(BookingType $type): bool
    {
        return $this->forBookingType($type)['sms']['ready'];
    }

    /** @return array{ready: bool, reason: ?string} */
    private function email(): array
    {
        // Platform transactional mail: it does not depend on a connected mailbox.
        return filled(config('mail.default')) && filled(config('mail.from.address'))
            ? ['ready' => true, 'reason' => null]
            : ['ready' => false, 'reason' => 'Email sending is not configured on this platform.'];
    }

    /** @return array{ready: bool, reason: ?string} */
    private function sms(Business $business, int $locationId): array
    {
        $path = $this->paths->resolveForLocation($business, new LocationSendContext($locationId, false));

        if (is_array($path)) {
            return ['ready' => true, 'reason' => null];
        }

        return ['ready' => false, 'reason' => match ($path) {
            'location_sender_unavailable' => 'The texting number is not assigned to this location. Assign it in Settings > Text messaging.',
            default => 'Text messages need a phone number. Set one up in Settings > Text messaging.',
        }];
    }
}
