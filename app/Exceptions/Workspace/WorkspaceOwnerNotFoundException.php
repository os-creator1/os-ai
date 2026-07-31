<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by WorkspaceManager::createWorkspace() (RFC-003 Milestone 2
 * domain contract) when the requested owner User cannot be locked/found —
 * the typed Workspace-domain boundary exception in place of the generic
 * ModelNotFoundException. Scoped to createWorkspace() only;
 * resolveLegacyOnboardingWorkspace() keeps its own existing
 * ModelNotFoundException behavior unchanged.
 *
 * Carries only a numeric User identifier — never Customer, User or
 * Business names, company, email, phone, or address.
 */
class WorkspaceOwnerNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $ownerUserId)
    {
        parent::__construct("Owner User [{$ownerUserId}] does not exist.");
    }
}
