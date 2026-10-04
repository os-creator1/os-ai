<?php

namespace App\Library\GoogleAds\Reporting;

use App\Library\GoogleAds\GoogleAdsConfig;

/**
 * Google Ads Module V1 contract §9 — PURE, deterministic pacing and CPL
 * status maths. No database, no clock, no framework: everything is an
 * argument, so every boundary is unit-testable. AI never produces these
 * numbers.
 *
 * PROJECTION (documented simple method):
 *     projected month-end spend = (month-to-date spend / days WITH DATA) x days in month
 * rounded half up to a micro. Zero days of data => null (omitted, never a
 * fabricated 0). Fewer than $minDays days of data => still computed but
 * flagged low confidence.
 *
 * Worked example: 104 spent over 8 days with data in a 30-day month projects
 * 104 / 8 x 30 = 390.
 *
 * PACING STATUS vs the MONTHLY target (needs data on at least $minDays days):
 *     expected spend so far = target x daysElapsed / daysInMonth
 *     on_pace  when |spent - expected| <= expected x tolerance   (inclusive)
 *     ahead    when spent > expected x (1 + tolerance)
 *     behind   when spent < expected x (1 - tolerance)
 * evaluated with exact bcmath cross-multiplication so a value exactly on the
 * tolerance edge is on_pace, never a float-rounding coin flip. `daysElapsed`
 * is the day-of-month of the LATEST day that has data (spend and elapsed days
 * then describe the same span, so a stale sync does not read as "behind").
 *
 * CPL STATUS vs the target CPL, inside a +/- $tolerance band:
 *     better     cpl < target x (1 - tolerance)
 *     on_target  within the band, edges inclusive
 *     worse      cpl > target x (1 + tolerance)
 * No target => no_target; no current CPL (no conversions) => null (no status).
 */
final class GoogleAdsPacingCalculator
{
    public function __construct(
        private readonly float $tolerance = 0.15,
        private readonly int $minDays = 7,
    ) {
    }

    public static function fromConfig(GoogleAdsConfig $config): self
    {
        return new self($config->pacingTolerance(), $config->pacingMinDays());
    }

    public function pacing(?int $spentMicros, ?int $targetMicros, int $daysElapsed, int $daysInMonth, int $daysWithData): GoogleAdsPacing
    {
        $daysInMonth = max(1, $daysInMonth);
        $daysElapsed = max(0, min($daysElapsed, $daysInMonth));
        $hasData = $spentMicros !== null && $daysWithData > 0;
        $hasTarget = $targetMicros !== null && $targetMicros > 0;

        $projected = $hasData
            ? (int) bcadd(bcdiv(bcmul((string) $spentMicros, (string) $daysInMonth, 0), (string) $daysWithData, 6), '0.5', 0)
            : null;

        $status = match (true) {
            ! $hasTarget => GoogleAdsPacingStatus::NoTarget,
            ! $hasData || $daysWithData < $this->minDays => GoogleAdsPacingStatus::InsufficientData,
            default => $this->statusFor((int) $spentMicros, (int) $targetMicros, $daysElapsed, $daysInMonth),
        };

        return new GoogleAdsPacing(
            status: $status,
            monthlyTargetMicros: $targetMicros,
            spentMicros: $hasData ? $spentMicros : null,
            projectedMicros: $projected,
            projectionLowConfidence: $hasData && $daysWithData < $this->minDays,
            elapsedProportion: $hasData ? $daysElapsed / $daysInMonth : null,
            spendProportion: $hasData && $hasTarget ? (float) bcdiv((string) $spentMicros, (string) $targetMicros, 10) : null,
            daysElapsed: $daysElapsed,
            daysInMonth: $daysInMonth,
            daysWithData: $daysWithData,
        );
    }

    public function cplStatus(?int $currentCplMicros, ?int $targetCplMicros): ?GoogleAdsCplStatus
    {
        if ($targetCplMicros === null || $targetCplMicros <= 0) {
            return GoogleAdsCplStatus::NoTarget;
        }

        if ($currentCplMicros === null) {
            return null;
        }

        return match (true) {
            $this->above($currentCplMicros, 1, $targetCplMicros) => GoogleAdsCplStatus::Worse,
            $this->below($currentCplMicros, 1, $targetCplMicros) => GoogleAdsCplStatus::Better,
            default => GoogleAdsCplStatus::OnTarget,
        };
    }

    private function statusFor(int $spent, int $target, int $daysElapsed, int $daysInMonth): GoogleAdsPacingStatus
    {
        // spent x daysInMonth  vs  target x daysElapsed  (both sides scaled by daysInMonth)
        return match (true) {
            $this->above($spent * 1, $daysInMonth, $target * $daysElapsed) => GoogleAdsPacingStatus::Ahead,
            $this->below($spent * 1, $daysInMonth, $target * $daysElapsed) => GoogleAdsPacingStatus::Behind,
            default => GoogleAdsPacingStatus::OnPace,
        };
    }

    /** value x scale > reference x (1 + tolerance), exact. */
    private function above(int $value, int $scale, int|string $reference): bool
    {
        $left = bcmul((string) $value, (string) $scale, 0);
        $right = bcmul((string) $reference, $this->factor(1), 10);

        return bccomp($left, $right, 10) > 0;
    }

    /** value x scale < reference x (1 - tolerance), exact. */
    private function below(int $value, int $scale, int|string $reference): bool
    {
        $left = bcmul((string) $value, (string) $scale, 0);
        $right = bcmul((string) $reference, $this->factor(-1), 10);

        return bccomp($left, $right, 10) < 0;
    }

    private function factor(int $sign): string
    {
        $tolerance = number_format($this->tolerance, 10, '.', '');

        return $sign > 0 ? bcadd('1', $tolerance, 10) : bcsub('1', $tolerance, 10);
    }
}
