<?php

namespace Tests\Feature\MetaAds\Sync\Support;

use App\Library\MetaAds\Sync\MetaAdsSyncEligibility;
use App\Models\Business;
use App\Models\Workspace;

/**
 * The production eligibility with ONLY the entitlement decision stubbed: the
 * Ads features may not be Available in the registry in every lane, so the real
 * decision could be "denied" here. Everything else (Business / workspace /
 * connection / token / Meta identity re-checks) is real.
 */
final class StubbedMetaSyncEligibility extends MetaAdsSyncEligibility
{
    /** @var array<int, int> business ids that have LOST entitlement */
    public static array $deniedBusinessIds = [];

    protected function entitled(Workspace $workspace, Business $business): bool
    {
        return ! in_array((int) $business->id, self::$deniedBusinessIds, true);
    }
}
