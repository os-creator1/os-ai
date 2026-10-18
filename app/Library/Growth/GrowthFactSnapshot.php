<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthFactStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * Everything one evaluation reads, loaded ONCE per Business (Growth Center
 * §16): each domain reader runs its bounded queries a single time, and then
 * every rule is a pure function of this object. Rules never touch the
 * database, so rule count cannot multiply query count.
 */
final class GrowthFactSnapshot
{
    /**
     * @param  array<string, GrowthFactSet>  $sets  keyed by domain
     * @param  array<int, string>  $locationNames  active Location id => display name
     */
    public function __construct(
        public readonly Business $business,
        public readonly CarbonImmutable $now,
        public readonly GrowthThresholds $thresholds,
        private readonly array $sets,
        public readonly array $locationNames,
        public readonly array $failedDomains = [],
    ) {
    }

    /** A reader that THREW is not "unavailable": its workers must fail, not read stale. */
    public function hasFailed(string $domain): bool
    {
        return in_array($domain, $this->failedDomains, true);
    }

    public function set(string $domain): GrowthFactSet
    {
        return $this->sets[$domain] ?? GrowthFactSet::withStatus($domain, GrowthFactStatus::Unavailable);
    }

    /** @return array<string, GrowthFactSet> */
    public function sets(): array
    {
        return $this->sets;
    }
}
