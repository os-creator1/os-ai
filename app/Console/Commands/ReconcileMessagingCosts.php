<?php

namespace App\Console\Commands;

use App\Library\Messaging\MessageCostReconciler;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Settles managed message charges to approved retail base x Telnyx billable
 * parts + the actual carrier surcharge. Safe to run automatically: each
 * settlement is idempotent, retries are bounded and unresolved or oversized
 * variances become visible incidents. Without --apply it only records and
 * reports. The scheduled run uses MESSAGING_RECONCILIATION_ACTOR_USER_ID as
 * the audited actor on credits.
 */
class ReconcileMessagingCosts extends Command
{
    protected $signature = 'messaging:reconcile-costs
        {--days=7 : only messages from the last N days}
        {--apply : settle the differences (debit under-charges, credit over-charges)}
        {--recheck-days=0 : also re-read already-settled messages from the last N days to catch later Telnyx billing adjustments}
        {--actor-user-id= : administrator recorded on credits (defaults to MESSAGING_RECONCILIATION_ACTOR_USER_ID)}
        {--limit=500}';

    protected $description = 'Reconcile managed message charges to the retail base x billable parts + the actual Telnyx carrier surcharge.';

    public function handle(MessageCostReconciler $reconciler): int
    {
        $actor = $this->option('actor-user-id') ?? config('messaging.reconciliation.actor_user_id');

        if ($this->option('apply') && $actor !== null && $actor !== '' && (! ctype_digit((string) $actor) || ! User::query()->whereKey((int) $actor)->value('is_admin'))) {
            $this->error('The reconciliation actor must be a platform administrator.');

            return self::FAILURE;
        }

        $s = $reconciler->run((int) $this->option('days'), (bool) $this->option('apply'), $actor === null || $actor === '' ? null : (int) $actor, (int) $this->option('limit'), (int) $this->option('recheck-days'));

        $this->line("examined={$s['examined']} not_final={$s['not_final']} matched={$s['matched']} settled={$s['settled']} open={$s['open']} incidents={$s['incidents']}");
        $this->line("net_variance_micro={$s['variance_micro']} (positive = more is owed by the Business)");
        $this->info($this->option('apply') ? 'Applied.' : 'Report only - nothing was charged or credited. Re-run with --apply to settle.');

        if ($s['incidents'] > 0) {
            $this->error("{$s['incidents']} message(s) need an administrator: see messaging_cost_reconciliations where status = 'incident'.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
