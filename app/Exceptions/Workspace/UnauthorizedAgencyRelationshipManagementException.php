<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by AgencyClientRelationshipManager's authority assertions
 * (Implementation Contract 01 §6) when the acting user may not establish or
 * terminate an Agency -> Client Workspace management relationship.
 *
 * Deliberately NOT UnauthorizedWorkspaceManagementException: ordinary
 * Workspace management and Agency relationship management are different
 * authorities with different actor sets, and a caller must be able to tell
 * them apart.
 *
 * Carries only numeric identifiers — never Customer, User or Business names,
 * company, email, phone, or address.
 */
class UnauthorizedAgencyRelationshipManagementException extends RuntimeException
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $agencyWorkspaceId,
    ) {
        parent::__construct(
            "User [{$actorUserId}] is not authorized to manage Agency client relationships for Workspace [{$agencyWorkspaceId}]."
        );
    }
}
