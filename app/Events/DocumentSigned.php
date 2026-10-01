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
 *
 * Carries the document's Business, Location and Contact ids and a
 * deterministic `occurrenceKey()` from the persisted signature row (there is
 * exactly one per document), and is emitted only for the submission that
 * actually wrote that row — never for a replay of it.
 */
final class DocumentSigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $signatureId,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
    ) {
    }

    /** `document_signature:{signatureId}` — one signature row, one occurrence. */
    public function occurrenceKey(): string
    {
        return 'document_signature:' . $this->signatureId;
    }
}
