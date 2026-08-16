<?php

namespace App\Jobs\Usage;

use App\Jobs\Base;
use App\Library\Usage\UsageWalletManager;

/**
 * RFC-005 §13 — finds pending reservations past their own expires_at and
 * releases them. Never auto-commits a stale reservation. ShouldQueue is
 * inherited from App\Jobs\Base.
 */
class ExpireStaleUsageReservations extends Base
{
    public function handle(UsageWalletManager $manager): void
    {
        $manager->expireStaleReservations();
    }
}
