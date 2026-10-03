<?php

namespace App\Exceptions\Documents;

use RuntimeException;

/**
 * Implementation Contract 17B §5/§6 — a template edit named an
 * `expected_lock_version` that is no longer the template's. Another tab saved
 * first; the caller must reload. HTTP 409 carrying the CURRENT version.
 */
final class DocumentTemplateConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentLockVersion)
    {
        parent::__construct('This template was changed somewhere else. Reload it to see the latest version.');
    }
}
