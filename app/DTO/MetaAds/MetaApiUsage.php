<?php

namespace App\DTO\MetaAds;

/**
 * Meta Ads Module V1 contract §6 — quota usage parsed from the
 * `X-Business-Use-Case-Usage` / `X-Ad-Account-Usage` response headers. Each
 * figure is a percentage (0..100+); when Meta sent several entries the MAX is
 * kept. `regainSeconds` is Meta's estimated time to regain access (seconds),
 * null when not throttled.
 */
final readonly class MetaApiUsage
{
    public function __construct(
        public float $callCountPct = 0.0,
        public float $totalTimePct = 0.0,
        public float $totalCpuPct = 0.0,
        public ?int $regainSeconds = null,
    ) {
    }

    public function maxPct(): float
    {
        return max($this->callCountPct, $this->totalTimePct, $this->totalCpuPct);
    }

    public function isAtOrAbove(int $percent): bool
    {
        return $this->maxPct() >= $percent;
    }
}
