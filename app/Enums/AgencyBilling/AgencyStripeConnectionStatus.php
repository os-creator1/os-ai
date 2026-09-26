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

    /**
     * Fail-closed payment-readiness policy — the connected account's real
     * Stripe `controller` configuration (fee payer, payment-loss liability,
     * requirement collection, or Dashboard access) does not match what this
     * platform requires (see `AgencyAccountSnapshot::isCompatibleController()`).
     * `stripe_dashboard.type` is immutable once an account exists, so unlike
     * Restricted this can never self-heal through more onboarding — the
     * Agency must disconnect and connect a different, compatible account.
     * Never chargeable, regardless of `charges_enabled`.
     */
    case Incompatible = 'incompatible';

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
