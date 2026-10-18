<?php

namespace Tests\Feature\GoogleAds\Sync\Support;

use App\Library\GoogleAds\Sync\GoogleAdsSyncEligibility;
use App\Models\Business;
use App\Models\Workspace;

/**
 * The production eligibility with ONLY the entitlement decision stubbed: the
 * feature registry still lists the Ads features as Planned in this lane until
 * the wiring phase flips them, so the real decision is always "denied" here.
 * Everything else (Business / workspace / connection re-checks) is real.
 */
final class StubbedSyncEligibility extends GoogleAdsSyncEligibility
{
    /** @var array<int, int> business ids that have LOST entitlement */
    public static array $deniedBusinessIds = [];

    protected function entitled(Workspace $workspace, Business $business): bool
    {
        return ! in_array((int) $business->id, self::$deniedBusinessIds, true);
    }
}
