<?php

namespace App\Library\Seo\Rank\Provider;

/** One vendor-neutral task to queue. `tag` is our run uid, echoed back by the vendor. */
final class SeoRankTaskRequest
{
    public function __construct(
        public readonly string $checkType,
        public readonly string $keyword,
        public readonly int $locationCode,
        public readonly string $languageCode,
        public readonly string $device,
        public readonly int $depth,
        public readonly string $tag,
    ) {
    }
}
