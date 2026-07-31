<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by addMember() (RFC-003 Milestone 2 domain contract, corrected
 * report §4) when a WorkspaceMembership already exists for the requested
 * (workspace, user) pair and does not exactly match the requested role,
 * scope, and normalized selected Business-ID set — an exact match is an
 * authorized no-op instead. Also thrown when the existing row is inactive,
 * directing the caller to reactivateMember() rather than silently
 * resurrecting removed access.
 *
 * Carries only numeric identifiers and a boolean state flag — never
 * Customer, User or Business names, company, email, phone, or address.
 */
class WorkspaceMembershipAlreadyExistsException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
        public readonly bool $isActive,
    ) {
        $state = $isActive ? 'active' : 'inactive';

        parent::__construct(
            "WorkspaceMembership already exists for Workspace [{$workspaceId}], User [{$userId}] ({$state})."
        );
    }
}
