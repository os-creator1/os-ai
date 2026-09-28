<?php

namespace App\Jobs\Messaging;

use App\Jobs\Base;
use App\Library\Messaging\BusinessMessagingProvisioningService;

/**
 * Phone Numbers + A2P lane — review correction: without this, a local
 * number's own carrier-side campaign assignment (Telnyx's own assignment
 * endpoint returns a background task, never an immediate confirmation)
 * had no reachable mechanism to ever resolve Requested to Confirmed or
 * Failed, and would stay Requested — and therefore never Ready — forever.
 * Mirrors RefreshPendingMessagingRegistrations exactly: scheduled, never
 * invoked from a page render (see app/Console/Kernel.php's own scheduling
 * comment).
 */
class RefreshPendingCampaignAssignments extends Base
{
    public function handle(BusinessMessagingProvisioningService $provisioning): void
    {
        $provisioning->refreshAllPendingCampaignAssignments();
    }
}
