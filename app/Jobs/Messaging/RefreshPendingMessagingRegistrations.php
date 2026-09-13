<?php

namespace App\Jobs\Messaging;

use App\Jobs\Base;
use App\Library\Messaging\BusinessMessagingRegistrationService;

/**
 * PR #295 Correction Round 1, item 6 — without this, a submitted
 * registration had no reachable caller/job/webhook and could stay Pending
 * forever. Scheduled, never invoked from a page render (see
 * app/Console/Kernel.php's own scheduling comment for the cadence
 * rationale). ShouldQueue is inherited from App\Jobs\Base, mirroring
 * ExpireStaleUsageReservations.
 */
class RefreshPendingMessagingRegistrations extends Base
{
    public function handle(BusinessMessagingRegistrationService $registrations): void
    {
        $registrations->refreshAllPending();
    }
}
