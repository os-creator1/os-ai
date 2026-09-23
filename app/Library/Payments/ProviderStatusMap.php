<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Exceptions\Payments\StripeConnectException;

/**
 * Implementation Contract 17 §11.8 — THE ONE SEAM where Stripe's status
 * vocabulary exists.
 *
 * "No provider status string may leak into manager, domain, controller or
 * Blade code." Everything past this class speaks only the six local values of
 * §5.9, which is what lets the local lifecycle stay stable if the provider
 * ever renames or adds a status.
 *
 * FAIL CLOSED. An unmapped provider status raises rather than guessing a
 * local state: guessing on a payment status is how money silently goes
 * missing. §12.E test (l) asserts exactly this.
 *
 * On `requires_payment_method`: Stripe uses that one string BOTH for a
 * freshly created intent and for one whose last attempt failed. The intent
 * status alone therefore cannot distinguish them, and this class does not
 * pretend otherwise — it maps to `created`, and the failure case is carried
 * by the EVENT TYPE (`payment_intent.payment_failed`), which is the only
 * signal that actually says "this attempt failed".
 */
final class ProviderStatusMap
{
    /** Stripe PaymentIntent status -> our §5.9 local status. */
    private const INTENT_STATUS = [
        'requires_payment_method' => BusinessDocumentPaymentStatus::Created,
        'requires_confirmation' => BusinessDocumentPaymentStatus::Created,
        'requires_action' => BusinessDocumentPaymentStatus::RequiresAction,
        'processing' => BusinessDocumentPaymentStatus::Processing,
        // We never use manual capture, so an authorized-but-uncaptured intent
        // is in flight rather than settled. It is mapped explicitly rather
        // than left to fail closed, because Stripe can produce it.
        'requires_capture' => BusinessDocumentPaymentStatus::Processing,
        'succeeded' => BusinessDocumentPaymentStatus::Succeeded,
        'canceled' => BusinessDocumentPaymentStatus::Canceled,
    ];

    /**
     * The lane-B event types this slice acts on. Anything else is recorded
     * and ignored rather than guessed at (§8.3).
     */
    private const EVENT_STATUS = [
        'payment_intent.succeeded' => BusinessDocumentPaymentStatus::Succeeded,
        'payment_intent.processing' => BusinessDocumentPaymentStatus::Processing,
        'payment_intent.payment_failed' => BusinessDocumentPaymentStatus::Failed,
        'payment_intent.requires_action' => BusinessDocumentPaymentStatus::RequiresAction,
        'payment_intent.canceled' => BusinessDocumentPaymentStatus::Canceled,
    ];

    /**
     * @throws StripeConnectException when the provider reports a status this
     *         contract has no mapping for
     */
    public static function forIntentStatus(string $providerStatus): BusinessDocumentPaymentStatus
    {
        return self::INTENT_STATUS[$providerStatus]
            ?? throw StripeConnectException::unmappedProviderStatus();
    }

    /** Null when the event type is not one this slice acts on. */
    public static function forEventType(string $eventType): ?BusinessDocumentPaymentStatus
    {
        return self::EVENT_STATUS[$eventType] ?? null;
    }

    public static function handlesEventType(string $eventType): bool
    {
        return array_key_exists($eventType, self::EVENT_STATUS);
    }

    // =================================================================
    // Sub-slice F — refunds
    // =================================================================

    /** Stripe Refund status -> our §5.9 local refund status. */
    private const REFUND_STATUS = [
        'pending' => BusinessDocumentRefundStatus::Pending,
        // The customer must complete a step before the refund settles; it is
        // still in flight, so it still RESERVES capacity (§8.7).
        'requires_action' => BusinessDocumentRefundStatus::Pending,
        'succeeded' => BusinessDocumentRefundStatus::Succeeded,
        'failed' => BusinessDocumentRefundStatus::Failed,
        // A canceled refund never moved money, so it releases its reservation
        // exactly like a failed one.
        'canceled' => BusinessDocumentRefundStatus::Failed,
    ];

    /**
     * §8.3/§4.5 — refund events are routed by EVENT TYPE, never by metadata.
     * Stripe's Charge/Dispute/Refund metadata is independent and is never
     * inherited from the originating PaymentIntent, so an app_operation_id
     * cannot be relied on to appear on a refund object at all.
     *
     * EVERY TYPE HERE CARRIES A REFUND OBJECT IN `data.object`. `charge.refunded`
     * is deliberately absent even though it sounds like the obvious one: its
     * `data.object` is a CHARGE, whose refunds sit in a nested paginated list.
     * Admitting it would mean one handler silently parsing two different object
     * shapes, and the refund's own events already carry the authoritative
     * status — so a charge-level event is recorded and ignored rather than
     * half-understood.
     */
    private const REFUND_EVENT_TYPES = [
        'refund.created',
        'refund.updated',
        'refund.failed',
        // The legacy name for the same refund-object event.
        'charge.refund.updated',
    ];

    /**
     * @throws StripeConnectException when the provider reports a refund status
     *         this contract has no mapping for
     */
    public static function forRefundStatus(string $providerStatus): BusinessDocumentRefundStatus
    {
        return self::REFUND_STATUS[$providerStatus]
            ?? throw StripeConnectException::unmappedProviderStatus();
    }

    public static function isRefundEventType(string $eventType): bool
    {
        return in_array($eventType, self::REFUND_EVENT_TYPES, true);
    }
}
