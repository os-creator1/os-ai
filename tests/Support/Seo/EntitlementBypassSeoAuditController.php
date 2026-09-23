<?php

namespace Tests\Support\Seo;

use App\Http\Controllers\Customer\Business\SeoAuditController;

/**
 * TEST-ONLY. SeoModule is registered `Planned` until Sub-slice H, so
 * EntitlementManager denies it for every tier and the real SeoAuditController
 * is (deliberately) unreachable — proven tier by tier in
 * SeoAuditBoundaryTest. This subclass replaces exactly and only the
 * entitlement step (it still runs the full tenancy chain), so everything
 * behind it — the capability gate, the manual re-run throttle, the reader and
 * the view — runs the unmodified production code. Never registered outside a
 * test, and it flips no feature.
 */
class EntitlementBypassSeoAuditController extends SeoAuditController
{
    protected function resolveAuditTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid);
    }
}
