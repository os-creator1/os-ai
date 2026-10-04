<?php

namespace Tests\Unit\GoogleAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsMetricTotals;
use PHPUnit\Framework\TestCase;

/** NULL IS NOT ZERO — derived metrics of a summed block. Pure. */
class GoogleAdsMetricTotalsTest extends TestCase
{
    private function row(array $o = []): object
    {
        return (object) array_merge([
            'row_count' => 3, 'day_count' => 3, 'spend_micros' => '30000000', 'clicks' => '30', 'impressions' => '300',
            'conversions' => '3.000000', 'conversions_value' => null, 'last_date' => '2026-10-03',
        ], $o);
    }

    public function test_no_rows_is_all_null_not_zero(): void
    {
        foreach ([null, $this->row(['row_count' => 0])] as $row) {
            $t = GoogleAdsMetricTotals::fromRow($row);
            $this->assertFalse($t->hasData());
            $this->assertNull($t->spendMicros);
            $this->assertNull($t->conversions);
            $this->assertNull($t->cplMicros());
            $this->assertNull($t->conversionRate());
            $this->assertNull($t->conversionValue());
        }
    }

    public function test_rows_with_zero_conversions_give_zero_conversions_but_null_cpl(): void
    {
        $t = GoogleAdsMetricTotals::fromRow($this->row(['conversions' => '0.000000']));

        $this->assertTrue($t->hasData());
        $this->assertSame('0.000000', $t->conversions);
        $this->assertNull($t->cplMicros());
        $this->assertSame(0.0, $t->conversionRate());
        $this->assertSame('0', $t->conversionsDisplay());
    }

    public function test_rows_with_null_conversions_have_no_conversion_data(): void
    {
        $t = GoogleAdsMetricTotals::fromRow($this->row(['conversions' => null]));

        $this->assertSame(30_000_000, $t->spendMicros);
        $this->assertNull($t->conversions);
        $this->assertNull($t->cplMicros());
        $this->assertNull($t->conversionRate());
    }

    public function test_cpl_rate_and_display(): void
    {
        $t = GoogleAdsMetricTotals::fromRow($this->row());

        $this->assertSame(10_000_000, $t->cplMicros());
        $this->assertEqualsWithDelta(0.1, $t->conversionRate(), 1e-9);
        $this->assertSame('3', $t->conversionsDisplay());
        $this->assertSame('2.5', GoogleAdsMetricTotals::trim('2.500000'));
        $this->assertSame('30', GoogleAdsMetricTotals::trim('30'));
    }

    public function test_conversion_rate_is_null_without_clicks(): void
    {
        $this->assertNull(GoogleAdsMetricTotals::fromRow($this->row(['clicks' => '0']))->conversionRate());
    }

    public function test_conversion_value_only_when_positive(): void
    {
        $this->assertNull(GoogleAdsMetricTotals::fromRow($this->row(['conversions_value' => '0.000000']))->conversionValue());
        $this->assertNull(GoogleAdsMetricTotals::fromRow($this->row(['conversions_value' => null]))->conversionValue());
        $this->assertSame('120.500000', GoogleAdsMetricTotals::fromRow($this->row(['conversions_value' => '120.5']))->conversionValue());
    }
}
