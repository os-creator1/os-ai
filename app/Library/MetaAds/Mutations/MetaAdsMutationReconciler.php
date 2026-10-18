<?php

namespace App\Library\MetaAds\Mutations;

use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\MetaAdsMutation;
use Illuminate\Support\Carbon;

/**
 * Meta Ads Module V1 contract 24 §7 — resolves `unknown` pause/resume
 * mutations from Meta's own synced state. It NEVER calls the provider and
 * never re-sends anything.
 *
 * PRECONDITION: the sync coordinator calls it (ReconcileMetaMutationsAfterSync)
 * right after the `campaigns`, `ad_sets` or `ads` stage persisted.
 * `$afterStage` says which entity type was just refreshed.
 *
 * For each `unknown` operation of the account:
 *   - the synced status already equals the requested state (valid evidence at
 *     any time) -> ledger `succeeded` (observed);
 *   - it does not, AND the stage that just persisted is the one that refreshes
 *     this entity type, AND its report was COMPLETE (not truncated), AND the
 *     entity row was synced AFTER the operation completed
 *       -> ledger `failed`, classification `not_applied`;
 *   - otherwise it stays `unknown`.
 * Operations older than `meta_ads.mutations.reconcile_max_age_hours` (default
 * 72) are left `unknown`: after that long, Meta's state may have changed for
 * reasons unrelated to our request, so it is no longer evidence.
 *
 * A `pending` operation older than `meta_ads.mutations.pending_stale_minutes`
 * (default 15) means the process died between commit and the provider call;
 * whether a request left is unknowable, so it is first moved to `unknown` (it
 * would otherwise block its target forever) and then judged as above.
 */
final class MetaAdsMutationReconciler
{
    /** The sync stage keys that refresh each target type. */
    public const STAGES = ['campaigns', 'ad_sets', 'ads'];

    public function __construct(private readonly MetaAdsOperationLedger $ledger)
    {
    }

    public function reconcile(MetaAdsAccount $account, string $afterStage, bool $truncated = false): void
    {
        $this->promoteStalePending($account);

        $mutations = MetaAdsMutation::query()
            ->with('operation')
            ->where('meta_ads_account_id', $account->id)
            ->where('business_id', $account->business_id)
            ->whereHas('operation', fn ($operation) => $operation
                ->where('status', MetaOperationStatus::Unknown->value)
                ->where('started_at', '>=', Carbon::now()->subHours($this->maxAgeHours())))
            ->orderBy('id')
            ->get();

        foreach ($mutations as $mutation) {
            $operation = $mutation->operation;

            if ($operation === null || $operation->status !== MetaOperationStatus::Unknown) {
                continue;
            }

            $verdict = $this->verdict($mutation, $operation);

            if ($verdict === false && ! $this->mayConcludeNotApplied($mutation->target_type, $afterStage, $truncated)) {
                $verdict = null;
            }

            if ($verdict === true) {
                $this->ledger->succeed($operation, 'Confirmed by Meta Ads sync: ' . $this->describe($mutation));
            } elseif ($verdict === false) {
                $this->ledger->failNotApplied($operation, 'Not applied in Meta Ads: ' . $this->describe($mutation));
            }
        }
    }

    private function promoteStalePending(MetaAdsAccount $account): void
    {
        $stale = MetaAdsMutation::query()
            ->with('operation')
            ->where('meta_ads_account_id', $account->id)
            ->where('business_id', $account->business_id)
            ->whereHas('operation', fn ($operation) => $operation
                ->where('status', MetaOperationStatus::Pending->value)
                ->where('started_at', '<', Carbon::now()->subMinutes($this->pendingStaleMinutes())))
            ->get();

        foreach ($stale as $mutation) {
            if ($mutation->operation !== null) {
                $this->ledger->fail($mutation->operation, MetaProviderException::timeout(true), $this->describe($mutation));
            }
        }
    }

    /** @return ?bool true = applied, false = not applied, null = no evidence */
    private function verdict(MetaAdsMutation $mutation, BusinessMetaOperation $operation): ?bool
    {
        $model = match ($mutation->target_type) {
            MetaAdsMutationTargetType::Campaign => MetaAdsCampaign::class,
            MetaAdsMutationTargetType::AdSet => MetaAdsAdSet::class,
            MetaAdsMutationTargetType::Ad => MetaAdsAd::class,
        };

        $target = $model::query()
            ->whereKey($mutation->target_local_id)
            ->where('meta_ads_account_id', $mutation->meta_ads_account_id)
            ->first();

        if ($target === null) {
            return null;
        }

        if ((string) $target->status === $mutation->requested_state->providerValue()) {
            return true;
        }

        $since = $operation->completed_at ?? $operation->started_at;
        $syncedAt = $target->last_synced_at;

        return $syncedAt !== null && $since !== null && $syncedAt->greaterThan($since) ? false : null;
    }

    /** Absence of the requested state is evidence only right after a COMPLETE refresh of that entity type. */
    private function mayConcludeNotApplied(MetaAdsMutationTargetType $type, string $afterStage, bool $truncated): bool
    {
        if ($truncated) {
            return false;
        }

        return match ($type) {
            MetaAdsMutationTargetType::Campaign => $afterStage === 'campaigns',
            MetaAdsMutationTargetType::AdSet => $afterStage === 'ad_sets',
            MetaAdsMutationTargetType::Ad => $afterStage === 'ads',
        };
    }

    private function describe(MetaAdsMutation $mutation): string
    {
        return $mutation->target_type->value . ' ' . $mutation->requested_state->value;
    }

    private function maxAgeHours(): int
    {
        return max(1, (int) config('meta_ads.mutations.reconcile_max_age_hours', 72));
    }

    private function pendingStaleMinutes(): int
    {
        return max(1, (int) config('meta_ads.mutations.pending_stale_minutes', 15));
    }
}
