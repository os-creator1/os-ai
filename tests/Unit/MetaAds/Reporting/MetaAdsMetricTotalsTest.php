<?php

namespace Tests\Unit\MetaAds\Reporting;

use App\Library\MetaAds\Reporting\MetaAdsListInput;
use App\Library\MetaAds\Reporting\MetaAdsMetricQueries;
use App\Library\MetaAds\Reporting\MetaAdsMetricTotals;
use PHPUnit\Framework\TestCase;

/** NULL IS NOT ZERO - derived metrics of a summed block (contract 24 section 5). Pure. */
class MetaAdsMetricTotalsTest extends TestCase
{
    private function row(array $o = []): object
    {
        return (object) array_merge([
            'row_count' => 3, 'day_count' => 3, 'spend_micros' => '30000000', 'impressions' => '3000',
            'clicks' => '60', 'link_clicks' => '30', 'last_date' => '2026-10-03',
            'results' => '3.000000', 'result_value' => null, 'result_row_count' => 3,
        ], $o);
    }

    public function test_no_rows_is_all_null_not_zero(): void
    {
        foreach ([null, $this->row(['row_count' => 0])] as $row) {
            $t = MetaAdsMetricTotals::fromRow($row, true, true);
            $this->assertFalse($t->hasData());
            $this->assertNull($t->spendMicros);
            $this->assertNull($t->impressions);
            $this->assertNull($t->linkClicks);
            $this->assertNull($t->results);
            $this->assertNull($t->costPerResultMicros());
            $this->assertNull($t->ctr());
            $this->assertNull($t->cpcMicros());
            $this->assertNull($t->cpmMicros());
            $this->assertNull($t->resultValue());
            $this->assertTrue($t->resultTypeChosen);
        }
    }

    public function test_derived_metrics_from_sums(): void
    {
        $t = MetaAdsMetricTotals::fromRow($this->row(), true);

        $this->assertSame(30_000_000, $t->spendMicros);
        $this->assertSame('3.000000', $t->results);
        $this->assertSame('3', $t->resultsDisplay());
        $this->assertSame(10_000_000, $t->costPerResultMicros());
        $this->assertSame(0.01, $t->ctr());              // 30 link clicks / 3000 impressions
        $this->assertSame(1_000_000, $t->cpcMicros());   // 30 spend / 30 link clicks
        $this->assertSame(10_000_000, $t->cpmMicros());  // 30 spend / 3000 x 1000
    }

    public function test_no_result_type_chosen_makes_results_unavailable_even_with_spend(): void
    {
        $t = MetaAdsMetricTotals::fromRow($this->row(), false);

        $this->assertTrue($t->hasData());
        $this->assertFalse($t->resultTypeChosen);
        $this->assertSame(30_000_000, $t->spendMicros);
        $this->assertNull($t->results);
        $this->assertNull($t->costPerResultMicros());
        $this->assertNull($t->resultValue());
    }

    public function test_chosen_type_without_result_rows_is_zero_only_when_result_data_is_present(): void
    {
        $none = $this->row(['results' => null, 'result_row_count' => 0]);

        $this->assertNull(MetaAdsMetricTotals::fromRow($none, true, false)->results);
        $zero = MetaAdsMetricTotals::fromRow($none, true, true);
        $this->assertSame('0.000000', $zero->results);
        $this->assertSame('0', $zero->resultsDisplay());
        $this->assertNull($zero->costPerResultMicros());
    }

    public function test_null_link_clicks_is_not_zero(): void
    {
        $t = MetaAdsMetricTotals::fromRow($this->row(['link_clicks' => null]), true);

        $this->assertNull($t->linkClicks);
        $this->assertNull($t->ctr());
        $this->assertNull($t->cpcMicros());
        $this->assertSame(60, $t->clicks);

        $zero = MetaAdsMetricTotals::fromRow($this->row(['link_clicks' => '0']), true);
        $this->assertSame(0, $zero->linkClicks);
        $this->assertSame(0.0, $zero->ctr());
        $this->assertNull($zero->cpcMicros()); // divisor 0
    }

    public function test_zero_impressions_make_ctr_and_cpm_null(): void
    {
        $t = MetaAdsMetricTotals::fromRow($this->row(['impressions' => '0']), true);

        $this->assertNull($t->ctr());
        $this->assertNull($t->cpmMicros());
    }

    public function test_cost_per_result_rounds_half_up_and_handles_fractions(): void
    {
        $t = MetaAdsMetricTotals::fromRow($this->row(['spend_micros' => '10000000', 'results' => '3.000000']), true);
        $this->assertSame(3_333_333, $t->costPerResultMicros());

        $half = MetaAdsMetricTotals::fromRow($this->row(['spend_micros' => '5', 'results' => '2.000000']), true);
        $this->assertSame(3, $half->costPerResultMicros()); // 2.5 -> 3

        $fractional = MetaAdsMetricTotals::fromRow($this->row(['spend_micros' => '10000000', 'results' => '2.500000']), true);
        $this->assertSame(4_000_000, $fractional->costPerResultMicros());
    }

    public function test_result_value_only_when_positive(): void
    {
        $this->assertNull(MetaAdsMetricTotals::fromRow($this->row(['result_value' => null]), true)->resultValue());
        $this->assertNull(MetaAdsMetricTotals::fromRow($this->row(['result_value' => '0.000000']), true)->resultValue());
        $this->assertSame('125.500000', MetaAdsMetricTotals::fromRow($this->row(['result_value' => '125.5']), true)->resultValue());
    }

    public function test_trim(): void
    {
        $this->assertSame('3', MetaAdsMetricTotals::trim('3.000000'));
        $this->assertSame('2.5', MetaAdsMetricTotals::trim('2.500000'));
        $this->assertSame('0', MetaAdsMetricTotals::trim('0.000000'));
        $this->assertNull(MetaAdsMetricTotals::trim(null));
    }

    public function test_list_input_falls_back_to_defaults_and_never_passes_junk(): void
    {
        $this->assertSame('spend', MetaAdsListInput::sort('; drop table', ['name', 'spend']));
        $this->assertSame('spend', MetaAdsListInput::sort(['x'], ['name', 'spend']));
        $this->assertSame('name', MetaAdsListInput::sort('name', ['name', 'spend']));
        $this->assertSame('asc', MetaAdsListInput::direction(null, 'name'));
        $this->assertSame('desc', MetaAdsListInput::direction('junk', 'spend'));
        $this->assertSame('asc', MetaAdsListInput::direction('ASC', 'spend'));
        $this->assertSame(1, MetaAdsListInput::page('-4'));
        $this->assertSame(1, MetaAdsListInput::page(['2']));
        $this->assertSame(7, MetaAdsListInput::page('7'));
        $this->assertSame('paused', MetaAdsListInput::status('PAUSED'));
        $this->assertNull(MetaAdsListInput::status('enabled'));
        $this->assertNull(MetaAdsListInput::status(['paused']));
        $this->assertSame('u1', MetaAdsListInput::entity('u1', ['u1' => 'A']));
        $this->assertNull(MetaAdsListInput::entity('foreign', ['u1' => 'A']));
        $this->assertNull(MetaAdsListInput::period(['last_7']));
        $this->assertSame(['sort' => 'spend', 'ad_set' => 'abc-1'], MetaAdsListInput::returnQuery(['sort' => 'spend', 'ad_set' => 'abc-1', 'x' => 'y', 'page' => 'a b']));
    }

    public function test_sort_expressions_cover_the_whitelist_and_reject_unknown_keys(): void
    {
        foreach (MetaAdsMetricQueries::METRIC_SORTS as $key) {
            $this->assertNotNull(MetaAdsMetricQueries::metricSortExpression($key), $key);
        }

        $this->assertNull(MetaAdsMetricQueries::metricSortExpression('name; DROP'));
        $this->assertSame('NULL', MetaAdsMetricQueries::metricSortExpression('results', false));
        $this->assertSame('NULL', MetaAdsMetricQueries::metricSortExpression('cost_per_result', false));
        $this->assertSame('desc', MetaAdsMetricQueries::direction('weird'));
        $this->assertSame([1, 200, 0], MetaAdsMetricQueries::window(0, 9999));
    }
}
