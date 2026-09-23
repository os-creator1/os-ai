<?php

namespace App\Enums\PlatformBilling;

/**
 * Implementation Contract 21 §6 — lane A's OWN subscription vocabulary.
 *
 * The provider's status strings die in one mapping seam
 * (PlatformProviderStatusMap); nothing past that seam speaks Stripe. That is
 * what keeps the local lifecycle stable if the provider ever renames or adds a
 * status, and it is the same rule Contract 17 §11.8 applies in lane B.
 *
 * `Pending` has no provider counterpart and is deliberately ours: it is the
 * state of a durable row that exists because a customer started checkout, but
 * about which the provider has confirmed nothing yet. §7 forbids fabricating a
 * paid Active state without a provider result, and this is the value that
 * makes "not yet confirmed" representable instead of guessable.
 */
enum PlatformSubscriptionStatus: string
{
    /** Local only — checkout started, nothing provider-confirmed yet. */
    case Pending = 'pending';

    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Paused = 'paused';

    /**
     * The statuses in which the customer is entitled to use the product.
     *
     * `past_due` is deliberately INCLUDED: Blueprint §27 keeps full access
     * through the 3-day Grace window, and past_due is exactly the provider
     * state that opens it. Access is withdrawn by the Grace sweep reaching
     * Locked, never by the provider status alone.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /**
     * Terminal from lane A's point of view: the subscription is over and no
     * further provider event can revive THIS provider subscription. A returning
     * customer gets a new provider subscription on the same local row.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::IncompleteExpired], true);
    }
}
