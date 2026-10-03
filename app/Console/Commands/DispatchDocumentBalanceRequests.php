<?php

namespace App\Console\Commands;

use App\Library\Documents\Delivery\DocumentBalanceRequestDispatcher;
use Illuminate\Console\Command;

/**
 * Contract 17B §3/§7 — the automatic balance payment request sweep.
 *
 * Same convention as the sibling document sweeps: this command owns the
 * `documents.enabled` no-op, the `--limit` validation and `self::INVALID`;
 * DocumentBalanceRequestDispatcher owns every decision about the rows, and the
 * durable claim markers on the schedule item are what make a rerun send
 * nothing.
 */
class DispatchDocumentBalanceRequests extends Command
{
    protected $signature = 'documents:dispatch-balance-requests
        {--limit= : Maximum number of due balance items to inspect}';

    protected $description = 'Email the secure pay link for deposit-paid balances whose frozen due date has arrived';

    public function handle(DocumentBalanceRequestDispatcher $dispatcher): int
    {
        if (! config('documents.enabled', false)) {
            $this->info('Payments & Contracts is disabled; balance payment request sweep skipped.');

            return self::SUCCESS;
        }

        $rawLimit = $this->option('limit');

        if ($rawLimit === null) {
            $limit = max(1, (int) config('documents.balance_request_sweep_limit', 100));
        } elseif (is_int($rawLimit) && $rawLimit > 0) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && $rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0) {
            $limit = (int) $rawLimit;
        } else {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $dispatcher->dispatchDue($limit);

        $this->info("Dispatched {$count} balance payment request(s).");

        return self::SUCCESS;
    }
}
