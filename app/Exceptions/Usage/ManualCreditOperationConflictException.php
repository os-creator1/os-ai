<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::issueManualCredit() (RFC-005 Admin Usage
 * Billing Surface Contract §2.3) when an existing ledger row already
 * uses the same deterministic correlation key but its own normalized
 * Business/type/amount/actor/reason payload differs from the current
 * call's own. An identical replay of the same operation id returns the
 * original row instead of reaching this exception; only a genuinely
 * different payload reused against the same operation id throws it.
 */
class ManualCreditOperationConflictException extends RuntimeException
{
    public function __construct(public readonly string $correlationKey)
    {
        parent::__construct("A manual credit already exists for correlation key [{$correlationKey}] with a different payload.");
    }
}
