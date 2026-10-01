<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The Blueprint §18 lifecycle transition `-> void`. Carries the document's
 * Business, Location and Contact ids; void is terminal, so the document id is
 * itself the stable occurrence identity.
 */
final class DocumentVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
    ) {
    }

    /** `document_voided:{documentId}` — void is terminal, so once per document. */
    public function occurrenceKey(): string
    {
        return 'document_voided:' . $this->documentId;
    }
}
