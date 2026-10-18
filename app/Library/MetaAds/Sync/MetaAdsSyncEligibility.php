<?php

namespace App\Library\MetaAds\Sync;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use App\Models\Workspace;

/**
 * The ONE answer to "may we talk to Meta for this account right now?", asked
 * by the sweep (before queueing), the requester (before accepting a refresh)
 * and the job (immediately before any provider call).
 *
 * Everything is RE-FETCHED from the database on every call — nothing is
 * trusted from a queued payload — so a Business that was suspended, a
 * workspace that was deactivated, a plan that was downgraded, a connection
 * that expired / was revoked / disconnected, an account that was unselected or
 * a re-authorisation as a different Meta user after queueing makes no
 * provider call.
 *
 * Entitlement (contract 24 §8): the read-only Overview needs only basic
 * visibility, the full module any of ads_module / google_ads_module /
 * meta_ads_module; a sync runs if EITHER allows (AdsFeatureAccess::hasAnyAds),
 * so Core still gets its daily refresh.
 *
 * Not final only so tests can stub the entitlement decision (entitled()).
 */
class MetaAdsSyncEligibility
{
    public const REASON_ACCOUNT_MISSING = 'account_missing';

    public const REASON_ACCOUNT_NOT_SELECTED = 'account_not_selected';

    public const REASON_BUSINESS_INACTIVE = 'business_inactive';

    public const REASON_WORKSPACE_INACTIVE = 'workspace_inactive';

    public const REASON_NOT_ENTITLED = 'not_entitled';

    public const REASON_CONNECTION_INACTIVE = 'connection_inactive';

    public const REASON_TOKEN_EXPIRED = 'token_expired';

    public const REASON_CONNECTION_MISMATCH = 'connection_mismatch';

    public function __construct(
        private readonly AdsFeatureAccess $access,
        private readonly MetaAdsConnectionManager $connections,
    ) {
    }

    /** The fresh account + its Business's own active Meta connection, or the reason it is not syncable. */
    public function evaluate(int $accountId): MetaAdsSyncEligibilityResult
    {
        $account = MetaAdsAccount::query()->find($accountId);

        if ($account === null) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_ACCOUNT_MISSING);
        }

        if ($account->selected_at === null) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_ACCOUNT_NOT_SELECTED, $account);
        }

        $business = Business::query()->find($account->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active || $business->workspace_id === null) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_BUSINESS_INACTIVE, $account);
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_WORKSPACE_INACTIVE, $account);
        }

        if (! $this->entitled($workspace, $business)) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_NOT_ENTITLED, $account);
        }

        // Found through the account's OWN Business, then required to be the
        // very connection the account row is bound to.
        $connection = BusinessMetaConnection::query()->where('business_id', $account->business_id)->first();

        if ($connection === null) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_CONNECTION_INACTIVE, $account);
        }

        if ((int) $connection->id !== (int) $account->business_meta_connection_id) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_CONNECTION_MISMATCH, $account);
        }

        if (! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_CONNECTION_INACTIVE, $account);
        }

        if ($connection->token_expires_at === null || $connection->token_expires_at->lte(now())) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_TOKEN_EXPIRED, $account, $connection);
        }

        // The selection was made under one Meta identity; a re-authorisation
        // as someone else must never keep syncing the old selection.
        if ($account->selected_meta_user_id === null
            || $connection->meta_user_id === null
            || (string) $connection->meta_user_id !== (string) $account->selected_meta_user_id) {
            return MetaAdsSyncEligibilityResult::refused(self::REASON_CONNECTION_MISMATCH, $account);
        }

        return MetaAdsSyncEligibilityResult::allowed($account, $connection);
    }

    /**
     * A connection whose token passed its expiry is still `active` in the
     * database until something asks for the token. The sweep and the job call
     * this when evaluate() refused with token_expired, so the connection
     * becomes `expired` (token wiped, UI shows "Reconnect") instead of
     * silently staying active. No provider call is made.
     */
    public function retireExpiredToken(MetaAdsSyncEligibilityResult $result): void
    {
        if ($result->reason !== self::REASON_TOKEN_EXPIRED || $result->connection === null) {
            return;
        }

        try {
            $this->connections->accessTokenFor($result->connection);
        } catch (MetaProviderException|MetaAdsConcurrencyException) {
            // accessTokenFor() already moved the connection (or another writer did).
        }
    }

    protected function entitled(Workspace $workspace, Business $business): bool
    {
        // A background run has no human actor; the actor argument is audit-only.
        return $this->access->hasAnyAds($workspace, $business, 0);
    }
}
