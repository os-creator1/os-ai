<?php

namespace App\Library\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;

/**
 * Implementation Contract 21 §6/§12 — THE ONE SEAM where Stripe's subscription
 * vocabulary exists in lane A.
 *
 * Everything past this class speaks only PlatformSubscriptionStatus. That is
 * what lets the local lifecycle stay stable if the provider renames or adds a
 * status, and it mirrors the rule Contract 17 §11.8 already applies in lane B.
 *
 * FAIL CLOSED. An unmapped provider status raises rather than guessing a local
 * state: guessing on a subscription status is how a paying customer silently
 * loses access, or a delinquent one silently keeps it.
 *
 * The eight provider statuses below are the complete documented enum, verified
 * against Stripe's current API reference for the Subscription object.
 */
final class PlatformProviderStatusMap
{
    /** Stripe subscription status -> our §6 local status. */
    private const SUBSCRIPTION_STATUS = [
        'incomplete' => PlatformSubscriptionStatus::Incomplete,
        'incomplete_expired' => PlatformSubscriptionStatus::IncompleteExpired,
        'trialing' => PlatformSubscriptionStatus::Trialing,
        'active' => PlatformSubscriptionStatus::Active,
        'past_due' => PlatformSubscriptionStatus::PastDue,
        'canceled' => PlatformSubscriptionStatus::Canceled,
        'unpaid' => PlatformSubscriptionStatus::Unpaid,
        'paused' => PlatformSubscriptionStatus::Paused,
    ];

    /**
     * §12 — the CLOSED lane-A event set. Every other event type is
     * acknowledged and ignored with a reason code rather than guessed at.
     *
     * Chosen from Stripe's own stated meanings:
     *   checkout.session.completed    — the signup checkout finished
     *   customer.subscription.created — "subscription starts"
     *   customer.subscription.updated — "starts or changes": renewal, plan
     *                                   change, cancel-at-period-end, period roll
     *   customer.subscription.deleted — "a customer's subscription ends"
     *   invoice.paid                  — "provision access … when the
     *                                   subscription status is active"
     *   invoice.payment_failed        — "a payment for an invoice failed"
     */
    private const HANDLED_EVENT_TYPES = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    /**
     * @throws PlatformBillingException when the provider reports a status this
     *         contract has no mapping for
     */
    public static function forSubscriptionStatus(string $providerStatus): PlatformSubscriptionStatus
    {
        return self::SUBSCRIPTION_STATUS[$providerStatus]
            ?? throw PlatformBillingException::because(PlatformBillingException::UNMAPPED_PROVIDER_STATUS);
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
