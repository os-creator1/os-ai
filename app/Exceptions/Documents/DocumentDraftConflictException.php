<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17B §5 — a draft mutation named an
 * `expected_lock_version` that is no longer the open draft's. Another tab (or
 * another person) saved first; the caller must reload before saving again.
 * HTTP 409 carrying the CURRENT version.
 */
final class DocumentDraftConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentLockVersion)
    {
        parent::__construct('This document was changed somewhere else. Reload it to see the latest version.');
    }
}
