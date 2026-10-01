<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.9/§11.8 — OUR local vocabulary, not Stripe's.
 * Provider statuses are mapped onto these six in exactly one gateway seam;
 * no provider status string belongs in domain code.
 *
 * created / requires_action / processing / failed are the LIVE statuses that hold
 * business_document_payments.active_schedule_item_id (the one-live-attempt
 * guard). `failed` is live because a provider payment_failed leaves the
 * PaymentIntent retryable: the customer's retry re-drives the same row and
 * intent. Only succeeded (settled) and canceled (the provider says the intent
 * is dead) are final and free the slot.
 */
enum BusinessDocumentPaymentStatus: string
{
    case Created = 'created';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
