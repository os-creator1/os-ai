<?php

namespace Tests\Support\Seo;

use App\Http\Controllers\Customer\Business\SeoReviewsController;

/**
 * TEST-ONLY. SeoModule is `Planned` until Sub-slice H, so the real
 * SeoReviewsController is (deliberately) unreachable — proven, tier by tier,
 * in SeoReviewsBoundaryTest. This subclass replaces exactly and only the
 * entitlement step (it still runs the full tenancy chain), so everything
 * behind it — capability gates, Location ACL, the managers, the view — runs
 * the unmodified production code. Never registered outside a test.
 */
class EntitlementBypassSeoReviewsController extends SeoReviewsController
{
    protected function resolveReviewTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid);
    }
}
