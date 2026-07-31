<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by addMember() (RFC-003 Milestone 2 domain contract) when the
 * supplied member user ID is the Workspace's own owner_user_id — the owner
 * is authoritative (RFC-003 §7.3) and never also holds a membership row.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class OwnerCannotBeMemberException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $ownerUserId,
    ) {
        parent::__construct(
            "User [{$ownerUserId}] is Workspace [{$workspaceId}]'s owner and cannot also be a member."
        );
    }
}
