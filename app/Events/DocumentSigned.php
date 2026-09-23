<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — the Blueprint §18 lifecycle transition
 * `sent -> signed`.
 *
 * Numeric ids and scalars only, no PII: never the signer's typed name, email
 * or IP address. Those are signature EVIDENCE and live in
 * business_document_signatures, which is the durable record; this event only
 * says that the transition happened.
 */
final class DocumentSigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $signatureId,
    ) {
    }
}
