<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Contract §9 — the deterministic pacing facts for one calendar month in the
 * account time zone. Money is micros in the account currency. The campaign
 * DAILY budget is never part of this object: it is a different fact.
 */
final class GoogleAdsPacing
{
    public function __construct(
        public readonly GoogleAdsPacingStatus $status,
        public readonly ?int $monthlyTargetMicros,
        public readonly ?int $spentMicros,
        /** (spent / days with data) x days in month; null with zero days of data. */
        public readonly ?int $projectedMicros,
        /** True when 0 < days with data < pacing.min_days. */
        public readonly bool $projectionLowConfidence,
        /** days elapsed / days in month (null without data). */
        public readonly ?float $elapsedProportion,
        /** spent / target (null without a target or data). */
        public readonly ?float $spendProportion,
        public readonly int $daysElapsed,
        public readonly int $daysInMonth,
        public readonly int $daysWithData,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'monthly_target_micros' => $this->monthlyTargetMicros,
            'spent_micros' => $this->spentMicros,
            'projected_micros' => $this->projectedMicros,
            'projection_low_confidence' => $this->projectionLowConfidence,
            'elapsed_proportion' => $this->elapsedProportion,
            'spend_proportion' => $this->spendProportion,
            'days_elapsed' => $this->daysElapsed,
            'days_in_month' => $this->daysInMonth,
            'days_with_data' => $this->daysWithData,
        ];
    }
}
