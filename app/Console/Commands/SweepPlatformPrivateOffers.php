<?php

namespace App\Console\Commands;

use App\Library\PlatformBilling\Offers\OfferReconciler;
use App\Library\PlatformBilling\Offers\PrivateOfferManager;
use App\Library\PlatformBilling\Offers\PrivateOfferRedemptionManager;
use Illuminate\Console\Command;

/**
 * Private Offers' housekeeping, bounded and idempotent:
 *
 *   1. release reservations whose Checkout window closed unpaid (a Checkout the
 *      provider says was PAID is never released — the webhook confirms it);
 *   2. tell the provider about temporary promotions it has not been told about
 *      yet (a failure at redemption time leaves the schedule `pending`);
 *   3. re-drive billing changes a provider failure left half-applied.
 *
 * Like platform-subscriptions:apply-due-plan-changes there is no feature flag:
 * a skipped sweep would leave a customer on a promotional price for ever.
 */
class SweepPlatformPrivateOffers extends Command
{
    protected $signature = 'platform-offers:sweep
        {--limit=100 : Maximum number of rows to handle in each pass}';

    protected $description = 'Release lapsed private-offer reservations and finish pending promotion schedules';

    public function handle(
        PrivateOfferManager $offers,
        OfferReconciler $reconciler,
        PrivateOfferRedemptionManager $redemptions,
    ): int {
        $rawLimit = $this->option('limit');

        if (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $released = $offers->sweepExpiredReservations($limit);
        $scheduled = $reconciler->sweepPendingSchedules($limit);
        $changes = $redemptions->sweepUnappliedBillingChanges($limit);

        $this->info("Released {$released} lapsed reservation(s); scheduled {$scheduled} promotion(s); applied {$changes} billing change(s).");

        return self::SUCCESS;
    }
}
