<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::issueManualCredit() (RFC-005 Admin Usage
 * Billing Surface Contract §2.3) when the supplied amount is not
 * strictly positive. The manager never trusts the FormRequest's own
 * validation alone.
 */
class InvalidAdminCreditAmountException extends RuntimeException
{
    public function __construct(public readonly int $amountMicro)
    {
        parent::__construct("Manual credit amount [{$amountMicro}] must be a positive number of micros.");
    }
}
