<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Exceptions\AgencyBilling\AgencyBillingException;

/**
 * Lane C §C4 — the one place a Stripe subscription status string becomes a
 * lane-C status, and the one place the handled event set is written down.
 *
 * A status we have no mapping for is REFUSED rather than guessed at: inventing
 * a meaning for an unknown provider state is how an account silently gets
 * access it has not paid for, or loses access it has.
 *
 * The event set is deliberately CLOSED. Stripe will happily send dozens of
 * other event types to a Connect endpoint — including every lane-B document
 * payment event, if an Agency happens to use one Stripe account for both. Lane
 * C acknowledges those and processes none of them.
 */
final class AgencyProviderStatusMap
{
    private const SUBSCRIPTION_STATUS = [
        'incomplete' => AgencyClientSubscriptionStatus::Incomplete,
        'incomplete_expired' => AgencyClientSubscriptionStatus::IncompleteExpired,
        'trialing' => AgencyClientSubscriptionStatus::Trialing,
        'active' => AgencyClientSubscriptionStatus::Active,
        'past_due' => AgencyClientSubscriptionStatus::PastDue,
        'canceled' => AgencyClientSubscriptionStatus::Canceled,
        'unpaid' => AgencyClientSubscriptionStatus::Unpaid,
        'paused' => AgencyClientSubscriptionStatus::Paused,
    ];

    private const HANDLED_EVENT_TYPES = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    public static function forSubscriptionStatus(string $providerStatus): AgencyClientSubscriptionStatus
    {
        return self::SUBSCRIPTION_STATUS[$providerStatus]
            ?? throw AgencyBillingException::because(AgencyBillingException::UNMAPPED_PROVIDER_STATUS);
    }

    public static function handlesEventType(string $eventType): bool
    {
        return in_array($eventType, self::HANDLED_EVENT_TYPES, true);
    }

    /** @return array<int, string> */
    public static function handledEventTypes(): array
    {
        return self::HANDLED_EVENT_TYPES;
    }
}
