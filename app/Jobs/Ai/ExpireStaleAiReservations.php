<?php

namespace App\Jobs\Ai;

use App\Jobs\Base;
use App\Library\Ai\AiUsageLedgerManager;

/**
 * Unified Business Home and COO Decision Engine Contract §10.1 step 7 —
 * releases AI usage reservations older than
 * `config('ai.reservation_expiry_minutes')`. Never auto-commits a stale
 * reservation. Mirrors App\Jobs\Usage\ExpireStaleUsageReservations.
 */
class ExpireStaleAiReservations extends Base
{
    public function handle(AiUsageLedgerManager $ledger): void
    {
        $ledger->expireStaleReservations();
    }
}
