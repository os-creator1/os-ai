<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Library\MetaAds\Reporting\MetaAdsMetricTotals;
use Tests\TestCase;

/**
 * The single cost-per-result calculation (per row and aggregate): BCMath, half-up
 * rounding, and null (never a clamped maximum) when the result cannot be a PHP int.
 */
class MetaAdsCostPerResultTest extends TestCase
{
    public function test_fractional_results_round_half_up(): void
    {
        $this->assertSame(3_333_333, MetaAdsMetricTotals::costPerResult(10_000_000, '3'));        // 3333333.33
        $this->assertSame(6_666_667, MetaAdsMetricTotals::costPerResult(20_000_000, '3'));        // 6666666.67
        $this->assertSame(4_000_000, MetaAdsMetricTotals::costPerResult(10_000_000, '2.5'));      // exact
        $this->assertSame(3, MetaAdsMetricTotals::costPerResult(5, '2'));                         // 2.5 rounds half up to 3
    }

    public function test_missing_zero_or_negative_inputs_stay_null(): void
    {
        $this->assertNull(MetaAdsMetricTotals::costPerResult(null, '3'));
        $this->assertNull(MetaAdsMetricTotals::costPerResult(10_000_000, null));
        $this->assertNull(MetaAdsMetricTotals::costPerResult(10_000_000, '0'));
        $this->assertNull(MetaAdsMetricTotals::costPerResult(10_000_000, '0.000000'));
        $this->assertNull(MetaAdsMetricTotals::costPerResult(10_000_000, '-1'));
        $this->assertSame(0, MetaAdsMetricTotals::costPerResult(0, '3'), 'zero spend over real results is a real zero cost');
    }

    public function test_the_upper_integer_boundary_is_representable_and_anything_above_is_null(): void
    {
        $this->assertSame(PHP_INT_MAX, MetaAdsMetricTotals::costPerResult(PHP_INT_MAX, '1'), 'exactly PHP_INT_MAX is returned as is');
        $this->assertNull(MetaAdsMetricTotals::costPerResult(PHP_INT_MAX, '0.999999'), 'rounds above PHP_INT_MAX');
        $this->assertNull(MetaAdsMetricTotals::costPerResult(PHP_INT_MAX, '0.5'), 'overflows: null, not a fabricated maximum');
        $this->assertNull(MetaAdsMetricTotals::costPerResult(PHP_INT_MAX, '0.000001'));
    }

    public function test_the_per_row_method_uses_the_same_calculation(): void
    {
        $row = new MetaAdsMetricTotals(1, 1, PHP_INT_MAX, 1000, 20, 10, '0.5', null, null, true);
        $this->assertNull($row->costPerResultMicros());

        $normal = new MetaAdsMetricTotals(1, 1, 20_000_000, 1000, 20, 10, '3', null, null, true);
        $this->assertSame(6_666_667, $normal->costPerResultMicros());
    }
}
