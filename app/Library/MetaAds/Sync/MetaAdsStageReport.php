<?php

namespace App\Library\MetaAds\Sync;

/**
 * What one stage FETCHED, complete before anything is written. Meta clients
 * return one page per call, so the stage pages here, bounded by
 * max_pages_per_report / max_rows_per_report.
 *
 *  - `truncated`: a cap was hit (or paging stopped for usage) while Meta still
 *    had more rows, so the rows are a prefix and absence proves nothing.
 *  - `usageHigh`: Meta reported API usage at or above sync.usage_stop_percent;
 *    the run persists this stage and then stops cleanly as partial/usage_high.
 *
 * @template T
 */
final readonly class MetaAdsStageReport
{
    /**
     * @param  array<int, T>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $truncated = false,
        public bool $usageHigh = false,
    ) {
    }
}
