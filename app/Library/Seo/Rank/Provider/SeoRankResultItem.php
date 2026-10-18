<?php

namespace App\Library\Seo\Rank\Provider;

/**
 * One SERP/local-finder row, normalized. `position` is the provider's
 * rank_group (position within the organic / local group), 1-based.
 */
final class SeoRankResultItem
{
    public function __construct(
        public readonly int $position,
        public readonly ?string $domain,
        public readonly ?string $url,
        public readonly ?string $phone = null,
        public readonly ?string $cid = null,
    ) {
    }
}
