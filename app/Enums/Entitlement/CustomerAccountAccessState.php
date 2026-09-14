<?php

namespace App\Enums\Entitlement;

/**
 * Chat F — Customer Account Access Gate.
 *
 * The three states the reusable access gate currently resolves to, mapped
 * 1:1 from the canonical WorkspacePlanAssignmentStatus (Active/Inactive/
 * Suspended — there is no Trialing/TrialExpired case today). Future states
 * (trial expired, past due) are expected to add cases here, each mapped by
 * CustomerAccountAccessResolver from whatever canonical status eventually
 * represents them — never by scattering a new "if status === X" check
 * across controllers or views.
 */
enum CustomerAccountAccessState: string
{
    /** Normal product access. */
    case Usable = 'usable';

    /** WorkspacePlanAssignmentStatus::Inactive — a payment/billing path may restore it. */
    case LockedInactive = 'locked_inactive';

    /** WorkspacePlanAssignmentStatus::Suspended — administrative; payment is not assumed to fix it. */
    case LockedSuspended = 'locked_suspended';

    public function isLocked(): bool
    {
        return $this !== self::Usable;
    }
}
