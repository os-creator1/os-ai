<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::issueManualCredit() (RFC-005 Admin Usage
 * Billing Surface Contract §2.3) when the supplied entry type is
 * anything other than UsageLedgerEntryType::ManualCredit or
 * ::PromotionalCredit. The manager never trusts the FormRequest's own
 * validation alone.
 */
class InvalidAdminCreditEntryTypeException extends RuntimeException
{
    public function __construct(public readonly string $entryType)
    {
        parent::__construct("Entry type [{$entryType}] is not an allowed manual-credit entry type.");
    }
}
