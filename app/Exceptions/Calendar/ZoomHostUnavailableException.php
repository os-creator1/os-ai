<?php

namespace App\Exceptions\Calendar;

/**
 * A Zoom appointment was requested but there is no usable Zoom host for it (Zoom not available to this Business, or the
 * assigned staff member / configured shared host has not connected Zoom). Refused BEFORE any lock or write, so no
 * appointment without a possible video link is ever created.
 */
class ZoomHostUnavailableException extends BookingRefusedException
{
    public static function forStaff(int $staffUserId): self
    {
        return new self("No connected Zoom host for staff member [{$staffUserId}].");
    }
}
