<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Library\Crm\CrmBoard;
use App\Library\Crm\CrmBoardFilters;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\Business;
use App\Models\CrmPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * CrmBoard's own contract: the board read costs the same number of queries
 * whatever the pipeline holds — no query per opportunity, per contact or per
 * column. Measured on the read model itself (not a whole request), for a tiny
 * Business and a crowded one, under each kind of filter.
 */
class CrmBoardQueryBudgetTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_the_board_read_costs_the_same_queries_for_two_deals_as_for_two_hundred(): void
    {
        [, $small] = $this->crmTenant('Small Studio', 'Small');
        [, $crowded] = $this->crmTenant('Crowded Studio', 'Crowded');

        $smallPipeline = $this->populate($small, contacts: 1, perStage: [1, 1, 0, 0], closedInArchivedStage: 1);
        $crowdedPipeline = $this->populate($crowded, contacts: 60, perStage: [70, 50, 40, 40], closedInArchivedStage: 12);

        $filters = [
            'open (default)' => new CrmBoardFilters(),
            'all statuses, with an archived column' => new CrmBoardFilters(status: CrmBoardFilters::STATUS_ALL),
            'search by contact name' => new CrmBoardFilters(search: 'Person', status: CrmBoardFilters::STATUS_ALL),
            'contact status' => new CrmBoardFilters(contactStatus: CrmContactStatus::InContact->value),
        ];

        foreach ($filters as $label => $filter) {
            [$smallQueries, $smallBoard] = $this->measure($small, $smallPipeline, $filter);
            [$crowdedQueries, $crowdedBoard] = $this->measure($crowded, $crowdedPipeline, $filter);

            $this->assertSame($smallQueries, $crowdedQueries, "{$label}: the query count must not grow with rows ({$smallQueries} vs {$crowdedQueries}).");
            $this->assertLessThanOrEqual(5, $crowdedQueries, "{$label}: stages, totals, cards, contacts, contact names.");
            $this->assertGreaterThan($smallBoard['total'], $crowdedBoard['total'], "{$label}: the crowded fixture really is bigger.");
        }

        // The crowded board is genuinely crowded: capped columns, many contacts on cards.
        [, $board] = $this->measure($crowded, $crowdedPipeline, new CrmBoardFilters());
        $newInquiry = $board['columns'][0];
        $this->assertSame(70, $newInquiry['count']);
        $this->assertCount(CrmBoard::CARDS_PER_STAGE, $newInquiry['cards']);
        $cards = array_merge(...array_column($board['columns'], 'cards'));
        $this->assertGreaterThan(150, count($cards));
        $this->assertGreaterThanOrEqual(50, count(array_unique(array_map(fn ($card) => $card['contact']['uid'], $cards))));
        $this->assertNotNull($cards[0]['contact']['name']);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function measure(Business $business, CrmPipeline $pipeline, CrmBoardFilters $filters): array
    {
        $board = app(CrmBoard::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $board->board($business, $pipeline, $filters);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return [$count, $result];
    }

    /**
     * @param  list<int>  $perStage open deals per active stage, in order
     */
    private function populate(Business $business, int $contacts, array $perStage, int $closedInArchivedStage): CrmPipeline
    {
        $pipeline = $this->standardPipeline($business);
        $pipelines = app(CrmPipelineService::class);
        $deals = app(CrmOpportunityService::class);

        $people = [];
        for ($i = 1; $i <= $contacts; $i++) {
            $people[] = $this->crmContact($business, ['FIRST_NAME' => 'Person', 'LAST_NAME' => 'No' . $i]);
        }

        // A stage whose closed deals only show under the "archived" column.
        $retired = $pipelines->addStage($pipeline, 'Retired stage');
        for ($i = 0; $i < $closedInArchivedStage; $i++) {
            $deal = $deals->create($business, $pipeline, $people[$i % count($people)], 'Closed ' . $i, 10000, $retired);
            $i % 2 === 0 ? $deals->markWon($deal) : $deals->markLost($deal, 'Budget');
        }
        $pipelines->archiveStage($retired);

        $stages = $pipeline->activeStages()->get()->values();
        $n = 0;
        foreach ($perStage as $index => $count) {
            for ($i = 0; $i < $count; $i++, $n++) {
                $deal = $deals->create($business, $pipeline, $people[$n % count($people)], 'Deal ' . $n, 1000 * ($n + 1), $stages[$index]);

                if ($n % 3 === 0) {
                    $deals->setContactStatus($deal, CrmContactStatus::InContact);
                }
            }
        }

        return $pipeline;
    }
}
