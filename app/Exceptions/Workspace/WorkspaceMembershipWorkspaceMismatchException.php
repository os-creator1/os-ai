<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by changeMemberRole(), deactivateMember() and reactivateMember()
 * (RFC-003 Milestone 2 Slice 2D authoritative-state correction) when the
 * freshly locked Membership's workspace_id does not match the freshly
 * locked Workspace it was looked up against — the caller-supplied
 * Membership's workspace_id was stale or mutated relative to the
 * authoritative locked rows.
 *
 * Carries only numeric identifiers — never Customer, User, Workspace or
 * Business names, company, email, phone, or address.
 */
class WorkspaceMembershipWorkspaceMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $membershipId,
        public readonly int $expectedWorkspaceId,
        public readonly int $actualWorkspaceId,
    ) {
        parent::__construct(
            "Membership [{$membershipId}] was expected to belong to Workspace [{$expectedWorkspaceId}] " .
            "but currently belongs to Workspace [{$actualWorkspaceId}]."
        );
    }
}
