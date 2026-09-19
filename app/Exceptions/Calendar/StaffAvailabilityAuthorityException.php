<?php

namespace App\Exceptions\Calendar;

use RuntimeException;

/**
 * Implementation Contract 15 §6 — the availability-authority table refused
 * this write.
 *
 * DELIBERATELY DISTINCT FROM A TENANCY FAILURE. A Location the actor cannot
 * reach, a Booking Type from another Business, or an unentitled feature all
 * 404: the actor must not learn the resource exists. This exception means
 * something different — the actor legitimately reached the Location and the
 * surface, and is simply not permitted to write THIS target's availability
 * (an Admin or Staff member editing somebody else, or an Owner reaching for
 * a staff member who is not currently eligible at that Location). The
 * controller renders it as a 403, matching
 * BusinessLocationsController's own precedent for a role refusal on a
 * resource the actor can see.
 */
class StaffAvailabilityAuthorityException extends RuntimeException
{
    public static function notSelf(int $actorUserId, int $targetStaffUserId): self
    {
        return new self(
            "User [{$actorUserId}] may manage only their own availability; target was [{$targetStaffUserId}]."
        );
    }

    public static function targetNotEligible(int $targetStaffUserId, int $locationId): self
    {
        return new self(
            "Staff member [{$targetStaffUserId}] is not currently eligible at Location [{$locationId}]."
        );
    }
}
