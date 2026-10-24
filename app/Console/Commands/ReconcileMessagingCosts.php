<?php

namespace App\Console\Commands;

use App\Library\Messaging\MessageCostReconciler;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Compares billed messaging charges with Telnyx's final per-message cost.
 * Without --apply it only records and reports the differences; with --apply
 * it bills under-charges at cost (idempotently, per message) and — when an
 * administrator actor is given — credits over-charges back.
 */
class ReconcileMessagingCosts extends Command
{
    protected $signature = 'messaging:reconcile-costs
        {--days=7 : only messages from the last N days}
        {--apply : settle the differences (bill under-charges, credit over-charges)}
        {--actor-user-id= : administrator authorizing credits (required to credit)}
        {--limit=500}';

    protected $description = 'Reconcile billed message charges against Telnyx\'s final per-message cost (zero markup).';

    public function handle(MessageCostReconciler $reconciler): int
    {
        $actor = $this->option('actor-user-id');

        if ($this->option('apply') && $actor !== null && (! ctype_digit((string) $actor) || ! User::query()->whereKey((int) $actor)->value('is_admin'))) {
            $this->error('--actor-user-id must be a platform administrator.');

            return self::FAILURE;
        }

        $s = $reconciler->run((int) $this->option('days'), (bool) $this->option('apply'), $actor === null ? null : (int) $actor, (int) $this->option('limit'));

        $this->line("examined={$s['examined']} not_final={$s['not_final']} matched={$s['matched']} undercharged={$s['undercharged']} overcharged={$s['overcharged']}");
        $this->line("settled={$s['settled']} open={$s['open']} net_variance_micro={$s['variance_micro']} (positive = carrier cost more than billed)");
        $this->info($this->option('apply') ? 'Applied.' : 'Report only — nothing was charged or credited. Re-run with --apply to settle.');

        return self::SUCCESS;
    }
}
