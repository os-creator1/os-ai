<?php

namespace Tests\Unit\GoogleAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsCplStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingCalculator;
use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus;
use PHPUnit\Framework\TestCase;

/** Contract §9 — pure pacing / projection / CPL-status maths. No framework, no database. */
class GoogleAdsPacingCalculatorTest extends TestCase
{
    private function calc(): GoogleAdsPacingCalculator
    {
        return new GoogleAdsPacingCalculator(0.15, 7);
    }

    public function test_projection_documented_example_104_over_8_days_in_a_30_day_month_is_390(): void
    {
        $pacing = $this->calc()->pacing(104_000_000, null, 8, 30, 8);

        $this->assertSame(390_000_000, $pacing->projectedMicros);
        $this->assertFalse($pacing->projectionLowConfidence);
    }

    public function test_projection_is_low_confidence_under_min_days_and_omitted_with_zero_days(): void
    {
        $low = $this->calc()->pacing(30_000_000, null, 3, 31, 3);
        $this->assertSame(310_000_000, $low->projectedMicros);
        $this->assertTrue($low->projectionLowConfidence);

        $none = $this->calc()->pacing(null, 100_000_000, 0, 31, 0);
        $this->assertNull($none->projectedMicros);
        $this->assertNull($none->spentMicros);
        $this->assertFalse($none->projectionLowConfidence);
        $this->assertSame(GoogleAdsPacingStatus::InsufficientData, $none->status);
    }

    public function test_projection_uses_days_with_data_not_days_elapsed(): void
    {
        // Elapsed 10 days but only 8 of them have data: 80 / 8 x 30 = 300.
        $this->assertSame(300_000_000, $this->calc()->pacing(80_000_000, null, 10, 30, 8)->projectedMicros);
    }

    public function test_no_target_wins_over_insufficient_data(): void
    {
        $this->assertSame(GoogleAdsPacingStatus::NoTarget, $this->calc()->pacing(10_000_000, null, 2, 31, 2)->status);
        $this->assertSame(GoogleAdsPacingStatus::NoTarget, $this->calc()->pacing(10_000_000, 0, 2, 31, 2)->status);
    }

    public function test_insufficient_data_below_min_days_with_a_target(): void
    {
        $this->assertSame(GoogleAdsPacingStatus::InsufficientData, $this->calc()->pacing(900_000_000, 100_000_000, 6, 31, 6)->status);
    }

    public function test_pacing_status_boundaries_are_inclusive_on_pace(): void
    {
        // target 3.1 / month 31 days, 10 elapsed => expected spend exactly 1.0 (1,000,000 micros).
        $statusFor = fn (int $spent) => $this->calc()->pacing($spent, 3_100_000, 10, 31, 10)->status;

        $this->assertSame(GoogleAdsPacingStatus::OnPace, $statusFor(1_000_000));
        $this->assertSame(GoogleAdsPacingStatus::OnPace, $statusFor(1_150_000), 'exactly +15% is on pace');
        $this->assertSame(GoogleAdsPacingStatus::Ahead, $statusFor(1_150_001));
        $this->assertSame(GoogleAdsPacingStatus::OnPace, $statusFor(850_000), 'exactly -15% is on pace');
        $this->assertSame(GoogleAdsPacingStatus::Behind, $statusFor(849_999));
    }

    public function test_pacing_proportions_are_reported(): void
    {
        $pacing = $this->calc()->pacing(50_000_000, 100_000_000, 10, 30, 10);

        $this->assertEqualsWithDelta(0.5, $pacing->spendProportion, 1e-9);
        $this->assertEqualsWithDelta(10 / 30, $pacing->elapsedProportion, 1e-9);
        $this->assertSame(GoogleAdsPacingStatus::Ahead, $pacing->status);
        $this->assertSame(100_000_000, $pacing->monthlyTargetMicros);
        $this->assertSame(10, $pacing->daysElapsed);
        $this->assertSame(30, $pacing->daysInMonth);
    }

    public function test_cpl_status_against_target(): void
    {
        $c = $this->calc();

        $this->assertSame(GoogleAdsCplStatus::NoTarget, $c->cplStatus(10_000_000, null));
        $this->assertSame(GoogleAdsCplStatus::NoTarget, $c->cplStatus(null, null));
        $this->assertNull($c->cplStatus(null, 20_000_000), 'current CPL null => no status');

        $this->assertSame(GoogleAdsCplStatus::OnTarget, $c->cplStatus(20_000_000, 20_000_000));
        $this->assertSame(GoogleAdsCplStatus::OnTarget, $c->cplStatus(23_000_000, 20_000_000), '+15% inclusive');
        $this->assertSame(GoogleAdsCplStatus::Worse, $c->cplStatus(23_000_001, 20_000_000));
        $this->assertSame(GoogleAdsCplStatus::OnTarget, $c->cplStatus(17_000_000, 20_000_000), '-15% inclusive');
        $this->assertSame(GoogleAdsCplStatus::Better, $c->cplStatus(16_999_999, 20_000_000));
    }
}
