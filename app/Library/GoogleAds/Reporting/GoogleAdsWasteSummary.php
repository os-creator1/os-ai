<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Total potential-waste picture for a period. Terms an enabled negative
 * already covers are counted separately (`alreadyExcluded*`) and are NOT in
 * `termCount` / `spendMicros`, so nothing already handled is recommended
 * again. `hasData` is false when there are no search-term rows at all
 * (then every figure is null: no data is not "no waste").
 */
final class GoogleAdsWasteSummary
{
    /** @param  array<int, GoogleAdsSearchTermRow>  $topTerms  highest-spend still-actionable waste terms */
    public function __construct(
        public readonly bool $hasData,
        public readonly ?int $termCount,
        public readonly ?int $spendMicros,
        public readonly ?int $clicks,
        public readonly ?int $alreadyExcludedCount,
        public readonly ?int $alreadyExcludedSpendMicros,
        public readonly array $topTerms,
    ) {
    }
}
