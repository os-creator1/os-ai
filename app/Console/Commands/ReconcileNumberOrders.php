<?php

namespace App\Console\Commands;

use App\Library\Messaging\NumberOrderReconciler;
use Illuminate\Console\Command;

/**
 * Finishes accepted-but-unfinished carrier number orders (Telnyx `pending`, or an
 * outcome that was never seen) using read-only carrier requests. Scheduled every
 * minute; safe to run concurrently and to replay (see NumberOrderReconciler).
 */
class ReconcileNumberOrders extends Command
{
    protected $signature = 'messaging:reconcile-number-orders {--limit=50 : Maximum orders to check per run}';

    protected $description = 'Complete pending Telnyx number orders automatically (read-only carrier requests; never orders or charges).';

    public function handle(NumberOrderReconciler $reconciler): int
    {
        $checked = $reconciler->reconcileDue(max(1, (int) $this->option('limit')));

        $this->info("Checked {$checked} number order(s).");

        return self::SUCCESS;
    }
}
