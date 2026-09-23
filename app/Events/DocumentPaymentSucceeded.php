<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — Blueprint §24's "payment events reach the
 * Activity Center". Numeric ids only: no amount, no provider reference, no
 * client secret, no PII.
 */
final class DocumentPaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $paymentId,
    ) {
    }
}
