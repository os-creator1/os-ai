<?php

namespace App\Console\Commands;

use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use Illuminate\Console\Command;

/**
 * Lane C §C7 — apply client downgrades whose billing period has ended.
 *
 * A downgrade takes effect at the END of the period the client already paid
 * their agency for, so something has to notice the boundary arrived. That is
 * this command: bounded, idempotent, and following the same convention the
 * repository already uses for scheduled work — the command owns the `--limit`
 * validation, the manager owns every decision about the rows.
 *
 * There is deliberately NO feature flag. Once a client has scheduled a
 * downgrade, failing to apply it would keep their agency charging them for a
 * tier they cancelled. The bounded batch and the manager's own per-row
 * transaction are the safety, not a switch.
 *
 * Separate from lane A's command on purpose: these are different agencies'
 * connected accounts, and one lane's sweep must never touch the other's rows.
 */
class ApplyDueAgencyPlanChanges extends Command
{
    protected $signature = 'agency-subscriptions:apply-due-plan-changes
        {--limit=100 : Maximum number of scheduled plan changes to apply}';

    protected $description = 'Apply agency SaaS client downgrades whose billing period has ended';

    public function handle(AgencyClientSubscriptionManager $manager): int
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

        $this->info("Applied {$count} scheduled agency plan change(s).");

        return self::SUCCESS;
    }
}
