<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::issueManualCredit() (RFC-005 Admin Usage
 * Billing Surface Contract §2.3) when the supplied reason is blank
 * after trimming. The manager never trusts the FormRequest's own
 * validation alone.
 */
class InvalidAdminCreditReasonException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("A mandatory reason is required to issue a manual credit for Business #{$businessId}.");
    }
}
