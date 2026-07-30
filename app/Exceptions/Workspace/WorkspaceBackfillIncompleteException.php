<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by WorkspaceBackfillV1 when, after every customer_id group has
 * been processed, businesses.workspace_id still has a non-zero null count
 * (RFC-003 §10.4) — a failed run must never be reported as a silent
 * success, and no raw database exception substitutes for this check.
 */
class WorkspaceBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly int $remainingNullCount)
    {
        parent::__construct(
            "Workspace backfill incomplete: {$remainingNullCount} Business row(s) still have a null workspace_id."
        );
    }
}
