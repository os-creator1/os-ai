<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(2) gate 4 — a Location-bound action whose
 * actor no longer has access to the bound Location
 * (`LocationAccessGuard::userCanAccessLocation`).
 *
 * Only evaluated for an action the registry marks `location_bound`; a
 * Business-level action has no Location to check and must not invent one.
 */
class OpportunityLocationAccessRevokedException extends OpportunityAuthorityRevokedException
{
    public static function forLocation(int $actorUserId, int $businessLocationId): self
    {
        return new self(
            "Actor [{$actorUserId}] may no longer act on Business Location [{$businessLocationId}]."
        );
    }
}
