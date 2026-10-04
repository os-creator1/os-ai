<?php

namespace App\Library\GoogleAds\Mutations;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsMutation;
use Illuminate\Support\Carbon;

/**
 * Google Ads Module V1 contract §6 step 5 — resolves `unknown` mutations
 * from Google's own synced state. It NEVER calls the provider and never
 * re-sends anything.
 *
 * PRECONDITION: the sync coordinator calls it right after campaigns and
 * keywords were freshly synced for the account (it is not wired into sync
 * here).
 *
 * For each `unknown` operation of the account:
 *   - the synced state already equals the requested state (campaign / keyword
 *     status; a live negative with the same text, match type and scope)
 *       -> ledger `succeeded` (observed);
 *   - it does not, AND the affected campaign was synced after the mutate
 *     finished (so the absence is evidence, not a stale read)
 *       -> ledger `failed`, classification `not_applied`;
 *   - otherwise it stays `unknown`.
 * Operations older than `google_ads.mutations.reconcile_max_age_hours`
 * (default 72) are left `unknown`: after that long, Google's state may have
 * changed for reasons unrelated to our request, so it is no longer evidence.
 *
 * A `pending` operation older than `google_ads.mutations.pending_stale_minutes`
 * (default 15) means the process died between commit and the provider call;
 * whether a request left is unknowable, so it is first moved to `unknown`
 * (it would otherwise block its target forever) and then judged as above.
 */
final class GoogleAdsMutationReconciler
{
    public function __construct(private readonly GoogleAdsOperationLedger $ledger)
    {
    }

    public function reconcile(GoogleAdsAccount $account): void
    {
        $this->promoteStalePending($account);

        $mutations = GoogleAdsMutation::query()
            ->with('operation')
            ->where('google_ads_account_id', $account->id)
            ->whereHas('operation', fn ($operation) => $operation
                ->where('status', GoogleOperationStatus::Unknown->value)
                ->where('started_at', '>=', Carbon::now()->subHours($this->maxAgeHours())))
            ->orderBy('id')
            ->get();

        foreach ($mutations as $mutation) {
            $operation = $mutation->operation;

            if ($operation === null || $operation->status !== GoogleOperationStatus::Unknown) {
                continue;
            }

            $verdict = match ($mutation->kind) {
                GoogleAdsMutationKind::CampaignStatus => $this->campaignVerdict($mutation, $operation),
                GoogleAdsMutationKind::KeywordStatus => $this->keywordVerdict($mutation, $operation),
                GoogleAdsMutationKind::NegativeKeyword => $this->negativeVerdict($mutation, $operation),
            };

            if ($verdict === true) {
                $this->ledger->succeed($operation, 'Confirmed by Google Ads sync: ' . $this->describe($mutation));
            } elseif ($verdict === false) {
                $this->ledger->failNotApplied($operation, 'Not applied in Google Ads: ' . $this->describe($mutation));
            }
        }
    }

    private function promoteStalePending(GoogleAdsAccount $account): void
    {
        $stale = GoogleAdsMutation::query()
            ->with('operation')
            ->where('google_ads_account_id', $account->id)
            ->whereHas('operation', fn ($operation) => $operation
                ->where('status', GoogleOperationStatus::Pending->value)
                ->where('started_at', '<', Carbon::now()->subMinutes($this->pendingStaleMinutes())))
            ->get();

        foreach ($stale as $mutation) {
            if ($mutation->operation !== null) {
                $this->ledger->fail($mutation->operation, GoogleAdsProviderException::timeout(true), $this->describe($mutation));
            }
        }
    }

    /** @return ?bool true = applied, false = not applied, null = no evidence */
    private function campaignVerdict(GoogleAdsMutation $mutation, BusinessGoogleOperation $operation): ?bool
    {
        $campaign = GoogleAdsCampaign::query()
            ->whereKey($mutation->target_local_id)
            ->where('google_ads_account_id', $mutation->google_ads_account_id)
            ->first();

        if ($campaign === null) {
            return null;
        }

        return $this->statusVerdict($campaign->status, $mutation, $campaign->last_synced_at, $operation);
    }

    private function keywordVerdict(GoogleAdsMutation $mutation, BusinessGoogleOperation $operation): ?bool
    {
        $keyword = GoogleAdsKeyword::query()
            ->whereKey($mutation->target_local_id)
            ->where('google_ads_account_id', $mutation->google_ads_account_id)
            ->first();

        if ($keyword === null) {
            return null;
        }

        return $this->statusVerdict($keyword->status, $mutation, $keyword->last_synced_at, $operation);
    }

    private function statusVerdict(GoogleAdsEntityStatus $observed, GoogleAdsMutation $mutation, ?Carbon $syncedAt, BusinessGoogleOperation $operation): ?bool
    {
        if ($observed->value === $mutation->requested_state) {
            return true;
        }

        return $this->syncedAfter($syncedAt, $operation) ? false : null;
    }

    private function negativeVerdict(GoogleAdsMutation $mutation, BusinessGoogleOperation $operation): ?bool
    {
        $params = (array) $mutation->params;
        $scope = GoogleAdsKeywordLevel::tryFrom((string) ($params['scope'] ?? ''));
        $campaignId = (int) ($params['campaign_local_id'] ?? 0);

        if ($scope === null || $campaignId === 0 || ! isset($params['text'], $params['match_type'])) {
            return null;
        }

        $present = GoogleAdsKeyword::query()
            ->where('google_ads_account_id', $mutation->google_ads_account_id)
            ->where('is_negative', true)
            ->where('level', $scope->value)
            ->where('match_type', (string) $params['match_type'])
            ->where('status', '!=', GoogleAdsEntityStatus::Removed->value)
            ->where($scope === GoogleAdsKeywordLevel::AdGroup ? 'google_ads_ad_group_id' : 'google_ads_campaign_id', (int) $mutation->target_local_id)
            ->whereRaw('LOWER(text) = ?', [mb_strtolower((string) $params['text'])])
            ->exists();

        if ($present) {
            return true;
        }

        // Absence is only evidence when the keywords were synced after the mutate. The
        // keywords stage runs after the campaigns stage in the same run, so the parent
        // campaign's sync time bounds it from below.
        $campaign = GoogleAdsCampaign::query()
            ->whereKey($campaignId)
            ->where('google_ads_account_id', $mutation->google_ads_account_id)
            ->first();

        return $campaign !== null && $this->syncedAfter($campaign->last_synced_at, $operation) ? false : null;
    }

    private function syncedAfter(?Carbon $syncedAt, BusinessGoogleOperation $operation): bool
    {
        $since = $operation->completed_at ?? $operation->started_at;

        return $syncedAt !== null && $since !== null && $syncedAt->greaterThan($since);
    }

    private function describe(GoogleAdsMutation $mutation): string
    {
        return $mutation->kind->value . ' ' . ($mutation->requested_state ?? 'add');
    }

    private function maxAgeHours(): int
    {
        return max(1, (int) config('google_ads.mutations.reconcile_max_age_hours', 72));
    }

    private function pendingStaleMinutes(): int
    {
        return max(1, (int) config('google_ads.mutations.pending_stale_minutes', 15));
    }
}
