<?php

namespace App\Library\MetaAds\Reporting;

/**
 * Meta Ads Module V1 contract 24 §5 — a summed block of cached daily facts for
 * ONE account (so one currency) and the derived metrics that hang off it.
 *
 * NULL IS NOT ZERO:
 *   - no insight rows at all         => every field null, hasData() false
 *   - rows, link_clicks all NULL     => linkClicks null (Meta returned none), ctr/cpc null
 *   - no result type chosen          => results / resultValue / cost per result null
 *                                       (UNAVAILABLE, shown as "Choose a result type")
 *   - result type chosen:
 *       result rows exist            => their sum
 *       no result rows for THIS block but the account has result rows of the
 *       chosen type at this level in the window => "0.000000" (a real zero: Meta
 *       reported results for others and none here)
 *       no result rows anywhere      => null (cannot tell zero from "not stored")
 *
 * Definitions (contract §5.3):
 *   cost per result = spend / chosen-type results (null when results <= 0 or null)
 *   CTR  = link clicks / impressions ("Link CTR"; null when link clicks null or impressions 0)
 *   CPC  = spend / link clicks        ("Cost per link click"; null when link clicks null or 0)
 *   CPM  = spend / impressions x 1000 (null when impressions 0)
 * All money in micros, rounded half up. Pure maths, no I/O.
 *
 * Aggregate row columns read by fromRow(): row_count, day_count, spend_micros,
 * impressions, clicks, link_clicks, last_date and the optional results,
 * result_value, result_row_count.
 */
final class MetaAdsMetricTotals
{
    public function __construct(
        public readonly int $rowCount,
        public readonly int $dayCount,
        public readonly ?int $spendMicros,
        public readonly ?int $impressions,
        public readonly ?int $clicks,
        public readonly ?int $linkClicks,
        /** Chosen-type results as a decimal string, or null (unavailable). */
        public readonly ?string $results,
        /** Meta-reported action value of the chosen type (raw sum), or null. */
        public readonly ?string $resultValueRaw,
        public readonly ?string $lastDate = null,
        /** Whether the account has an owner-chosen result type (false => "Choose a result type"). */
        public readonly bool $resultTypeChosen = false,
    ) {
    }

    public static function empty(bool $resultTypeChosen = false): self
    {
        return new self(0, 0, null, null, null, null, null, null, null, $resultTypeChosen);
    }

    public static function fromRow(?object $row, bool $resultTypeChosen = false, bool $resultDataPresent = false): self
    {
        if ($row === null || (int) ($row->row_count ?? 0) === 0) {
            return self::empty($resultTypeChosen);
        }

        $resultRows = (int) ($row->result_row_count ?? 0);
        $results = null;
        $value = null;

        if ($resultTypeChosen) {
            if ($resultRows > 0) {
                $results = self::decimal($row->results ?? null);
                $value = self::decimal($row->result_value ?? null);
            } elseif ($resultDataPresent) {
                $results = '0.000000';
            }
        }

        return new self(
            (int) $row->row_count,
            (int) ($row->day_count ?? 0),
            ($row->spend_micros ?? null) === null ? null : (int) $row->spend_micros,
            ($row->impressions ?? null) === null ? null : (int) $row->impressions,
            ($row->clicks ?? null) === null ? null : (int) $row->clicks,
            ($row->link_clicks ?? null) === null ? null : (int) $row->link_clicks,
            $results,
            $value,
            ($row->last_date ?? null) === null ? null : (string) $row->last_date,
            $resultTypeChosen,
        );
    }

    public function hasData(): bool
    {
        return $this->rowCount > 0;
    }

    /** Cost per result in micros (half up); null when results are null or <= 0 or spend is null. */
    public function costPerResultMicros(): ?int
    {
        if ($this->spendMicros === null || $this->results === null || bccomp($this->results, '0', 6) <= 0) {
            return null;
        }

        $quotient = bcadd(bcdiv((string) $this->spendMicros, $this->results, 6), '0.5', 0);

        return bccomp($quotient, (string) PHP_INT_MAX, 0) > 0 ? null : (int) $quotient;
    }

    /** Link CTR = link clicks / impressions. */
    public function ctr(): ?float
    {
        if ($this->linkClicks === null || $this->impressions === null || $this->impressions <= 0) {
            return null;
        }

        return (float) bcdiv((string) $this->linkClicks, (string) $this->impressions, 8);
    }

    /** Cost per link click in micros. */
    public function cpcMicros(): ?int
    {
        if ($this->spendMicros === null || $this->linkClicks === null || $this->linkClicks <= 0) {
            return null;
        }

        return (int) bcadd(bcdiv((string) $this->spendMicros, (string) $this->linkClicks, 6), '0.5', 0);
    }

    /** Cost per 1,000 impressions in micros. */
    public function cpmMicros(): ?int
    {
        if ($this->spendMicros === null || $this->impressions === null || $this->impressions <= 0) {
            return null;
        }

        return (int) bcadd(bcdiv(bcmul((string) $this->spendMicros, '1000', 0), (string) $this->impressions, 6), '0.5', 0);
    }

    /** Meta's reported value of the chosen result type, only when positive (else null, not 0). */
    public function resultValue(): ?string
    {
        return $this->resultValueRaw !== null && bccomp($this->resultValueRaw, '0', 6) > 0
            ? $this->resultValueRaw
            : null;
    }

    /** "3", "2.5" - trailing zeros trimmed for display; null stays null. */
    public function resultsDisplay(): ?string
    {
        return self::trim($this->results);
    }

    public static function trim(?string $decimal): ?string
    {
        if ($decimal === null) {
            return null;
        }

        $text = str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;

        return $text === '' ? '0' : $text;
    }

    private static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return bcadd((string) $value, '0', 6);
    }
}
