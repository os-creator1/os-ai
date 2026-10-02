<?php

namespace App\Library\PlatformOwner;

use RuntimeException;

/**
 * Platform Owner / Admin V1 — restoreAccess() found no recorded Grace or
 * Locked state to clear (already restored, still only a running trial, or
 * the plan is inactive/suspended and owned by the existing plan-status
 * control). A refusal, not a failure: nothing was written and nothing was
 * audited.
 *
 * Carries only a numeric Workspace identifier.
 */
class NothingToRestoreException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] has no Grace or Locked state to restore.");
    }
}
