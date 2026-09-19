<?php

namespace Tests\Support\Seo;

use App\Http\Controllers\Customer\Business\SeoController;
use App\Models\Business;
use App\Models\Workspace;

/**
 * TEST-ONLY. SeoBasicVisibility is registered `Planned` until Sub-slice H, so
 * EntitlementManager denies it for every tier and the real SeoController is
 * (deliberately) unreachable — which is proven, tier by tier, in
 * SeoFoundationBoundaryTest. That leaves everything BEHIND the entitlement
 * step untestable over HTTP.
 *
 * This subclass replaces exactly and only that step: it runs the full
 * tenancy chain (Workspace -> Business -> userCanAccessBusiness() -> active
 * Business) but skips the entitlement decision. The `view_seo` capability
 * gate, the readers, the Location-ACL filtering, the views and everything
 * else run unmodified from the production class. It is never registered
 * outside a test, and it changes no production behavior.
 */
class EntitlementBypassSeoController extends SeoController
{
    protected function resolveSeoTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid);
    }

    /**
     * Simulates the post-flip state of the bare entry's availability floor so
     * its selector logic (zero / one / many) can be tested WITHOUT flipping
     * the registry, which Sub-slice H owns. The production floor itself is
     * proven, tier by tier, against the real controller.
     */
    protected function seoIsImplementedAndAvailable(): bool
    {
        return true;
    }

    protected function seoEntitlementAllows(Workspace $workspace, Business $business, int $userId): bool
    {
        return true;
    }
}
