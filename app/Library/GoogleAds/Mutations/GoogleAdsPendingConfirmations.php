<?php

namespace App\Library\GoogleAds\Mutations;

use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsKeyword;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §6 — which rows are "Pending confirmation".
 *
 * A change whose answer Google never gave leaves its ledger operation
 * `unknown` (business_google_operations); until the next sync reconciles it,
 * the owner should see that the row's state is not yet certain. Read-only,
 * Business + account scoped, and bounded: every method is at most two small
 * queries whose size is the page, not the history.
 */
final class GoogleAdsPendingConfirmations
{
    private const NEGATIVE_LIMIT = 200;

    /**
     * @param  array<int, string>  $campaignUids
     * @return array<string, true>  campaign uid => pending
     */
    public function forCampaigns(GoogleAdsAccount $account, array $campaignUids): array
    {
        return $this->byUid($account, GoogleAdsMutationKind::CampaignStatus, 'campaign', GoogleAdsCampaign::class, $campaignUids);
    }

    /**
     * @param  array<int, string>  $keywordUids
     * @return array<string, true>  keyword uid => pending
     */
    public function forKeywords(GoogleAdsAccount $account, array $keywordUids): array
    {
        return $this->byUid($account, GoogleAdsMutationKind::KeywordStatus, 'keyword', GoogleAdsKeyword::class, $keywordUids);
    }

    /**
     * Unconfirmed negative keywords as "campaignId|lower(text)" and
     * "adGroupId|lower(text)" lookups over LOCAL ids, so a search-term row can
     * ask "is a negative for me awaiting confirmation?".
     *
     * @return array<string, true>
     */
    public function negativeKeys(GoogleAdsAccount $account): array
    {
        $rows = $this->unknownQuery($account)
            ->where('m.kind', GoogleAdsMutationKind::NegativeKeyword->value)
            ->orderByDesc('m.id')
            ->limit(self::NEGATIVE_LIMIT)
            ->get(['m.params']);

        $keys = [];

        foreach ($rows as $row) {
            $params = is_string($row->params) ? json_decode($row->params, true) : null;

            if (! is_array($params) || ! isset($params['text'])) {
                continue;
            }

            $text = mb_strtolower(trim((string) $params['text']));

            if (($params['campaign_local_id'] ?? null) !== null) {
                $keys['c|' . (int) $params['campaign_local_id'] . '|' . $text] = true;
            }

            if (($params['ad_group_local_id'] ?? null) !== null) {
                $keys['g|' . (int) $params['ad_group_local_id'] . '|' . $text] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  class-string  $model
     * @param  array<int, string>  $uids
     * @return array<string, true>
     */
    private function byUid(GoogleAdsAccount $account, GoogleAdsMutationKind $kind, string $targetType, string $model, array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, 'is_string')));

        if ($uids === []) {
            return [];
        }

        $idToUid = $model::query()
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->whereIn('uid', $uids)
            ->pluck('uid', 'id')
            ->all();

        if ($idToUid === []) {
            return [];
        }

        $pending = $this->unknownQuery($account)
            ->where('m.kind', $kind->value)
            ->where('m.target_type', $targetType)
            ->whereIn('m.target_local_id', array_keys($idToUid))
            ->distinct()
            ->pluck('m.target_local_id');

        $result = [];

        foreach ($pending as $id) {
            if (isset($idToUid[(int) $id])) {
                $result[(string) $idToUid[(int) $id]] = true;
            }
        }

        return $result;
    }

    private function unknownQuery(GoogleAdsAccount $account): \Illuminate\Database\Query\Builder
    {
        return DB::table('google_ads_mutations as m')
            ->join('business_google_operations as o', 'o.id', '=', 'm.business_google_operation_id')
            ->where('m.business_id', $account->business_id)
            ->where('m.google_ads_account_id', $account->id)
            ->where('o.business_id', $account->business_id)
            ->where('o.status', GoogleOperationStatus::Unknown->value);
    }
}
