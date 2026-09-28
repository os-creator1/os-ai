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
 *
 * AlreadyProcessed means a concurrent call already renewed (or otherwise
 * resolved) this number's due date under the row lock before this call
 * acquired it — never a failure, and never re-charged or re-logged.
 */
enum NumberRenewalOutcome: string
{
    case Succeeded = 'succeeded';
    case InsufficientFunds = 'insufficient_funds';
    case NotConfigured = 'not_configured';
    case AlreadyProcessed = 'already_processed';
}
