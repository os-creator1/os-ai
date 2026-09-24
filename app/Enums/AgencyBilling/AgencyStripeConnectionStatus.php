<?php

namespace App\Enums\AgencyBilling;

/**
 * Lane C §C3.1 — the readiness of the Stripe account that receives ONE
 * Agency's SaaS revenue.
 *
 * Deliberately its own enum rather than a reuse of
 * `App\Enums\Documents\StripeConnectionStatus`. That one belongs to lane B's
 * Business connections; an Agency's revenue account is a different commercial
 * identity even when the underlying Stripe account is physically the same one,
 * and sharing the type is the first step towards sharing the row.
 */
enum AgencyStripeConnectionStatus: string
{
    /** Created at the provider, onboarding not started. */
    case Pending = 'pending';

    /** The Agency is partway through Stripe's hosted onboarding. */
    case Onboarding = 'onboarding';

    /** Charges enabled: the Agency can be paid. */
    case Active = 'active';

    /** The provider has restricted the account; new charges must not be taken. */
    case Restricted = 'restricted';

    /** Retired by the Agency. Kept for history, never reused. */
    case Disconnected = 'disconnected';

    /** May this connection take a new lane-C charge right now? */
    public function canCharge(): bool
    {
        return $this === self::Active;
    }

    /** Is this the Agency's CURRENT connection, whatever its readiness? */
    public function isCurrent(): bool
    {
        return $this !== self::Disconnected;
    }
}
