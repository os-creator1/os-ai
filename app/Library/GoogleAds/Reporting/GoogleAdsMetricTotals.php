<?php

namespace App\Library\GoogleAds\Reporting;

use App\Library\GoogleAds\GoogleAdsMoney;

/**
 * Google Ads Module V1 contract §9 — a summed block of cached daily facts for
 * ONE account (so one currency) and the derived metrics that hang off it.
 *
 * NULL IS NOT ZERO:
 *   - no rows at all            => every field null, hasData() false
 *   - rows, conversions all null => conversions null (no conversion data)
 *   - rows summing to zero       => conversions "0.000000" (a real zero), CPL null
 *
 * Derived values go through GoogleAdsMoney (cpl / conversionRate) so the
 * "conversions <= 0 => null" rules live in exactly one place.
 */
final class GoogleAdsMetricTotals
{
    public function __construct(
        public readonly int $rowCount,
        public readonly int $dayCount,
        public readonly ?int $spendMicros,
        public readonly ?int $clicks,
        public readonly ?int $impressions,
        public readonly ?string $conversions,
        public readonly ?string $conversionsValue,
        public readonly ?string $lastDate = null,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, null, null, null, null, null, null);
    }

    /**
     * Hydrates from an aggregate row produced with
     * GoogleAdsMetricQueries::AGGREGATE_COLUMNS (null row / zero rows => empty).
     */
    public static function fromRow(?object $row): self
    {
        if ($row === null || (int) ($row->row_count ?? 0) === 0) {
            return self::empty();
        }

        return new self(
            (int) $row->row_count,
            (int) $row->day_count,
            $row->spend_micros === null ? null : (int) $row->spend_micros,
            $row->clicks === null ? null : (int) $row->clicks,
            $row->impressions === null ? null : (int) $row->impressions,
            self::decimal($row->conversions),
            self::decimal($row->conversions_value),
            $row->last_date === null ? null : (string) $row->last_date,
        );
    }

    public function hasData(): bool
    {
        return $this->rowCount > 0;
    }

    /** Cost per conversion in micros; null when conversions are null or 0. */
    public function cplMicros(): ?int
    {
        return GoogleAdsMoney::cpl($this->spendMicros, $this->conversions);
    }

    /** Conversions per click; null without conversion data or clicks. */
    public function conversionRate(): ?float
    {
        return GoogleAdsMoney::conversionRate($this->conversions, $this->clicks);
    }

    /** Google's conversion value, only when it is positive (else null, not 0). */
    public function conversionValue(): ?string
    {
        return $this->conversionsValue !== null && bccomp($this->conversionsValue, '0', 6) > 0
            ? $this->conversionsValue
            : null;
    }

    /** "3", "2.5" — trailing zeros trimmed for display; null stays null. */
    public function conversionsDisplay(): ?string
    {
        return self::trim($this->conversions);
    }

    public static function trim(?string $decimal): ?string
    {
        if ($decimal === null) {
            return null;
        }

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    private static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return bcadd((string) $value, '0', 6);
    }
}
