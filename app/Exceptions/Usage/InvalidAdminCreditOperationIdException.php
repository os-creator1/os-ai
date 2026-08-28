<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::issueManualCredit() (RFC-005 Admin Usage
 * Billing Surface Contract §2.3, Correction Round 2) when the supplied
 * operation id, trimmed and lowercased, does not validate as a UUID.
 * The manager never trusts the FormRequest's own uuid rule alone.
 */
class InvalidAdminCreditOperationIdException extends RuntimeException
{
    public function __construct(public readonly string $operationId)
    {
        parent::__construct("Operation id [{$operationId}] is not a valid UUID.");
    }
}
