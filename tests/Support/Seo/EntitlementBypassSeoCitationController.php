<?php

namespace Tests\Support\Seo;

use App\Http\Controllers\Customer\Business\SeoCitationController;

/**
 * TEST-ONLY. SeoModule is registered `Planned` until Sub-slice H, so
 * EntitlementManager denies it for every tier and the real
 * SeoCitationController is (deliberately) unreachable — proven tier by tier in
 * SeoCitationsBoundaryTest. This subclass replaces exactly and only the
 * entitlement step (it still runs the full tenancy chain), so everything
 * behind it — capability gates, Location ACL, the manager, the view — runs the
 * unmodified production code. Never registered outside a test.
 */
class EntitlementBypassSeoCitationController extends SeoCitationController
{
    protected function resolveCitationTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid);
    }
}
