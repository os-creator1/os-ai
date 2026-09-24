<?php

namespace App\Enums\AgencyBilling;

/**
 * Lane C §C3.4 — the state of ONE client's subscription to ONE Agency.
 *
 * Stripe's own documented subscription statuses, plus the two states that
 * exist before the provider has anything to say:
 *
 *   Offered — the Agency has proposed a plan and snapshotted its terms. NO
 *             provider call has happened, NO card exists, and nothing is owed.
 *             This is the state §C6 insists on: an offer is a proposal, and an
 *             Agency may not manufacture its client's financial consent.
 *   Pending — the client has consented and a Checkout Session is open. Still
 *             not paid; still no access granted.
 *
 * Everything after that is provider truth, written only by the finalizer.
 */
enum AgencyClientSubscriptionStatus: string
{
    case Offered = 'offered';
    case Pending = 'pending';

    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Paused = 'paused';

    /** The client is entitled to use the product on the Agency's dime. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** The relationship is over; only a NEW subscription can revive it. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::IncompleteExpired], true);
    }

    /** A provider subscription exists (or is being created) for this row. */
    public function isLive(): bool
    {
        return ! in_array($this, [self::Offered, self::Pending, self::Canceled, self::IncompleteExpired], true);
    }

    /** Nothing has reached the provider yet. */
    public function isPreProvider(): bool
    {
        return in_array($this, [self::Offered, self::Pending], true);
    }
}
