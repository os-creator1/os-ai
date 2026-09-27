<?php

namespace App\Console\Commands;

use App\Enums\Messaging\NumberRenewalOutcome;
use App\Library\Messaging\NumberLifecycleManager;
use Illuminate\Console\Command;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3's periodic
 * sweep: sends advance warnings, attempts due renewal charges, and sends
 * release notices once a suspended number's grace period has expired.
 *
 * Never decides to release a number itself — that is
 * NumberLifecycleManager::recordReleaseDecision(), an explicit, audited,
 * admin-only action this command never calls, and even that action never
 * itself confirms a real carrier release. A quiet run (0 due, 0 warned, 0
 * release notices dispatched) is the expected, safe default today: no
 * number accumulates a real renewal charge without an owner-activated
 * rate for NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, so
 * attemptRenewal() reports NotConfigured and this command counts that
 * separately from a genuine insufficient-funds suspension — it is never
 * treated as a failure. AlreadyProcessed (a concurrent run already
 * resolved this number's due date under the row lock) is likewise never a
 * failure.
 */
class SweepMessagingNumberRenewals extends Command
{
    protected $signature = 'messaging:sweep-number-renewals';

    protected $description = 'Send renewal advance warnings, attempt due number-rental renewals, and send release notices once grace has expired.';

    public function handle(NumberLifecycleManager $lifecycle): int
    {
        $warned = 0;

        foreach ($lifecycle->numbersNeedingAdvanceWarning() as $number) {
            if ($lifecycle->sendAdvanceWarningIfNeeded($number)) {
                $warned++;
            }
        }

        $succeeded = 0;
        $suspended = 0;
        $notConfigured = 0;
        $alreadyProcessed = 0;

        foreach ($lifecycle->numbersDueForRenewal() as $number) {
            match ($lifecycle->attemptRenewal($number)) {
                NumberRenewalOutcome::Succeeded => $succeeded++,
                NumberRenewalOutcome::InsufficientFunds => $suspended++,
                NumberRenewalOutcome::NotConfigured => $notConfigured++,
                NumberRenewalOutcome::AlreadyProcessed => $alreadyProcessed++,
            };
        }

        $releaseNoticesDispatched = 0;

        foreach ($lifecycle->numbersEligibleForReleaseNotice() as $number) {
            $lifecycle->sendReleaseNotice($number);
            $releaseNoticesDispatched++;
        }

        $this->info(sprintf(
            'Advance warnings sent: %d. Renewals — succeeded: %d, suspended (insufficient funds): %d, not yet configured: %d, already processed: %d. Release notices dispatched: %d.',
            $warned,
            $succeeded,
            $suspended,
            $notConfigured,
            $alreadyProcessed,
            $releaseNoticesDispatched,
        ));

        return self::SUCCESS;
    }
}
