<?php

namespace App\Console\Commands;

use App\Library\Documents\DocumentManager;
use Illuminate\Console\Command;

/**
 * Implementation Contract 17 §8.4 / §12.F — the reminder sweep.
 *
 * Same convention as ExpireDueDocuments and SweepExpiredOpportunitySnoozes:
 * this command owns the flag gate, the `--limit` validation and
 * `self::INVALID`, and nothing else. Deduplication is NOT this command's job —
 * the durable markers the manager writes under the row lock are what make a
 * rerun send nothing (§8.4).
 *
 * `--limit` bounds DOCUMENTS INSPECTED; one document can produce a payment
 * reminder and an expiry warning in the same pass.
 */
class DispatchDueDocumentReminders extends Command
{
    protected $signature = 'documents:dispatch-due-reminders
        {--limit= : Maximum number of documents to inspect}';

    protected $description = 'Send due document payment and offer-expiry reminders in one bounded batch';

    public function handle(DocumentManager $manager): int
    {
        if (! config('documents.enabled', false)) {
            $this->info('Payments & Contracts is disabled; document reminder sweep skipped.');

            return self::SUCCESS;
        }

        $rawLimit = $this->option('limit');

        if ($rawLimit === null) {
            $limit = max(1, (int) config('documents.reminder_sweep_limit', 100));
        } elseif (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $manager->dispatchDueReminders($limit);

        $this->info("Dispatched {$count} document reminder(s).");

        return self::SUCCESS;
    }
}
