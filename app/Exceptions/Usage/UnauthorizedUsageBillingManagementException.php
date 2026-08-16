<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by BillingProfileManager::updateBillingContact() and
 * UsageWalletManager::setSpendCap()/setFeatureLimit() (M2 contract §7)
 * when the acting user is not the Workspace owner, an active Admin whose
 * business_access_scope covers the target Business, or the direct
 * Business owner/customer. Staff is never authorized to mutate, even with
 * matching scope.
 *
 * Carries only numeric identifiers — never User or Business names,
 * company, email, phone, or address.
 */
class UnauthorizedUsageBillingManagementException extends RuntimeException
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $businessId,
    ) {
        parent::__construct(
            "User [{$actorUserId}] is not authorized to manage usage billing settings for Business [{$businessId}]."
        );
    }
}
