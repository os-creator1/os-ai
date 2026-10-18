<?php

namespace App\Library\MetaAds\Reporting;

/**
 * One ad line for a period. Addressed by `uid`; no Meta ids.
 *
 * `creative` is a read-only display summary: title and body are customer data
 * (the view must escape them); `thumbnail_url` is present ONLY when the URL
 * passes MetaAdsConfig::allowedThumbnailUrl (https + allow-listed host),
 * otherwise null.
 */
final class MetaAdsAdRow
{
    /**
     * @param  array{title: ?string, body: ?string, thumbnail_url: ?string, object_type: ?string}  $creative
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $effectiveStatus,
        public readonly string $campaignUid,
        public readonly string $campaignName,
        public readonly string $adSetUid,
        public readonly string $adSetName,
        public readonly array $creative,
        public readonly string $currencyCode,
        public readonly MetaAdsMetricTotals $totals,
        public readonly int $localId = 0,
    ) {
    }

    public function spendMicros(): ?int
    {
        return $this->totals->spendMicros;
    }

    public function costPerResultMicros(): ?int
    {
        return $this->totals->costPerResultMicros();
    }
}
