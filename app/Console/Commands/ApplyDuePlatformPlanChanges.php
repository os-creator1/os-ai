<?php

namespace App\Console\Commands;

use App\Library\PlatformBilling\PlatformSubscriptionManager;
use Illuminate\Console\Command;

/**
 * Implementation Contract 21 §10.2 — apply downgrades whose billing period has
 * ended.
 *
 * A downgrade takes effect at the END of the period the customer already paid
 * for, so something has to notice that the boundary arrived. That is this
 * command: bounded, idempotent, and following the SweepExpiredOpportunitySnoozes
 * convention the repository already uses for scheduled work — the command owns
 * the `--limit` validation and `self::INVALID`, the manager owns every decision
 * about the rows.
 *
 * There is deliberately NO feature flag here. Lane A is not optional
 * configuration: once a customer has scheduled a downgrade, failing to apply it
 * would keep charging them for a tier they cancelled. The bounded batch and the
 * manager's own per-row transaction are the safety, not a switch.
 */
class ApplyDuePlatformPlanChanges extends Command
{
    protected $signature = 'platform-subscriptions:apply-due-plan-changes
        {--limit=100 : Maximum number of scheduled plan changes to apply}';

    protected $description = 'Apply platform subscription downgrades whose billing period has ended';

    public function handle(PlatformSubscriptionManager $manager): int
    {
        $rawLimit = $this->option('limit');

        if (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $manager->applyDuePendingPlanChanges($limit);

        $this->info("Applied {$count} scheduled plan change(s).");

        return self::SUCCESS;
    }
}
