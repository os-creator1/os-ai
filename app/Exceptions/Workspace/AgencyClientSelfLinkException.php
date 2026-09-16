<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when a Workspace would be established as the managing Agency of
 * itself (Implementation Contract 01 §5).
 *
 * Enforced here rather than by a database CHECK constraint: no migration in
 * this repository uses a raw CHECK, and introducing one only for this rule
 * would be inconsistent with every other cross-column invariant in the
 * codebase, which are all asserted at write time in the domain layer.
 *
 * Carries only a numeric identifier.
 */
class AgencyClientSelfLinkException extends RuntimeException
{
    public function __construct(public readonly int $workspaceId)
    {
        parent::__construct("Workspace [{$workspaceId}] cannot be established as the managing Agency of itself.");
    }
}
