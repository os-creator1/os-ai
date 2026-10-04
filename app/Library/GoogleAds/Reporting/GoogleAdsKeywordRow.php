<?php

namespace App\Library\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;

/**
 * One keyword line (positive keywords carry metrics; negatives carry none).
 * `qualityScore` is shown only when Google returned one. `internal` holds
 * Google's criterion id and must not reach a view or URL; address by `uid`.
 */
final class GoogleAdsKeywordRow
{
    /** @param  array{external_criterion_id: string}  $internal */
    public function __construct(
        public readonly string $uid,
        public readonly string $text,
        public readonly ?GoogleAdsMatchType $matchType,
        public readonly GoogleAdsEntityStatus $status,
        public readonly GoogleAdsKeywordLevel $level,
        public readonly bool $isNegative,
        public readonly string $campaignUid,
        public readonly string $campaignName,
        public readonly ?string $adGroupName,
        public readonly ?int $qualityScore,
        public readonly GoogleAdsMetricTotals $totals,
        public readonly array $internal,
    ) {
    }

    public function cplMicros(): ?int
    {
        return $this->totals->cplMicros();
    }

    public function conversionRate(): ?float
    {
        return $this->totals->conversionRate();
    }
}
