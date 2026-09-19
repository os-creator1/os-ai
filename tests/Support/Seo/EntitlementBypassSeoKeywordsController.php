<?php

namespace Tests\Support\Seo;

use App\Http\Controllers\Customer\Business\SeoKeywordsController;

/**
 * TEST-ONLY. SeoBasicVisibility is `Planned` until Sub-slice H, so the real
 * SeoKeywordsController is (deliberately) unreachable — proven, tier by tier,
 * in SeoKeywordsHttpTest. This subclass replaces exactly and only the
 * entitlement step (see EntitlementBypassSeoController) so everything behind
 * it — capability gates, Location access, validation, the manager, the view —
 * runs as production code. Never registered outside a test.
 */
class EntitlementBypassSeoKeywordsController extends SeoKeywordsController
{
    protected function resolveSeoTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid);
    }
}
