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
 * This is a transient integration event, not an audit log (§10).
 */
final class DocumentSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $versionNumber,
    ) {
    }
}
