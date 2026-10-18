<?php

namespace App\Library\Seo\Rank\Provider;

/**
 * Normalized outcome of collecting one task. Items carry only the facts we
 * persist; the raw SERP is never kept.
 */
final class SeoRankTaskResult
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * @param list<SeoRankResultItem> $items
     * @param int|null $costMicros provider-reported cost in micro-USD
     */
    public function __construct(
        public readonly string $state,
        public readonly array $items = [],
        public readonly ?int $costMicros = null,
        public readonly ?string $errorCode = null,
    ) {
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }
}
