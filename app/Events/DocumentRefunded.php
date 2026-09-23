<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — Blueprint §24's "payment events reach the
 * Activity Center"; Blueprint §18 names refunds.
 *
 * Numeric ids only: no amount, no provider reference, no PII. A refund never
 * moves the document out of `paid` (§5.9), so this event reports a money
 * movement, never a lifecycle reversal.
 */
final class DocumentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $paymentId,
        public readonly int $refundId,
    ) {
    }
}
