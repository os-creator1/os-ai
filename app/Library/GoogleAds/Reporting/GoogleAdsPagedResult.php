<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * A page of reader rows plus the paging facts a table footer needs.
 *
 * @template T
 */
final class GoogleAdsPagedResult
{
    public readonly int $lastPage;

    /** @param  array<int, T>  $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }
}
