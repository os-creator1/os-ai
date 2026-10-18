<?php

namespace App\Library\MetaAds\Sync\Contracts;

use App\Models\MetaAdsAccount;

/**
 * The seam later phases hook into (e.g. the mutation reconciler). Tag an
 * implementation with MetaAdsSyncCoordinator::OBSERVER_TAG
 * (`meta_ads.sync_observers`) and it is called after every stage that
 * PERSISTED, with the stage key (`account`, `campaigns`, `ad_sets`, `ads`,
 * `campaign_insights`, `ad_set_insights`, `ad_insights`, `frequency`) and
 * whether that stage's report was truncated (hit its cap, so absence proves
 * nothing). It runs outside any transaction, must not call the provider, and
 * a throw is reported and isolated so an observer defect never aborts the sync.
 */
interface MetaAdsSyncObserver
{
    public function afterStage(MetaAdsAccount $account, string $stageKey, bool $truncated = false): void;
}
