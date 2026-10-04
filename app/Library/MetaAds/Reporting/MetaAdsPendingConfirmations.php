<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §7 — which rows are "Pending confirmation".
 *
 * A pause/resume whose answer Meta never gave leaves its ledger operation
 * `unknown` (business_meta_operations); until the next sync reconciles it the
 * owner should see that the row's state is not yet certain. The overlay joins
 * meta_ads_mutations (target) to the ledger (status). Read-only, Business +
 * account scoped and bounded: every method is at most TWO small queries whose
 * size is the page, not the history.
 */
final class MetaAdsPendingConfirmations
{
    /**
     * @param  array<int, string>  $campaignUids
     * @return array<string, true>  campaign uid => pending
     */
    public function forCampaigns(MetaAdsAccount $account, array $campaignUids): array
    {
        return $this->byUid($account, MetaAdsMutationTargetType::Campaign, MetaAdsCampaign::class, $campaignUids);
    }

    /**
     * @param  array<int, string>  $adSetUids
     * @return array<string, true>
     */
    public function forAdSets(MetaAdsAccount $account, array $adSetUids): array
    {
        return $this->byUid($account, MetaAdsMutationTargetType::AdSet, MetaAdsAdSet::class, $adSetUids);
    }

    /**
     * @param  array<int, string>  $adUids
     * @return array<string, true>
     */
    public function forAds(MetaAdsAccount $account, array $adUids): array
    {
        return $this->byUid($account, MetaAdsMutationTargetType::Ad, MetaAdsAd::class, $adUids);
    }

    /**
     * @param  class-string  $model
     * @param  array<int, string>  $uids
     * @return array<string, true>
     */
    private function byUid(MetaAdsAccount $account, MetaAdsMutationTargetType $targetType, string $model, array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, 'is_string')));

        if ($uids === []) {
            return [];
        }

        $idToUid = $model::query()
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->whereIn('uid', $uids)
            ->pluck('uid', 'id')
            ->all();

        if ($idToUid === []) {
            return [];
        }

        $pending = DB::table('meta_ads_mutations as m')
            ->join('business_meta_operations as o', 'o.id', '=', 'm.business_meta_operation_id')
            ->where('m.business_id', $account->business_id)
            ->where('m.meta_ads_account_id', $account->id)
            ->where('o.business_id', $account->business_id)
            ->where('o.status', MetaOperationStatus::Unknown->value)
            ->where('m.target_type', $targetType->value)
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
}
