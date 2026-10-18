<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §5.3 — an ad set's own trailing-window reach and
 * frequency from ONE non-daily insights call. Not additive: never summed
 * across days or entities.
 */
final readonly class MetaFrequencyRow
{
    public function __construct(
        public string $externalAdSetId,
        public ?int $reach,
        public ?float $frequency,
        public string $windowStart,
        public string $windowEnd,
    ) {
        if (preg_match('/\A\d{1,20}\z/', $externalAdSetId) !== 1) {
            throw new InvalidArgumentException('Invalid ad set id.');
        }
    }
}
