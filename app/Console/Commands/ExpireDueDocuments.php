<?php

namespace App\Console\Commands;

use App\Library\Documents\DocumentManager;
use Illuminate\Console\Command;

/**
 * Implementation Contract 17 §8.6 / §12.F — the offer-expiry sweep.
 *
 * Mirrors SweepExpiredOpportunitySnoozes exactly, which is the convention
 * §3.5 names: THE COMMAND owns the feature-flag gate, the `--limit`
 * validation and `self::INVALID`; THE MANAGER owns every decision about the
 * rows. The schedule registers this command unconditionally precisely because
 * the disabled no-op lives here, so turning the feature on or off never means
 * editing the scheduler.
 *
 * Disabled is a deterministic no-op: one line of output, exit SUCCESS, zero
 * rows read or written.
 */
class ExpireDueDocuments extends Command
{
    protected $signature = 'documents:expire-due
        {--limit= : Maximum number of due documents to inspect}';

    protected $description = 'Expire unsigned, unpaid documents past their offer expiry in one bounded batch';

    public function handle(DocumentManager $manager): int
    {
        if (! config('documents.enabled', false)) {
            $this->info('Payments & Contracts is disabled; document expiry sweep skipped.');

            return self::SUCCESS;
        }

        $rawLimit = $this->option('limit');

        if ($rawLimit === null) {
            $limit = max(1, (int) config('documents.expire_sweep_limit', 100));
        } elseif (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $manager->expireDue($limit);

        $this->info("Expired {$count} document(s).");

        return self::SUCCESS;
    }
}
