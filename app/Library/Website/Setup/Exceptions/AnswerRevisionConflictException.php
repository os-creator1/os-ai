<?php

namespace App\Library\Website\Setup\Exceptions;

use DomainException;

/**
 * Website Builder redesign — autosave submitted a stale
 * `answers_revision` (another tab, or a duplicate/retried request, saved
 * first). The caller should reload the response and retry with the
 * current revision, never silently overwrite what the other write
 * already saved.
 */
class AnswerRevisionConflictException extends DomainException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('This answer was already saved from another tab or device. Reload and try again.');
    }
}
