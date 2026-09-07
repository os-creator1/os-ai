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
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Models\Workspace;

/**
 * GBP Slice A contract §19.4 / §24 — refreshes ONE binding's mirror.
 *
 * App\Jobs\Base sets tries = 1 and maxExceptions = 1, so THERE IS NO
 * AUTOMATIC PROVIDER RETRY. A retry is only ever a deliberate,
 * ledger-checked re-dispatch (contract §24.4).
 *
 * Contract §24.7 — the job RE-FETCHES the Business and RE-EVALUATES
 * entitlement and access before any provider access. A Business that is
 * disabled, downgraded from Growth/Agency to Core, made inactive, or
 * deleted receives no background refresh and makes no provider call.
 *
 * Contract §24.3 — per-connection concurrency of one. A binding whose
 * connection is already being refreshed exits quietly; it is not an error.
 */
class RefreshGoogleBusinessProfileMirror extends Base
{
    public function __construct(
        private readonly int $bindingId,
        private readonly ?int $actorUserId = null,
    ) {
    }

    public function handle(
        GoogleBusinessProfileConnectionManager $connections,
        GoogleBusinessProfileMirrorService $mirror,
        EntitlementManager $entitlements,
    ): void {
        $binding = BusinessGoogleLocation::query()->with(['connection', 'businessLocation'])->find($this->bindingId);

        if ($binding === null || $binding->connection === null) {
            return;
        }

        if (! $this->stillEligible($binding, $entitlements)) {
            return;
        }

        $connection = $binding->connection;

        if (! $connection->isActive()) {
            return;
        }

        if (! $connections->claimRefresh($connection)) {
            // Contract §24.3 — another refresh for this connection is in
            // flight. Return without a provider call and without an error.
            return;
        }

        try {
            $mirror->refresh($binding, $connection, $this->actorUserId);
        } catch (GoogleBusinessProfileProviderException) {
            // Already classified and recorded in the ledger by the mirror
            // service (deferred / unknown / failed). Swallowed here so a
            // single tenant's provider failure never fails the sweep — and
            // because Base::$tries = 1 means re-throwing would not retry
            // anyway, it would only produce a failed_jobs row carrying a
            // provider classification we have already stored safely.
        } finally {
            $connections->releaseRefreshClaim($connection);
        }
    }

    /**
     * Contract §24.7 — the full re-check, before any provider access.
     */
    private function stillEligible(BusinessGoogleLocation $binding, EntitlementManager $entitlements): bool
    {
        $business = Business::query()->find($binding->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            return false;
        }

        if ($business->workspace_id === null) {
            return false;
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        try {
            // A scheduled run has no human actor; the entitlement
            // signature's actor argument is audit-only and is never a
            // tenancy decision.
            $decision = $entitlements->decide(
                $workspace,
                $business,
                PlatformFeature::GoogleBusinessProfileModule->value,
                $this->actorUserId ?? 0,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        return $decision->allowed;
    }
}
