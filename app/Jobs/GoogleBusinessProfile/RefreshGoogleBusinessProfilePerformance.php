<?php

namespace App\Jobs\GoogleBusinessProfile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Jobs\Base;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceLoader;
use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Refreshes ONE linked location's Google performance (the page's cached, 24-hour read). Like the mirror refresh job it has no
 * automatic retry (Base::$tries = 1), re-checks entitlement and access before any provider access, and takes the connection's
 * refresh claim so two reads for one connection never run at once. A provider failure is already recorded in the ledger and
 * leaves the previous result untouched.
 */
class RefreshGoogleBusinessProfilePerformance extends Base
{
    public function __construct(private readonly int $bindingId)
    {
    }

    public function handle(
        GoogleBusinessProfileConnectionManager $connections,
        GoogleBusinessProfilePerformanceLoader $performance,
        EntitlementManager $entitlements,
    ): void {
        $binding = BusinessGoogleLocation::query()->with('connection')->find($this->bindingId);

        if ($binding === null || $binding->connection === null || ! $binding->connection->isActive() || ! $this->stillEligible($binding, $entitlements)) {
            return;
        }

        // Another read for this connection is in flight, or the held result will still be there at this location's next scheduled read.
        $nextRead = CarbonImmutable::now('UTC')->addDay()->addSeconds(SweepGoogleBusinessProfilePerformance::LATE_WORKER_MARGIN);

        if ($performance->remainsAvailableThrough($binding, $nextRead) || ! $connections->claimRefresh($binding->connection)) {
            return;
        }

        try {
            $performance->load($binding, $binding->connection);
        } catch (GoogleBusinessProfileProviderException) {
            // Classified and recorded by the loader; swallowed so one tenant's failure never fails the sweep.
        } finally {
            $connections->releaseRefreshClaim($binding->connection);
        }
    }

    private function stillEligible(BusinessGoogleLocation $binding, EntitlementManager $entitlements): bool
    {
        $business = Business::query()->find($binding->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active || $business->workspace_id === null) {
            return false;
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        try {
            return $entitlements->decide($workspace, $business, PlatformFeature::GoogleBusinessProfileModule->value, 0)->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }
}
