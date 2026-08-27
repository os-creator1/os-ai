<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Receipt Boundary Correction Contract §7 — thrown by
 * SendReceiptNotification when UsageBillingCheckoutManager::ensureFundingReceipt()
 * returns null (provider payment-object identity/status check failed, or
 * receipt evidence was empty/absent). Accounting has already committed
 * before this job ever runs; this exception never affects it.
 */
class ReceiptEvidenceUnavailableException extends RuntimeException
{
    public function __construct(int $fundingAttemptId, int $ledgerEntryId)
    {
        parent::__construct("Receipt evidence unavailable for funding attempt {$fundingAttemptId} (ledger entry {$ledgerEntryId}).");
    }
}
