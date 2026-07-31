<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by reassignBusiness() (RFC-003 Milestone 2 domain contract,
 * corrected report §2/§4) when the freshly locked Business's workspace_id
 * no longer matches the expected source Workspace captured before locks
 * were acquired — a stale reference, most likely because another operation
 * already reassigned this Business.
 *
 * Carries only numeric identifiers — never Customer, User or Business
 * names, company, email, phone, or address.
 */
class BusinessWorkspaceMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $businessId,
        public readonly int $expectedWorkspaceId,
        public readonly int $actualWorkspaceId,
    ) {
        parent::__construct(
            "Business [{$businessId}] was expected to belong to Workspace [{$expectedWorkspaceId}] " .
            "but currently belongs to Workspace [{$actualWorkspaceId}]."
        );
    }
}
