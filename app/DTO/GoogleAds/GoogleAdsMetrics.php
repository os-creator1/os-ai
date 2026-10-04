<?php

namespace App\DTO\GoogleAds;

use App\Library\GoogleAds\GoogleAdsJson as J;

/**
 * Google Ads Module V1 contract §2 — the `metrics` the module uses:
 * impressions, clicks, interactions, cost_micros, conversions,
 * conversions_value (NOT all_conversions — V1 uses the primary-action
 * `conversions`).
 *
 * impressions / clicks / interactions / costMicros are read as 0 when Google
 * omits a REQUESTED field on a returned row. conversions and conversionsValue
 * stay NULL when absent: "no conversion data" is not "0 conversions". They
 * are exact 6-place decimal strings (account currency for the value).
 */
final readonly class GoogleAdsMetrics
{
    public function __construct(
        public int $impressions,
        public int $clicks,
        public int $interactions,
        public int $costMicros,
        public ?string $conversions,
        public ?string $conversionsValue,
    ) {
    }

    /**
     * @param  mixed  $metrics  the row's `metrics` object (may be absent)
     */
    public static function fromProvider(mixed $metrics): self
    {
        $m = is_array($metrics) ? $metrics : [];

        return new self(
            impressions: J::unsignedInt64($m['impressions'] ?? null) ?? 0,
            clicks: J::unsignedInt64($m['clicks'] ?? null) ?? 0,
            interactions: J::unsignedInt64($m['interactions'] ?? null) ?? 0,
            costMicros: J::unsignedInt64($m['costMicros'] ?? null) ?? 0,
            conversions: J::decimal($m['conversions'] ?? null),
            conversionsValue: J::decimal($m['conversionsValue'] ?? null),
        );
    }
}
