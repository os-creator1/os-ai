<?php

namespace Tests\Support\Seo;

use App\Jobs\Seo\RunSeoAuditForRevision;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Workspace;

/**
 * TEST-ONLY. SeoModule is registered `Planned` until Sub-slice H, so the real
 * entitlement decision is "not allowed" for every tier — which is correct and
 * is asserted directly in SeoAuditAuthorityTest. EntitlementManager is
 * `final`, so that decision cannot be mocked.
 *
 * This subclass replaces exactly and only the feature decision. Every other
 * gate in the job — Business exists, Business Active, Workspace present,
 * Workspace active — still runs the unmodified production code, so a test
 * using this class still proves those guards work. It flips no feature and is
 * never registered outside a test.
 */
class EntitlementAllowedSeoAuditJob extends RunSeoAuditForRevision
{
    protected function featureIsAllowed(EntitlementManager $entitlements, Workspace $workspace, Business $business): bool
    {
        return true;
    }
}
