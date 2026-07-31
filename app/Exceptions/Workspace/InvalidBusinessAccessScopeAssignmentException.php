<?php

namespace App\Exceptions\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use RuntimeException;

/**
 * Thrown wherever a Business-access-scope value is paired with an invalid
 * Business-ID set (RFC-003 Milestone 2 domain contract, corrected report
 * §2/§10) — specifically, scope `all` combined with a non-empty ID set.
 * Scope `selected` combined with an empty ID set is valid (means zero
 * Business access) and never throws this exception.
 *
 * Thrown both at ordinary scope-change call sites and inside
 * WorkspaceOwnershipTransferDisposition::convertToAdmin(), whose
 * construction-time validation reuses this exception so no separate
 * disposition-specific exception is needed.
 *
 * Carries only a closed enum value and a numeric count — never Customer,
 * User or Business names, company, email, phone, or address.
 */
class InvalidBusinessAccessScopeAssignmentException extends RuntimeException
{
    public function __construct(
        public readonly WorkspaceBusinessAccessScope $scope,
        public readonly int $suppliedIdCount,
    ) {
        parent::__construct(
            "Business access scope [{$scope->value}] cannot be combined with {$suppliedIdCount} supplied Business ID(s)."
        );
    }
}
