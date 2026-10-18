<?php

namespace Tests\Feature\GoogleAds\Reporting;

use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermClass;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermReader;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\TestCase;

/**
 * Contract §12 — search-term aggregation and the classification matrix.
 * Defaults: waste_min_spend 20,000,000 micros, waste_min_clicks 5.
 */
class GoogleAdsSearchTermReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGoogleAdsReportingData;

    private GoogleAdsAccount $account;

    private GoogleAdsPeriod $period;

    private GoogleAdsCampaign $campaign;

    private GoogleAdsAdGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinAdsClock();
        [, $business] = $this->adsTenant();
        $this->account = $this->adsAccountFor($business);
        $this->period = GoogleAdsPeriod::resolve('last_30', $this->account);
        $this->campaign = $this->seedCampaign($this->account, '1000000001', 'Alpha');
        $this->group = $this->seedAdGroup($this->campaign, '3000000001', 'General');
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function term(string $term, int $cost, int $clicks, ?string $conv, array $o = [], string $date = '2026-10-02'): void
    {
        $this->seedSearchTerm($this->campaign, $this->group, $term, $date, $cost, $clicks, $conv, $o);
    }

    /** @return array<string, \App\Library\GoogleAds\Reporting\GoogleAdsSearchTermRow> */
    private function rows(?string $class = null): array
    {
        $page = app(GoogleAdsSearchTermReader::class)->page($this->account, $this->period, $class, null, 'spend', 'desc', 1, 100);

        $keyed = [];
        foreach ($page->items as $row) {
            $keyed[$row->term] = $row;
        }

        return $keyed;
    }

    private function seedMatrix(): void
    {
        $this->term('waste by spend', 25_000_000, 3, '0');
        $this->term('waste by clicks', 5_000_000, 6, '0');
        $this->term('converting term', 40_000_000, 10, '2');
        $this->term('small term', 2_000_000, 1, '0');
        $this->term('no conversion data', 30_000_000, 9, null);
        $this->term('google excluded', 30_000_000, 9, '0', ['targeting_status' => GoogleAdsSearchTermStatus::Excluded]);
        $this->term('google added excluded', 30_000_000, 9, '0', ['targeting_status' => GoogleAdsSearchTermStatus::AddedExcluded]);
        $this->term('ignored term', 30_000_000, 9, '0', ['review_state' => GoogleAdsSearchTermReviewState::Ignored]);
        $this->term('boundary spend', 20_000_000, 1, '0');
        $this->term('boundary clicks', 1_000_000, 5, '0');
        $this->term('just under', 19_999_999, 4, '0');
    }

    public function test_classification_matrix(): void
    {
        $this->seedMatrix();
        $rows = $this->rows();

        $expected = [
            'waste by spend' => GoogleAdsSearchTermClass::PotentialWaste,
            'waste by clicks' => GoogleAdsSearchTermClass::PotentialWaste,
            'converting term' => GoogleAdsSearchTermClass::Converting,
            'small term' => GoogleAdsSearchTermClass::Unreviewed,
            'no conversion data' => GoogleAdsSearchTermClass::Unreviewed,
            'google excluded' => GoogleAdsSearchTermClass::Excluded,
            'google added excluded' => GoogleAdsSearchTermClass::Excluded,
            'ignored term' => GoogleAdsSearchTermClass::Ignored,
            'boundary spend' => GoogleAdsSearchTermClass::PotentialWaste,
            'boundary clicks' => GoogleAdsSearchTermClass::PotentialWaste,
            'just under' => GoogleAdsSearchTermClass::Unreviewed,
        ];

        foreach ($expected as $term => $class) {
            $this->assertSame($class, $rows[$term]->classification, $term);
        }

        $this->assertSame(GoogleAdsSearchTermStatus::Excluded, $rows['google excluded']->targetingStatus);
        $this->assertSame(GoogleAdsSearchTermStatus::AddedExcluded, $rows['google added excluded']->targetingStatus);
        $this->assertSame(GoogleAdsSearchTermReviewState::Ignored, $rows['ignored term']->reviewState);
        $this->assertSame(GoogleAdsSearchTermStatus::None, $rows['small term']->targetingStatus);
    }

    public function test_terms_are_aggregated_across_days_before_classifying(): void
    {
        // 15M + 10M over two days crosses the 20M waste threshold only once summed.
        $this->term('split term', 15_000_000, 2, '0', [], '2026-10-01');
        $this->term('split term', 10_000_000, 2, '0', [], '2026-10-02');
        $this->term('Split Term', 1_000_000, 1, '0', [], '2026-10-03'); // same hash (case-insensitive)

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $row = array_values($rows)[0];
        $this->assertSame(26_000_000, $row->totals->spendMicros);
        $this->assertSame(5, $row->totals->clicks);
        $this->assertSame('0.000000', $row->totals->conversions);
        $this->assertNull($row->cplMicros());
        $this->assertSame(GoogleAdsSearchTermClass::PotentialWaste, $row->classification);
        $this->assertSame(3, $row->totals->dayCount);
    }

    public function test_one_converting_day_makes_the_term_converting(): void
    {
        $this->term('mixed', 30_000_000, 5, '0', [], '2026-10-01');
        $this->term('mixed', 1_000_000, 1, '1', [], '2026-10-02');

        $row = $this->rows()['mixed'];

        $this->assertSame(GoogleAdsSearchTermClass::Converting, $row->classification);
        $this->assertSame(31_000_000, $row->cplMicros());
    }

    public function test_class_and_campaign_filters_and_sorting(): void
    {
        $this->seedMatrix();
        $other = $this->seedCampaign($this->account, '1000000002', 'Beta');
        $otherGroup = $this->seedAdGroup($other, '3000000009', 'Beta group');
        $this->seedSearchTerm($other, $otherGroup, 'beta waste', '2026-10-02', 33_000_000, 2, '0');
        $reader = app(GoogleAdsSearchTermReader::class);

        $waste = $reader->page($this->account, $this->period, 'potential_waste', null, 'spend', 'desc', 1, 100);
        $this->assertSame(['beta waste', 'waste by spend', 'boundary spend', 'waste by clicks', 'boundary clicks'], array_map(fn ($r) => $r->term, $waste->items));
        $this->assertSame(5, $waste->total);

        $inAlpha = $reader->page($this->account, $this->period, 'potential_waste', $this->campaign->uid, 'spend', 'desc', 1, 100);
        $this->assertNotContains('beta waste', array_map(fn ($r) => $r->term, $inAlpha->items));

        $this->assertSame(['converting term'], array_map(fn ($r) => $r->term, $reader->page($this->account, $this->period, 'converting')->items));
        $this->assertSame(1, $reader->page($this->account, $this->period, 'ignored')->total);
        $this->assertSame(12, $reader->page($this->account, $this->period, 'bogus', null, 'spend', 'desc', 1, 100)->total, 'unknown class = all');

        $byTerm = $reader->page($this->account, $this->period, null, null, 'term', 'asc', 1, 3);
        $this->assertSame(['beta waste', 'boundary clicks', 'boundary spend'], array_map(fn ($r) => $r->term, $byTerm->items));
        $this->assertSame(4, $byTerm->lastPage);
    }

    public function test_period_limits_the_rows(): void
    {
        $this->term('old term', 90_000_000, 20, '0', [], '2026-08-01');
        $this->term('new term', 90_000_000, 20, '0', [], '2026-10-01');

        $this->assertSame(['new term'], array_keys($this->rows()));
    }

    public function test_already_negative_marks_exact_text_case_insensitive_at_the_right_scope(): void
    {
        $this->term('diy booth', 30_000_000, 6, '0');
        $this->term('free booth', 30_000_000, 6, '0');
        $this->term('jobs booth', 30_000_000, 6, '0');
        $this->term('phrase booth', 30_000_000, 6, '0');
        $this->term('paused neg', 30_000_000, 6, '0');
        $this->term('other scope', 30_000_000, 6, '0');
        $this->term('broader than neg', 30_000_000, 6, '0');

        $other = $this->seedCampaign($this->account, '1000000002', 'Beta');
        $otherGroup = $this->seedAdGroup($other, '3000000009', 'Beta group');
        $siblingGroup = $this->seedAdGroup($this->campaign, '3000000002', 'Sibling');

        // campaign-level, different case
        $this->seedKeyword($this->campaign, null, '1000000001~1', 'DIY Booth', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign, 'match_type' => GoogleAdsMatchType::Exact]);
        // ad-group-level covering this ad group
        $this->seedKeyword($this->campaign, $this->group, '3000000001~2', 'free booth', ['is_negative' => true, 'match_type' => GoogleAdsMatchType::Exact]);
        // ad-group-level negative on a SIBLING ad group does not cover this term
        $this->seedKeyword($this->campaign, $siblingGroup, '3000000002~3', 'jobs booth', ['is_negative' => true]);
        // broad/phrase negative with the same text still counts as the same text
        $this->seedKeyword($this->campaign, null, '1000000001~4', 'phrase booth', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign, 'match_type' => GoogleAdsMatchType::Phrase]);
        // a paused negative does not exclude anything
        $this->seedKeyword($this->campaign, null, '1000000001~5', 'paused neg', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign, 'status' => \App\Enums\GoogleAds\GoogleAdsEntityStatus::Paused]);
        // a negative in ANOTHER campaign does not cover this campaign's term
        $this->seedKeyword($other, null, '1000000002~6', 'other scope', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);
        // only an exact text match counts; a different phrase containing the words does not
        $this->seedKeyword($this->campaign, null, '1000000001~7', 'broader', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);
        // a POSITIVE keyword with the same text is not an exclusion
        $this->seedKeyword($this->campaign, $this->group, '3000000001~8', 'other scope');
        unset($otherGroup);

        $rows = $this->rows();

        $this->assertTrue($rows['diy booth']->alreadyNegative);
        $this->assertTrue($rows['free booth']->alreadyNegative);
        $this->assertFalse($rows['jobs booth']->alreadyNegative);
        $this->assertTrue($rows['phrase booth']->alreadyNegative);
        $this->assertFalse($rows['paused neg']->alreadyNegative);
        $this->assertFalse($rows['other scope']->alreadyNegative);
        $this->assertFalse($rows['broader than neg']->alreadyNegative);
        // Classification itself is unchanged: the flag is separate.
        $this->assertSame(GoogleAdsSearchTermClass::PotentialWaste, $rows['diy booth']->classification);
    }

    public function test_waste_summary_counts_only_actionable_terms_and_reports_covered_ones(): void
    {
        $this->term('waste one', 25_000_000, 3, '0');
        $this->term('waste two', 5_000_000, 6, '0');
        $this->term('diy booth', 30_000_000, 6, '0');
        $this->term('converting term', 40_000_000, 10, '2');
        $this->term('google excluded', 30_000_000, 9, '0', ['targeting_status' => GoogleAdsSearchTermStatus::Excluded]);
        $this->term('ignored term', 30_000_000, 9, '0', ['review_state' => GoogleAdsSearchTermReviewState::Ignored]);
        $this->seedKeyword($this->campaign, null, '1000000001~1', 'diy booth', ['is_negative' => true, 'level' => GoogleAdsKeywordLevel::Campaign]);

        $summary = app(GoogleAdsSearchTermReader::class)->wasteSummary($this->account, $this->period);

        $this->assertTrue($summary->hasData);
        $this->assertSame(2, $summary->termCount);
        $this->assertSame(30_000_000, $summary->spendMicros);
        $this->assertSame(9, $summary->clicks);
        $this->assertSame(1, $summary->alreadyExcludedCount);
        $this->assertSame(30_000_000, $summary->alreadyExcludedSpendMicros);
        $this->assertSame('waste one', $summary->topTerms[0]->term);
    }

    public function test_waste_summary_without_any_search_term_rows_is_null_not_zero(): void
    {
        $summary = app(GoogleAdsSearchTermReader::class)->wasteSummary($this->account, $this->period);

        $this->assertFalse($summary->hasData);
        $this->assertNull($summary->termCount);
        $this->assertNull($summary->spendMicros);
        $this->assertSame([], $summary->topTerms);

        $this->term('converting term', 40_000_000, 10, '2');
        $none = app(GoogleAdsSearchTermReader::class)->wasteSummary($this->account, $this->period);
        $this->assertTrue($none->hasData);
        $this->assertSame(0, $none->termCount, 'data present, nothing wasted = a real zero');
    }

    public function test_classify_single_term(): void
    {
        $this->term('waste one', 25_000_000, 3, '0');
        $reader = app(GoogleAdsSearchTermReader::class);

        $this->assertSame(GoogleAdsSearchTermClass::PotentialWaste, $reader->classify($this->account, $this->period, ' Waste One '));
        $this->assertNull($reader->classify($this->account, $this->period, 'unknown'));
    }
}
