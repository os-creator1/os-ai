<?php

namespace App\DTO\GoogleAds;

/**
 * Google Ads Module V1 contract §5 — a bounded, paged read result.
 *
 * `truncated` is true when the report hit `sync.max_pages_per_report` or
 * `sync.max_rows_per_report` while Google still had more rows. The caller
 * MUST then mark its run `partial` (failure_code = row_cap): truncated data
 * is never presented as complete.
 *
 * @template T
 */
final readonly class GoogleAdsReportResult
{
    /**
     * @param  array<int, T>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $truncated = false,
        public int $pagesFetched = 1,
    ) {
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
