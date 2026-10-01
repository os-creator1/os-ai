<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payments & Invoices V1 — a provider-confirmed FAILED payment attempt.
 *
 * Dispatched AFTER COMMIT, at most once per payment row, and only for a
 * payment against a still-live document: the finalizer emits it solely on the
 * single transition into `failed` (a replay of the same failure is a no-op),
 * so a consumer can treat it as idempotent. It is the stable "payment failed"
 * hook a later central Automations trigger can subscribe to; nothing consumes
 * it yet.
 *
 * Identity only — no amount, no failure detail, no provider reference, no
 * PII. A failure the customer can retry is still a failure of THIS attempt:
 * the retry is a new attempt with its own row, and its own events.
 */
final class DocumentPaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $paymentId,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
    ) {
    }
}
