<?php

namespace App\Library\GoogleAds\Mutations;

use App\Library\GoogleAds\Sync\Contracts\GoogleAdsSyncObserver;
use App\Models\GoogleAdsAccount;

/**
 * Connects the sync pipeline to the mutation reconciler (contract 23 §6 step 5).
 *
 * An ambiguous mutate (timeout after send) is ledgered `unknown` and never
 * replayed. Google's own state is the only evidence of whether it applied, so
 * once campaigns / keywords have just been re-synced the reconciler compares
 * that fresh state with each unknown operation. It never calls the provider.
 */
final class ReconcileMutationsAfterSync implements GoogleAdsSyncObserver
{
    public function __construct(private readonly GoogleAdsMutationReconciler $reconciler)
    {
    }

    public function afterStage(GoogleAdsAccount $account, string $stageKey): void
    {
        if ($stageKey === 'campaigns' || $stageKey === 'keywords') {
            $this->reconciler->reconcile($account);
        }
    }
}
