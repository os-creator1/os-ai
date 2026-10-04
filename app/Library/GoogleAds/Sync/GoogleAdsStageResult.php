<?php

namespace App\Library\GoogleAds\Sync;

/**
 * What one stage persisted. `truncated` means the provider report hit its
 * cap, so the stage wrote what it had and made NO inference from absence.
 * `dataThroughDate` is set only by the campaign-metrics stage: the latest
 * metric_date the provider actually returned.
 */
final readonly class GoogleAdsStageResult
{
    public function __construct(
        public int $rows,
        public bool $truncated = false,
        public ?string $dataThroughDate = null,
    ) {
    }
}
