<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by assignBusinessToMember() and unassignBusinessFromMember()
 * (RFC-003 Milestone 2 domain contract, corrected report §2) when the
 * target WorkspaceMembership's current business_access_scope is not
 * `selected` — scoped Business grants only ever apply under `selected`
 * scope.
 *
 * Carries only a numeric WorkspaceMembership identifier — never Customer,
 * User or Business names, company, email, phone, or address.
 */
class BusinessAssignmentRequiresSelectedScopeException extends RuntimeException
{
    public function __construct(public readonly int $membershipId)
    {
        parent::__construct(
            "WorkspaceMembership [{$membershipId}] does not have the 'selected' Business access scope."
        );
    }
}
