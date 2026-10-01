<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — the Blueprint §18 lifecycle transition to
 * `expired`.
 *
 * §8.6 makes this narrow on purpose: only an UNSIGNED, UNPAID `sent` document
 * can reach it, so this event can never announce that signed or partly-paid
 * money was stranded. Carries the document's Business, Location and Contact
 * ids; expiry is terminal, so the document id is the stable occurrence
 * identity.
 */
final class DocumentExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
    ) {
    }

    /** `document_expired:{documentId}` — expiry is terminal, so once per document. */
    public function occurrenceKey(): string
    {
        return 'document_expired:' . $this->documentId;
    }
}
