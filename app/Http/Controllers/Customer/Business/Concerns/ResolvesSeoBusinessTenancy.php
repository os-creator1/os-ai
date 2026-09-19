<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\Business;
use App\Models\Workspace;

/**
 * Contract 18 §10.1 — the one place SEO controllers run the mandatory chain
 * through entitlement: Workspace → Business → userCanAccessBusiness() → active
 * Business → the SeoBasicVisibility entitlement decision. Every failure is a
 * 404 (never 403), and while SeoBasicVisibility is Planned EntitlementManager
 * denies it for every tier, so every SEO Business route fails closed.
 *
 * Requires ResolvesBusinessTenancy on the using controller. Its own seam so a
 * test-only subclass can replace exactly and only the entitlement step.
 */
trait ResolvesSeoBusinessTenancy
{
    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveSeoTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoBasicVisibility->value);
    }
}
