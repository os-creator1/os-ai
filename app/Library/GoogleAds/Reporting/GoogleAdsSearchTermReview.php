<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSearchTerm;

/**
 * Google Ads Module V1 contract §12 — the owner's "Ignore" decision on a
 * search term, and the one scoped lookup every search-term action uses.
 *
 * Ignoring is NOT a provider mutation and has no ledger: it only sets the
 * local `review_state` of the term's cached rows, so the term stops being
 * called potential waste. It is reversible (unignore) and never overwritten
 * by the sync. It is scoped to the term in ONE campaign and ad group of the
 * Business's selected account, so ignoring "x" in one campaign never hides it
 * in another.
 *
 * Every lookup is keyed by the Business AND the account (AND the campaign
 * when one is named): an id belonging to another Business resolves to null.
 */
final class GoogleAdsSearchTermReview
{
    /**
     * The cached row a search-term action addresses, or null when it is not
     * inside this Business's account (or not in the named campaign).
     */
    public function resolve(GoogleAdsAccount $account, int $searchTermId, ?string $campaignUid = null): ?GoogleAdsSearchTerm
    {
        $query = GoogleAdsSearchTerm::query()
            ->whereKey($searchTermId)
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id);

        if ($campaignUid !== null) {
            $query->whereHas('campaign', fn ($campaign) => $campaign
                ->where('uid', $campaignUid)
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id));
        }

        return $query->with(['campaign:id,uid,name', 'adGroup:id,name'])->first();
    }

    /** @return int the number of cached rows updated */
    public function ignore(GoogleAdsAccount $account, GoogleAdsSearchTerm $term): int
    {
        return $this->setState($account, $term, GoogleAdsSearchTermReviewState::Ignored);
    }

    /** @return int the number of cached rows updated */
    public function unignore(GoogleAdsAccount $account, GoogleAdsSearchTerm $term): int
    {
        return $this->setState($account, $term, GoogleAdsSearchTermReviewState::Unreviewed);
    }

    private function setState(GoogleAdsAccount $account, GoogleAdsSearchTerm $term, GoogleAdsSearchTermReviewState $state): int
    {
        return GoogleAdsSearchTerm::query()
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('google_ads_campaign_id', $term->google_ads_campaign_id)
            ->where('google_ads_ad_group_id', $term->google_ads_ad_group_id)
            ->where('term_hash', $term->term_hash)
            ->update(['review_state' => $state->value]);
    }
}
