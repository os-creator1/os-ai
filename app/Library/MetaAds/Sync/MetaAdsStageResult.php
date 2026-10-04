<?php

namespace App\Library\MetaAds\Sync;

/**
 * What one stage persisted. `truncated` means the provider report hit its
 * page / row cap (or the usage threshold cut it short), so the stage wrote
 * what it had and made NO inference from absence. `skipped` counts fetched
 * rows that were NOT written because they referenced an entity this account
 * does not hold locally (never invented). `dataThroughDate` is set only by
 * the campaign-insights stage: the latest metric_date it stored.
 */
final readonly class MetaAdsStageResult
{
    public function __construct(
        public int $rows,
        public bool $truncated = false,
        public ?string $dataThroughDate = null,
        public int $skipped = 0,
    ) {
    }
}
