<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — NumberLifecycleManager::attemptRenewal()'s
 * result. NotConfigured and InsufficientFunds are deliberately distinct:
 * NotConfigured means no UsageMeter/rate exists yet for the number-rental
 * feature key at all (nothing to charge, nothing failed, never a
 * suspension trigger); InsufficientFunds means a real rate exists and the
 * Business's own wallet balance could not cover it (§13.2's actual
 * "renewal charge requires sufficient balance" failure, which does
 * trigger the suspend-then-grace path).
 */
enum NumberRenewalOutcome: string
{
    case Succeeded = 'succeeded';
    case InsufficientFunds = 'insufficient_funds';
    case NotConfigured = 'not_configured';
}
