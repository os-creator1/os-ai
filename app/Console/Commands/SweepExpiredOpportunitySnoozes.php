<?php

namespace App\Console\Commands;

use App\Library\Opportunity\OpportunityManager;
use Illuminate\Console\Command;

/**
 * Runs one bounded batch of RFC-002 §33's expired-snooze sweep. All
 * transaction, locking, transition, and event behavior lives in
 * OpportunityManager::sweepExpiredSnoozes() — this command only reads and
 * validates --limit, checks the feature flag as an independent
 * defense-in-depth guard, and reports the result. It never loops until the
 * table is empty; the scheduler's own cadence provides eventual coverage.
 */
class SweepExpiredOpportunitySnoozes extends Command
{
    protected $signature = 'opportunity:sweep-expired-snoozes
        {--limit=100 : Maximum number of expired snoozes to inspect}';

    protected $description = 'Reopen expired Opportunity snoozes in one bounded batch';

    public function handle(OpportunityManager $manager): int
    {
        if (! config('opportunity.enabled', false)) {
            $this->info('Opportunity engine is disabled; expired snooze sweep skipped.');

            return self::SUCCESS;
        }

        $rawLimit = $this->option('limit');

        if (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $manager->sweepExpiredSnoozes($limit);

        $this->info("Reopened {$count} expired opportunity snooze(s).");

        return self::SUCCESS;
    }
}
