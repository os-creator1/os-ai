<?php

namespace App\Library\ExternalSite\Audit;

/**
 * What the evaluator needs to know BESIDES the crawl: the thresholds it judges
 * lengths by (conventional guidance, never Google-stated requirements) and the few
 * facts of the Business that local-SEO checks compare against. Plain values, so the
 * evaluator stays pure and unit-testable without a database.
 */
final class ExternalAuditContext
{
    /**
     * @param  list<string>  $cities  the cities of the Business's active locations
     */
    public function __construct(
        public readonly string $businessName = '',
        public readonly ?string $phoneDigits = null,
        public readonly array $cities = [],
        public readonly bool $hasLocation = false,
        public readonly int $titleMax = 60,
        public readonly int $titleMin = 15,
        public readonly int $descriptionMin = 70,
        public readonly int $descriptionMax = 160,
        public readonly int $largeImageBytes = 1_000_000,
        public readonly int $deepPageDepth = 4,
        public readonly int $thinLocationWords = 150,
    ) {
    }

    /** The terms the crawler should look for on every page (cities only; the business name is not needed). */
    public function terms(): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $c): string => trim($c), $this->cities), fn (string $c): bool => $c !== '')));
    }
}
