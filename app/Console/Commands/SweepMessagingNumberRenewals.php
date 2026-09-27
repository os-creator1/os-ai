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
 * Never releases a number itself — release is NumberLifecycleManager::release(),
 * an explicit, audited, admin-only action this command never calls. A
 * quiet run (0 due, 0 warned, 0 released-notice) is the expected, safe
 * default today: no number accumulates a real renewal charge without an
 * owner-activated rate for NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL,
 * so attemptRenewal() reports NotConfigured and this command counts that
 * separately from a genuine insufficient-funds suspension — it is never
 * treated as a failure.
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

        foreach ($lifecycle->numbersDueForRenewal() as $number) {
            match ($lifecycle->attemptRenewal($number)) {
                NumberRenewalOutcome::Succeeded => $succeeded++,
                NumberRenewalOutcome::InsufficientFunds => $suspended++,
                NumberRenewalOutcome::NotConfigured => $notConfigured++,
            };
        }

        $releaseNoticesSent = 0;

        foreach ($lifecycle->numbersEligibleForReleaseNotice() as $number) {
            $lifecycle->sendReleaseNotice($number);
            $releaseNoticesSent++;
        }

        $this->info(sprintf(
            'Advance warnings sent: %d. Renewals — succeeded: %d, suspended (insufficient funds): %d, not yet configured: %d. Release notices sent: %d.',
            $warned,
            $succeeded,
            $suspended,
            $notConfigured,
            $releaseNoticesSent,
        ));

        return self::SUCCESS;
    }
}
