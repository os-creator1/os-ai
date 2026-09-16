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

    /**
     * Contract 03 §5 — the post-Grace, pre-Inactive state: the base status is
     * still Active, but the 3-day Grace window elapsed without payment
     * (`locked_at` set, or `grace_started_at` older than the window even if a
     * scheduled run has not written `locked_at` yet).
     *
     * Grace itself deliberately has NO case here: Blueprint §27 keeps full
     * access during Grace, so it stays Usable and travels as a non-blocking
     * hint on CustomerAccountAccessDecision instead. A Trial is likewise not a
     * state — it is Usable with trial metadata.
     */
    case Locked = 'locked';

    public function isLocked(): bool
    {
        return $this !== self::Usable;
    }
}
