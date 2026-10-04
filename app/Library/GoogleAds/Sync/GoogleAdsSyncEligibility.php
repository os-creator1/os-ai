<?php

namespace App\Library\GoogleAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use App\Models\Workspace;

/**
 * The ONE answer to "may we talk to Google for this account right now?",
 * asked by the sweep (before queueing), the requester (before accepting a
 * refresh) and the job (immediately before any provider call).
 *
 * Everything is RE-FETCHED from the database on every call — nothing is
 * trusted from a queued payload — so a Business that was suspended, a
 * workspace that was deactivated, a plan that was downgraded or a connection
 * that was revoked/disconnected after queueing makes no provider call.
 *
 * Entitlement (contract §7): the read-only Overview needs only
 * `ads_basic_visibility`, the full module needs `google_ads_module`; a sync
 * runs if EITHER allows, so Core still gets its daily refresh.
 *
 * Not final only so tests can stub the entitlement decision while the
 * feature registry still lists the Ads features as Planned.
 */
class GoogleAdsSyncEligibility
{
    public const REASON_ACCOUNT_MISSING = 'account_missing';

    public const REASON_ACCOUNT_NOT_SELECTED = 'account_not_selected';

    public const REASON_BUSINESS_INACTIVE = 'business_inactive';

    public const REASON_WORKSPACE_INACTIVE = 'workspace_inactive';

    public const REASON_NOT_ENTITLED = 'not_entitled';

    public const REASON_CONNECTION_INACTIVE = 'connection_inactive';

    public function __construct(private readonly EntitlementManager $entitlements)
    {
    }

    /** The fresh account + its Business's own active Ads connection, or the reason it is not syncable. */
    public function evaluate(int $accountId): GoogleAdsSyncEligibilityResult
    {
        $account = GoogleAdsAccount::query()->find($accountId);

        if ($account === null) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_ACCOUNT_MISSING);
        }

        if ($account->selected_at === null) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_ACCOUNT_NOT_SELECTED, $account);
        }

        $business = Business::query()->find($account->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active || $business->workspace_id === null) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_BUSINESS_INACTIVE, $account);
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_WORKSPACE_INACTIVE, $account);
        }

        if (! $this->entitled($workspace, $business)) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_NOT_ENTITLED, $account);
        }

        // Found through the account's OWN Business and product, then required
        // to be the very connection the account row is bound to.
        $connection = BusinessGoogleConnection::query()
            ->where('business_id', $account->business_id)
            ->where('product', GoogleConnectionProduct::GoogleAds->value)
            ->first();

        if ($connection === null
            || (int) $connection->id !== (int) $account->business_google_connection_id
            || ! $connection->isActive()
            || ! $connection->hasStoredAuthorization()) {
            return GoogleAdsSyncEligibilityResult::refused(self::REASON_CONNECTION_INACTIVE, $account);
        }

        return GoogleAdsSyncEligibilityResult::allowed($account, $connection);
    }

    protected function entitled(Workspace $workspace, Business $business): bool
    {
        try {
            // A background run has no human actor; the actor argument is audit-only.
            $decisions = $this->entitlements->snapshotBusinessFeatureDecisions(
                $workspace,
                $business,
                [PlatformFeature::AdsBasicVisibility->value, PlatformFeature::GoogleAdsModule->value],
                0,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        foreach ($decisions as $decision) {
            if ($decision->allowed) {
                return true;
            }
        }

        return false;
    }
}
