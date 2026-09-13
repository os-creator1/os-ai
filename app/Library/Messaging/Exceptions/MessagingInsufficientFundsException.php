<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * PR #295 Correction Round 1, item 4 — thrown when UsageWalletManager's own
 * reserve() denies the reservation (insufficient balance, a spend cap, an
 * outstanding-debt or paused-activity gate) for a cost-incurring
 * provisioning action. Always thrown BEFORE the real provider request is
 * made. Carries the wallet's own denial reason verbatim (never a guessed
 * one) for support/diagnostic use; never shown to the customer as-is.
 */
class MessagingInsufficientFundsException extends RuntimeException
{
    public function __construct(public readonly ?string $denialReason)
    {
        parent::__construct(sprintf('Messaging funding reservation denied: %s', $denialReason ?? 'unknown'));
    }
}
