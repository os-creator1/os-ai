<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by BillingProfileManager::changePayer() (M2 contract §7/§16) when
 * the acting user does not hold the specific consent authority the target
 * payer_type requires — only the Workspace owner may set 'workspace';
 * only the direct Business owner/customer may set 'business'; neither
 * side may set the other's, and no other role may set either.
 *
 * Carries only numeric identifiers — never User, Business, or Workspace
 * names, company, email, phone, or address.
 */
class UnauthorizedPayerAssignmentException extends RuntimeException
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $businessId,
        public readonly string $targetPayerType,
    ) {
        parent::__construct(
            "User [{$actorUserId}] is not authorized to set Business [{$businessId}]'s payer to [{$targetPayerType}]."
        );
    }
}
