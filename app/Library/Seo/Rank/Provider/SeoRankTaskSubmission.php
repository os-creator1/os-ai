<?php

namespace App\Library\Seo\Rank\Provider;

final class SeoRankTaskSubmission
{
    /** @param int|null $costMicros provider-reported cost in micro-USD, if exposed at submit time */
    public function __construct(
        public readonly string $taskId,
        public readonly ?int $costMicros = null,
    ) {
    }
}
