<?php

namespace App\Library\MetaAds\Mutations;

use App\Library\MetaAds\Sync\Contracts\MetaAdsSyncObserver;
use App\Models\MetaAdsAccount;

/**
 * Connects the Meta sync pipeline to the mutation reconciler (contract 24 §7).
 *
 * An ambiguous mutate (timeout after send) is ledgered `unknown` and never
 * replayed; Meta's own synced state is the only evidence of whether it applied.
 * After the campaigns / ad_sets / ads stage persisted, the reconciler compares
 * that fresh state with each unknown operation. It never calls the provider.
 * This is the class tagged with MetaAdsSyncCoordinator::OBSERVER_TAG.
 */
final class ReconcileMetaMutationsAfterSync implements MetaAdsSyncObserver
{
    public function __construct(private readonly MetaAdsMutationReconciler $reconciler)
    {
    }

    public function afterStage(MetaAdsAccount $account, string $stageKey, bool $truncated = false): void
    {
        if (in_array($stageKey, MetaAdsMutationReconciler::STAGES, true)) {
            $this->reconciler->reconcile($account, $stageKey, $truncated);
        }
    }
}
