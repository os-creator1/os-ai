<?php

namespace App\Console\Commands;

use App\Library\Payments\PaymentManager;
use Illuminate\Console\Command;

/**
 * Implementation Contract 17 §7.5 / §12.F — the abandoned-attempt sweep.
 *
 * Same convention as the other two: flag gate, `--limit` validation,
 * `self::INVALID`, nothing else. The manager asks the provider and hands the
 * answer to the shared finalizer; neither this command nor that manager
 * decides an outcome, and neither may mark an attempt failed or canceled
 * because time passed.
 *
 * When the feature is disabled this makes ZERO provider calls — which matters
 * more here than in the other two commands, because a sweep that reached
 * Stripe while the feature was off would be talking to connected accounts
 * about a product that is not turned on.
 */
class ReconcileStaleDocumentPayments extends Command
{
    protected $signature = 'documents:reconcile-stale-payments
        {--limit= : Maximum number of stale payment attempts to inspect}';

    protected $description = 'Ask Stripe about stale non-terminal document payment attempts in one bounded batch';

    public function handle(PaymentManager $manager): int
    {
        if (! config('documents.enabled', false)) {
            $this->info('Payments & Contracts is disabled; stale payment reconciliation skipped.');

            return self::SUCCESS;
        }

        $rawLimit = $this->option('limit');

        if ($rawLimit === null) {
            $limit = max(1, (int) config('documents.stale_payment_sweep_limit', 100));
        } elseif (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $manager->reconcileStalePayments($limit);

        $this->info("Reconciled {$count} stale payment attempt(s).");

        return self::SUCCESS;
    }
}
