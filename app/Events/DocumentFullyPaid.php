<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — Blueprint §18's `signed/sent → paid`
 * transition. Dispatched AFTER COMMIT, exactly once per document: the
 * finalizer emits it only on the transition that makes the document `paid`,
 * and a `paid` document accepts no further transition. It is the stable
 * "invoice paid" hook a later central Automations trigger can subscribe to.
 *
 * Identity only — no amount, no provider reference, no PII. The tenant
 * identity is trailing and optional so the one-argument form keeps working.
 */
final class DocumentFullyPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
    ) {
    }
}
