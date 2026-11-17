<?php

namespace Tests\Unit\Seo;

use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Models\SeoRankObservation;
use PHPUnit\Framework\TestCase;

/**
 * Search keywords: best performers first. Pure function over dashboard rows, so no
 * database is involved. The rule under test:
 *   1. rows with a real numeric rank, ascending by the better of Google search
 *      (organic) and Google Maps (local); 1 is best;
 *   2. rows still checking / waiting for a first result;
 *   3. everything else with no rank (not in top N, not matched, needs domain,
 *      paused, untracked);
 * ties and groups 2 and 3 keep their incoming order.
 */
class SeoRankDashboardSortTest extends TestCase
{
    private function obs(string $status, ?int $position, string $type = 'organic'): SeoRankObservation
    {
        $o = new SeoRankObservation();
        $o->forceFill(['check_type' => $type, 'status' => $status, 'position' => $position, 'depth_checked' => 20]);

        return $o;
    }

    /** @return array<string, mixed> */
    private function row(string $name, string $state = SeoRankDashboardReader::STATE_ACTIVE, ?SeoRankObservation $organic = null, ?SeoRankObservation $local = null): array
    {
        return ['name' => $name, 'state' => $state, 'organic' => $organic, 'local' => $local];
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function names(array $rows): array
    {
        return array_map(fn (array $r) => $r['name'], SeoRankDashboardReader::sortByBestRank($rows));
    }

    public function test_numeric_ranks_sort_ascending(): void
    {
        $this->assertSame(['a3', 'b9', 'c14'], $this->names([
            $this->row('c14', organic: $this->obs('found', 14)),
            $this->row('a3', local: $this->obs('found', 3, 'local')),
            $this->row('b9', local: $this->obs('found', 9, 'local')),
        ]));
    }

    public function test_the_better_of_google_search_and_maps_is_used(): void
    {
        $rows = [
            $this->row('organic5-local2', organic: $this->obs('found', 5), local: $this->obs('found', 2, 'local')),
            $this->row('organic1-local8', organic: $this->obs('found', 1), local: $this->obs('found', 8, 'local')),
            $this->row('local4', local: $this->obs('found', 4, 'local')),
        ];

        $this->assertSame(2, SeoRankDashboardReader::bestRank($rows[0]));
        $this->assertSame(1, SeoRankDashboardReader::bestRank($rows[1]));
        $this->assertSame(['organic1-local8', 'organic5-local2', 'local4'], $this->names($rows));
    }

    public function test_a_not_found_result_does_not_count_as_a_rank_even_next_to_a_found_one(): void
    {
        $row = $this->row('mixed', organic: $this->obs('not_found', null), local: $this->obs('found', 12, 'local'));

        $this->assertSame(12, SeoRankDashboardReader::bestRank($row));
        $this->assertNull(SeoRankDashboardReader::bestRank($this->row('none', organic: $this->obs('not_found', null), local: $this->obs('not_matched', null, 'local'))));
        $this->assertNull(SeoRankDashboardReader::bestRank($this->row('blank')));
    }

    public function test_rows_without_a_numeric_rank_sit_below_ranked_rows(): void
    {
        $this->assertSame(['ranked', 'checking', 'notTop', 'untracked', 'needsDomain'], $this->names([
            $this->row('notTop', organic: $this->obs('not_found', null), local: $this->obs('not_found', null, 'local')),
            $this->row('untracked', SeoRankDashboardReader::STATE_UNTRACKED),
            $this->row('checking', SeoRankDashboardReader::STATE_CHECKING),
            $this->row('needsDomain', SeoRankDashboardReader::STATE_PAUSED),
            $this->row('ranked', organic: $this->obs('found', 50)),
        ]));
    }

    public function test_checking_and_waiting_rows_come_before_unavailable_ones_in_created_order(): void
    {
        $this->assertSame(['wait1', 'check2', 'wait3', 'unavail', 'budget'], $this->names([
            $this->row('unavail', SeoRankDashboardReader::STATE_UNAVAILABLE),
            $this->row('wait1', SeoRankDashboardReader::STATE_WAITING),
            $this->row('check2', SeoRankDashboardReader::STATE_CHECKING),
            $this->row('budget', SeoRankDashboardReader::STATE_BUDGET_PAUSED),
            $this->row('wait3', SeoRankDashboardReader::STATE_WAITING),
        ]));
    }

    public function test_ties_keep_created_order(): void
    {
        $this->assertSame(['first', 'second', 'third'], $this->names([
            $this->row('first', organic: $this->obs('found', 7)),
            $this->row('second', local: $this->obs('found', 7, 'local')),
            $this->row('third', organic: $this->obs('found', 7)),
        ]));
    }

    public function test_an_empty_list_stays_empty(): void
    {
        $this->assertSame([], SeoRankDashboardReader::sortByBestRank([]));
    }
}
