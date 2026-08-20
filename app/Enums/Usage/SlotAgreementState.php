<?php

namespace App\Enums\Usage;

/**
 * additional_business_slot_agreements.state (RFC-005 §18/§22, M4 contract
 * §18/§19). RefundPending/Refunded exist at the schema/enum level only —
 * no M4 code path ever writes them (M4 contract §8a).
 */
enum SlotAgreementState: string
{
    case QuoteCreated = 'quote_created';
    case CheckoutPending = 'checkout_pending';
    case PaymentSucceeded = 'payment_succeeded';
    case AllocationPending = 'allocation_pending';
    case Completed = 'completed';
    case PaymentFailed = 'payment_failed';
    case AllocationFailed = 'allocation_failed';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
}
