<?php

namespace App\Library\GoogleAds\Sync\Contracts;

use App\Models\GoogleAdsAccount;

/**
 * The seam later phases hook into (e.g. the mutation reconciler). Tag an
 * implementation with GoogleAdsSyncCoordinator::OBSERVER_TAG and it is called
 * after every stage that PERSISTED, with the stage key and whether that stage's
 * report was truncated (hit its row cap, so absence proves nothing). It runs outside any
 * transaction, must not call the provider, and a throw is reported and
 * isolated so an observer defect never aborts the sync.
 */
interface GoogleAdsSyncObserver
{
    public function afterStage(GoogleAdsAccount $account, string $stageKey, bool $truncated = false): void;
}
