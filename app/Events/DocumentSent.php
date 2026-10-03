<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — Blueprint §13's "proposal-sent follow-up"
 * starter automation is the one event an authority document explicitly
 * requires, so it exists.
 *
 * Numeric ids and scalars only, no PII: never the recipient's address, never
 * the plaintext token, never document content. A consumer that needs more
 * reads it through the owning module (Blueprint §31).
 *
 * IDENTITY. Every event the manager emits carries the document's own Business,
 * Location and Contact ids, taken from the locked row and never inferred, plus
 * a deterministic `occurrenceKey()` built from the persisted issued version —
 * one send of one version composes one key however many times the event is
 * replayed, which is what lets a consumer refuse a duplicate. A re-send of the
 * link (rotating the token) is NOT a lifecycle transition and emits nothing.
 *
 * `origin` IS AN OPAQUE CAUSATION REFERENCE, null for a person's own send (the same
 * contract as `ContactTagEvent::$origin`). The Automations engine passes
 * `automation_step_run:{id}` when it sends a document it created, so a consumer can
 * tell its own output from a person's and never re-trigger the workflow that made
 * it. This domain never reads it.
 *
 * This is a transient integration event, not an audit log (§10).
 */
final class DocumentSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $versionNumber,
        public readonly ?int $businessId = null,
        public readonly ?int $businessLocationId = null,
        public readonly ?int $contactId = null,
        public readonly ?string $origin = null,
    ) {
    }

    /** `document_version_sent:{versionId}` — one issued version, one occurrence. */
    public function occurrenceKey(): string
    {
        return 'document_version_sent:' . $this->versionId;
    }
}
