<?php

namespace App\DTO\MetaAds;

/**
 * Meta Ads Module V1 contract §6 — ONE page of a Graph list. `nextCursor` is
 * the `after` cursor to pass to the NEXT call, or null on the last page. The
 * `paging.next` URL is never exposed (it embeds the access token). `usage` is
 * what Meta reported on this call, when it reported anything.
 *
 * @template T
 */
final readonly class MetaReportPage
{
    /**
     * @param  array<int, T>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor = null,
        public ?MetaApiUsage $usage = null,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
