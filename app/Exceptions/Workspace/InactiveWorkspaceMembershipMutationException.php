<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown by changeMemberRole(), changeMemberBusinessAccessScope(),
 * assignBusinessToMember() and unassignBusinessFromMember() (RFC-003
 * Milestone 2 domain contract, corrected report §3) when the target
 * WorkspaceMembership is inactive — deactivateMember() and
 * reactivateMember() are the only two mutations permitted against an
 * inactive membership, and duplicate calls to either are authorized no-ops
 * rather than throwing this exception.
 *
 * Carries only a numeric WorkspaceMembership identifier — never Customer,
 * User or Business names, company, email, phone, or address.
 */
class InactiveWorkspaceMembershipMutationException extends RuntimeException
{
    public function __construct(public readonly int $membershipId)
    {
        parent::__construct(
            "WorkspaceMembership [{$membershipId}] is inactive and cannot be mutated."
        );
    }
}
