<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when an actor tries to remove their own membership. Carries only
 * numeric identifiers.
 */
class CannotRemoveSelfException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId, public readonly int $userId)
    {
        parent::__construct("User [{$userId}] cannot remove their own membership of Workspace [{$workspaceId}].");
    }
}
