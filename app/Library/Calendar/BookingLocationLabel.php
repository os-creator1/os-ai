<?php

namespace App\Library\Calendar;

use App\Enums\Business\BusinessServiceMode;
use App\Models\BusinessLocation;

/**
 * The one sentence for "where is this appointment held", from the canonical
 * Location. Shared by the public booking page and the booking notifications so a
 * guest never reads a different place in the email than on the confirmation page.
 * It invents nothing: an online Location says "Online" and carries no link —
 * joining details are the Booking Type's own meeting instructions.
 */
final class BookingLocationLabel
{
    public static function for(BusinessLocation $location): ?string
    {
        if ($location->service_mode === BusinessServiceMode::Online) {
            return 'Online';
        }

        $parts = $location->public_address
            ? array_filter([$location->address_line_1, $location->city, $location->region])
            : array_filter([$location->name]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
