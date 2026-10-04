<?php

namespace Tests\Feature\Seo\Rank;

use App\Library\Seo\Rank\SeoRankChart;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankEntitlement;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * The read side of rank tracking: change / best / summaries, the chart
 * geometry and the dashboard summary and row states.
 */
class SeoRankHistoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function change(?int $previous, ?int $current, string $prevStatus = 'found', string $curStatus = 'found'): array
    {
        return SeoRankHistoryReader::change(
            $this->memObs($current, $curStatus),
            $this->memObs($previous, $prevStatus),
        );
    }

    // ------------------------------- change -------------------------------

    public function test_change_up_down_same(): void
    {
        $this->assertSame(['kind' => 'up', 'amount' => 5], $this->change(12, 7));
        $this->assertSame(['kind' => 'down', 'amount' => 4], $this->change(5, 9));
        $this->assertSame(['kind' => 'same', 'amount' => 0], $this->change(8, 8));
    }

    public function test_change_entered_and_dropped(): void
    {
        $this->assertSame(['kind' => 'entered', 'amount' => null], $this->change(null, 14, 'not_found', 'found'));
        $this->assertSame(['kind' => 'dropped', 'amount' => null], $this->change(14, null, 'found', 'not_found'));
    }

    public function test_change_is_none_when_not_matched_is_involved_or_either_side_is_missing(): void
    {
        $this->assertSame('none', $this->change(null, 5, 'not_matched', 'found')['kind']);
        $this->assertSame('none', $this->change(5, null, 'found', 'not_matched')['kind']);
        $this->assertSame('none', $this->change(null, null, 'not_matched', 'not_matched')['kind']);
        $this->assertSame('none', $this->change(null, null, 'not_found', 'not_found')['kind']);
        $this->assertSame('none', SeoRankHistoryReader::change(null, $this->memObs(3))['kind']);
        $this->assertSame('none', SeoRankHistoryReader::change($this->memObs(3), null)['kind']);
        $this->assertSame('none', SeoRankHistoryReader::change(null, null)['kind']);
    }

    public function test_change_never_produces_a_number_for_a_null_position(): void
    {
        foreach ([[null, 5, 'not_found', 'found'], [5, null, 'found', 'not_found'], [null, null, 'not_found', 'not_found']] as [$p, $c, $ps, $cs]) {
            $this->assertNull($this->change($p, $c, $ps, $cs)['amount']);
        }
    }

    // -------------------------------- best --------------------------------

    public function test_best_is_the_lowest_found_position_or_null(): void
    {
        $this->assertSame(3, SeoRankHistoryReader::best([$this->memObs(9), $this->memObs(null, 'not_found'), $this->memObs(3), $this->memObs(12)]));
        $this->assertNull(SeoRankHistoryReader::best([$this->memObs(null, 'not_found'), $this->memObs(null, 'not_matched')]));
        $this->assertNull(SeoRankHistoryReader::best([]));
    }

    public function test_summaries_return_current_previous_and_best_with_organic_and_local_separate(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $t = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');

        $this->obs($target, 'organic', 12, $t);
        $this->obs($target, 'organic', 4, $t->addDays(7));
        $this->obs($target, 'organic', 7, $t->addDays(14));
        $this->obs($target, 'local', null, $t, 'not_found');
        $this->obs($target, 'local', 2, $t->addDays(7));

        $s = app(SeoRankHistoryReader::class)->summaries([$target->id])[$target->id];

        $this->assertSame(7, $s['organic']['current']->position);
        $this->assertSame(4, $s['organic']['previous']->position);
        $this->assertSame(4, $s['organic']['best']);

        $this->assertSame(2, $s['local']['current']->position);
        $this->assertFalse($s['local']['previous']->isFound());
        $this->assertSame(2, $s['local']['best']);

        $this->assertSame([], app(SeoRankHistoryReader::class)->summaries([]));
    }

    public function test_summaries_for_a_target_with_no_history_are_all_null(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        $s = app(SeoRankHistoryReader::class)->summaries([$target->id])[$target->id];

        foreach (['organic', 'local'] as $type) {
            $this->assertSame(['current' => null, 'previous' => null, 'best' => null], $s[$type]);
        }
    }

    public function test_summaries_query_count_does_not_grow_with_the_number_of_targets(): void
    {
        [$owner, $business] = $this->rankTenant();
        $reader = app(SeoRankHistoryReader::class);
        $ids = [];

        $add = function (int $n) use (&$ids, $owner, $business) {
            for ($i = 0; $i < $n; $i++) {
                $target = $this->track($owner, $business, $this->keyword($owner, $business, 'kw ' . (count($ids) + 1)));
                $this->obs($target, 'organic', 10 + $i, now()->subDays(3));
                $this->obs($target, 'organic', 5 + $i, now()->subDays(1));
                $this->obs($target, 'local', 2, now()->subDays(1));
                $ids[] = $target->id;
            }
        };

        $add(2);
        $few = count($this->capturedQueries(fn () => $reader->summaries($ids)));

        $add(8);
        $many = count($this->capturedQueries(fn () => $reader->summaries($ids)));

        $this->assertCount(10, $ids);
        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many, 'summaries() must be a constant number of queries.');
        $this->assertLessThanOrEqual(4, $many);
    }

    public function test_history_is_never_overwritten_by_new_observations(): void
    {
        [$owner, $business] = $this->rankTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $first = $this->obs($target, 'organic', 12, now()->subDays(14));
        $before = SeoRankObservation::query()->whereKey($first->id)->first()->toArray();

        $this->obs($target, 'organic', 3, now()->subDays(7));
        $this->obs($target, 'organic', null, now(), 'not_found');

        $this->assertSame(3, SeoRankObservation::query()->where('seo_rank_target_id', $target->id)->count());
        $this->assertSame($before, SeoRankObservation::query()->whereKey($first->id)->first()->toArray());
        $this->assertSame([12, 3, null], app(SeoRankHistoryReader::class)->series($target->id)->pluck('position')->all());
    }

    // -------------------------------- chart --------------------------------

    private function series(array $spec): Collection
    {
        $t = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');

        return collect($spec)->map(fn ($row) => $this->memObs($row[1], $row[1] === null ? 'not_found' : 'found', 'organic', $t->addDays($row[0])))->values();
    }

    public function test_chart_with_no_data_has_no_data(): void
    {
        $chart = SeoRankChart::build(collect());

        $this->assertFalse($chart['has_data']);
        $this->assertSame([], $chart['points']);
        $this->assertSame([], $chart['segments']);
        $this->assertSame([], $chart['gaps']);
    }

    public function test_chart_plots_rank_1_above_rank_10(): void
    {
        $chart = SeoRankChart::build($this->series([[0, 10], [7, 1]]));

        $this->assertTrue($chart['has_data']);
        $byPosition = collect($chart['points'])->keyBy('position');
        $this->assertLessThan($byPosition[10]['y'], $byPosition[1]['y'], 'Smaller y is higher on screen: rank 1 must be on top.');
        $this->assertCount(1, $chart['segments']);
    }

    public function test_chart_not_found_breaks_the_line_and_marks_a_gap(): void
    {
        $chart = SeoRankChart::build($this->series([[0, 5], [1, 6], [2, null], [3, 3], [4, 4]]));

        $this->assertCount(2, $chart['segments']);
        $this->assertCount(1, $chart['gaps']);
        $this->assertCount(4, $chart['points']);
        $this->assertSame($chart['baseline'], $chart['gaps'][0]['y']);
        $this->assertStringContainsString('not found', $chart['gaps'][0]['label']);
    }

    public function test_chart_lone_points_between_gaps_have_no_line_and_missing_checks_are_not_interpolated(): void
    {
        $chart = SeoRankChart::build($this->series([[0, 5], [1, null], [2, 8], [3, null], [4, 9]]));

        $this->assertSame([], $chart['segments'], 'A single found check cannot be a line.');
        $this->assertCount(3, $chart['points']);
        $this->assertCount(2, $chart['gaps']);

        // Unevenly spaced checks: only the real observations are plotted, no synthetic points.
        $sparse = SeoRankChart::build($this->series([[0, 5], [1, 6], [30, 4]]));
        $this->assertCount(3, $sparse['points']);
        $this->assertCount(1, $sparse['segments']);
        $xs = array_column($sparse['points'], 'x');
        $this->assertEqualsWithDelta(($xs[1] - $xs[0]) / ($xs[2] - $xs[0]), 1 / 30, 0.01, 'x follows real time, not the check index.');
        $this->assertSame(2, substr_count($sparse['segments'][0], 'L'));
    }

    public function test_chart_with_only_not_found_checks_has_gaps_and_no_points(): void
    {
        $chart = SeoRankChart::build($this->series([[0, null], [3, null]]));

        $this->assertTrue($chart['has_data']);
        $this->assertSame([], $chart['points']);
        $this->assertCount(2, $chart['gaps']);
    }

    // ------------------------------ dashboard ------------------------------

    /** @return array{0: SeoRankDashboardReader, 1: \Closure} */
    private function dashboard(Business $business, Collection $keywords, ?CarbonImmutable $now = null): array
    {
        $plan = app(SeoRankEntitlement::class)->planFor($business->fresh());

        return app(SeoRankDashboardReader::class)->build($business->fresh(), $keywords, $plan, $now);
    }

    private function rowFor(array $built, SeoKeyword $keyword): array
    {
        foreach ($built['rows'] as $row) {
            if ($row['keyword']->id === $keyword->id) {
                return $row;
            }
        }

        $this->fail('No dashboard row for the keyword.');
    }

    public function test_dashboard_summary_is_null_not_zero_with_no_data(): void
    {
        [$owner, $business] = $this->rankTenant();

        $empty = $this->dashboard($business, collect());
        $this->assertNull($empty['summary']['average_organic']);
        $this->assertNull($empty['summary']['top10']);
        $this->assertNull($empty['summary']['improved']);
        $this->assertNull($empty['summary']['local_top3']);
        $this->assertSame(0, $empty['summary']['slots_used']);

        $k = $this->keyword($owner, $business);
        $this->track($owner, $business, $k);
        $tracked = $this->dashboard($business, collect([$k]));

        foreach (['average_organic', 'top10', 'improved', 'local_top3'] as $key) {
            $this->assertNull($tracked['summary'][$key], $key);
        }

        $this->assertSame(1, $tracked['summary']['slots_used']);
        $this->assertSame(20, $tracked['summary']['slots_limit']);
    }

    public function test_dashboard_summary_is_computed_from_real_observations(): void
    {
        [$owner, $business] = $this->rankTenant();
        $t = CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC');
        $keywords = [];

        // [organic previous, organic current, local current]
        $specs = [
            'a' => [12, 7, 2],
            'b' => [15, 15, 5],
            'c' => [10, 3, null],
            'd' => [null, null, 1],
        ];

        foreach ($specs as $phrase => [$prev, $cur, $local]) {
            $k = $this->keyword($owner, $business, $phrase . ' phrase');
            $target = $this->track($owner, $business, $k);
            $this->obs($target, 'organic', $prev, $t, $prev === null ? 'not_found' : 'found');
            $this->obs($target, 'organic', $cur, $t->addDays(7), $cur === null ? 'not_found' : 'found');
            $this->obs($target, 'local', $local, $t->addDays(7), $local === null ? 'not_found' : 'found');
            $keywords[$phrase] = $k;
        }

        $built = $this->dashboard($business, collect(array_values($keywords)));
        $s = $built['summary'];

        $this->assertSame(8.3, $s['average_organic'], 'Average over FOUND organic ranks only: (7+15+3)/3.');
        $this->assertSame(2, $s['top10'], 'Ranks 7 and 3.');
        $this->assertSame(2, $s['improved'], 'a: 12->7 and c: 10->3.');
        $this->assertSame(2, $s['local_top3'], 'Local ranks 2 (a) and 1 (d) are top 3; 5 and not-found are not.');
        $this->assertSame(4, $s['slots_used']);

        $this->assertSame('up', $this->rowFor($built, $keywords['a'])['change']['kind']);
        $this->assertSame('same', $this->rowFor($built, $keywords['b'])['change']['kind']);
        $this->assertSame('none', $this->rowFor($built, $keywords['d'])['change']['kind']);
        $this->assertSame(3, $this->rowFor($built, $keywords['c'])['best_organic']);
    }

    public function test_dashboard_row_states(): void
    {
        [$owner, $business] = $this->rankTenant();
        $manager = app(SeoRankTargetManager::class);

        $untracked = $this->keyword($owner, $business, 'untracked kw');
        $pausedK = $this->keyword($owner, $business, 'paused kw');
        $checkingK = $this->keyword($owner, $business, 'checking kw');
        $waitingK = $this->keyword($owner, $business, 'waiting kw');
        $activeK = $this->keyword($owner, $business, 'active kw');

        $paused = $this->track($owner, $business, $pausedK);
        $manager->stop((int) $owner->user_id, $business, $paused->uid);
        $checking = $this->track($owner, $business, $checkingK);
        $this->track($owner, $business, $waitingK);
        $active = $this->track($owner, $business, $activeK);
        $this->obs($active, 'organic', 6);

        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $business->id, 'seo_rank_target_id' => $checking->id, 'check_type' => 'organic',
            'trigger' => 'scheduled', 'idempotency_key' => 'open:' . $checking->id, 'state' => 'scheduled',
            'provider' => $checking->provider, 'depth' => 100,
        ])->save();

        $keywords = collect([$untracked, $pausedK, $checkingK, $waitingK, $activeK]);
        $built = $this->dashboard($business, $keywords);

        $this->assertSame(SeoRankDashboardReader::STATE_UNTRACKED, $this->rowFor($built, $untracked)['state']);
        $this->assertNull($this->rowFor($built, $untracked)['target']);
        $this->assertSame(SeoRankDashboardReader::STATE_PAUSED, $this->rowFor($built, $pausedK)['state']);
        $this->assertSame(SeoRankDashboardReader::STATE_CHECKING, $this->rowFor($built, $checkingK)['state']);
        $this->assertSame(SeoRankDashboardReader::STATE_WAITING, $this->rowFor($built, $waitingK)['state']);
        $this->assertSame(SeoRankDashboardReader::STATE_ACTIVE, $this->rowFor($built, $activeK)['state']);

        // Provider switched off: a target with no observation yet is UNAVAILABLE — not a spend pause.
        config(['seo.rank_tracking.enabled' => false]);
        $paused = $this->dashboard($business, $keywords);
        $this->assertSame(SeoRankDashboardReader::STATE_UNAVAILABLE, $this->rowFor($paused, $waitingK)['state']);
        $this->assertFalse($this->rowFor($paused, $waitingK)['budget_paused']);
        // Rows that already have data or are stopped keep their own state.
        $this->assertSame(SeoRankDashboardReader::STATE_ACTIVE, $this->rowFor($paused, $activeK)['state']);
        $this->assertSame(SeoRankDashboardReader::STATE_PAUSED, $this->rowFor($paused, $pausedK)['state']);
    }

    public function test_dashboard_does_not_include_targets_of_keywords_it_was_not_given(): void
    {
        [$owner, $business] = $this->rankTenant();
        $visible = $this->keyword($owner, $business, 'visible kw');
        $hidden = $this->keyword($owner, $business, 'hidden kw');
        $this->track($owner, $business, $visible);
        $this->track($owner, $business, $hidden);

        $built = $this->dashboard($business, collect([$visible]));

        $this->assertCount(1, $built['rows']);
        $this->assertSame($visible->id, $built['rows'][0]['keyword']->id);
        $this->assertSame(1, SeoRankTarget::query()->where('seo_keyword_id', $visible->id)->count());
    }
}
