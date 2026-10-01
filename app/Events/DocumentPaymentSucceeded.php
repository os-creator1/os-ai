<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — Blueprint §24's "payment events reach the
 * Activity Center". Numeric ids only: no amount, no provider reference, no
 * client secret, no PII.
 *
 * Dispatched AFTER COMMIT, and at most once per payment row: the finalizer
 * emits it only on the single transition into `succeeded`, so a replayed
 * webhook or a reconciliation re-observation never re-emits it. It is the
 * stable "payment received" hook a later central Automations trigger can
 * subscribe to.
 *
 * `businessId`, `businessLocationId` and `contactId` are the document's own
 * tenant identity, carried so a consumer never has to re-derive it from a
 * table it may not be allowed to read. They are trailing and optional so the
 * two-argument form every earlier caller used keeps working.
 */
final class DocumentPaymentSucceeded
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
