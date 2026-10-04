<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\Reporting\MetaAdsAdReader;
use App\Library\MetaAds\Reporting\MetaAdsAdSetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * Contract 24 section 5 - campaign / ad set / ad readers: paging, sort
 * whitelist, status filter, per-row totals, NULL vs 0, detail with children,
 * creative thumbnail allow-list. Clock pinned to 2026-10-04 12:00 UTC.
 */
class MetaAdsCampaignAdSetAdReaderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAdsReportingData;

    private MetaAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinMetaClock();
        [, $business] = $this->metaReportingTenant();
        $this->account = $this->metaReportingAccountFor($business);
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    private function period(string $key = 'last_30')
    {
        return MetaAdsPeriod::resolve($key, $this->account);
    }

    /** Three campaigns: Alpha (spend 30, 3 results), Bravo (spend 60, 2 results), Charlie (no insight rows at all). */
    private function seedThree(): array
    {
        $alpha = $this->seedMetaCampaign($this->account, 'Alpha', ['daily_budget_minor' => 1000]);
        $bravo = $this->seedMetaCampaign($this->account, 'Bravo', ['status' => 'PAUSED', 'daily_budget_minor' => null, 'lifetime_budget_minor' => 90000]);
        $charlie = $this->seedMetaCampaign($this->account, 'Charlie');
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $alpha->id, '2026-10-01', '2026-10-03', 10_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::Campaign, $bravo->id, '2026-10-01', '2026-10-03', 20_000_000, null);
        $this->seedMetaResult($this->account, MetaAdsLevel::Campaign, $bravo->id, '2026-10-02', 2);

        return [$alpha, $bravo, $charlie];
    }

    public function test_campaign_page_sorts_filters_and_pages_with_per_campaign_totals(): void
    {
        [$alpha, $bravo, $charlie] = $this->seedThree();
        $reader = app(MetaAdsCampaignReader::class);

        $page = $reader->page($this->account, $this->period());
        $this->assertSame(3, $page->total);
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], array_map(fn ($r) => $r->name, $page->items));   // spend desc, nulls last
        $this->assertSame(60_000_000, $page->items[0]->spendMicros());
        $this->assertSame('2.000000', $page->items[0]->totals->results);
        $this->assertSame(30_000_000, $page->items[0]->costPerResultMicros());
        $this->assertSame('3.000000', $page->items[1]->totals->results);
        $this->assertSame(10_000_000, $page->items[1]->costPerResultMicros());
        $this->assertSame(3, $page->items[1]->totals->dayCount);

        $asc = $reader->page($this->account, $this->period(), null, 'spend', 'asc');
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_map(fn ($r) => $r->name, $asc->items));       // nulls STILL last

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), null, 'name', 'asc')->items));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), null, 'cost_per_result', 'asc')->items));
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), null, 'status', 'asc')->items)); // ACTIVE < PAUSED

        $paused = $reader->page($this->account, $this->period(), 'paused');
        $this->assertSame(1, $paused->total);
        $this->assertSame('Bravo', $paused->items[0]->name);
        $this->assertSame(3, $reader->page($this->account, $this->period(), 'bogus')->total);   // unknown status = all

        $second = $reader->page($this->account, $this->period(), null, 'spend', 'desc', 2, 2);
        $this->assertSame(3, $second->total);
        $this->assertSame(2, $second->lastPage);
        $this->assertSame(['Charlie'], array_map(fn ($r) => $r->name, $second->items));
    }

    public function test_unknown_sort_key_falls_back_to_spend_and_never_reaches_sql(): void
    {
        $this->seedThree();

        $page = app(MetaAdsCampaignReader::class)->page($this->account, $this->period(), null, 'name; DROP TABLE users', 'sideways');

        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], array_map(fn ($r) => $r->name, $page->items));
    }

    public function test_campaign_with_no_insights_is_null_not_zero(): void
    {
        [, , $charlie] = $this->seedThree();

        $row = app(MetaAdsCampaignReader::class)->find($this->account, $charlie->uid, $this->period());

        $this->assertNotNull($row);
        $this->assertFalse($row->totals->hasData());
        $this->assertNull($row->spendMicros());
        $this->assertNull($row->totals->impressions);
        $this->assertNull($row->totals->linkClicks);
        $this->assertNull($row->totals->results);
        $this->assertNull($row->costPerResultMicros());
        $this->assertNull($row->totals->ctr());
        $this->assertSame(0, $row->totals->dayCount);
    }

    public function test_spend_with_no_result_rows_is_zero_only_when_the_account_has_result_data(): void
    {
        [$alpha, $bravo] = $this->seedThree();
        $reader = app(MetaAdsCampaignReader::class);

        // Alpha has result rows in the window => Bravo's... Bravo has its own. Make a third spender with none.
        $delta = $this->seedMetaCampaign($this->account, 'Delta');
        $this->seedMetaInsight($this->account, MetaAdsLevel::Campaign, $delta->id, '2026-10-02', 5_000_000);

        $row = $reader->find($this->account, $delta->uid, $this->period());
        $this->assertSame('0.000000', $row->totals->results);       // Meta reported results for others, none here
        $this->assertNull($row->costPerResultMicros());             // 0 results: no cost per result

        // No result type chosen: everything result-based is unavailable, spend still shows.
        $this->account->update(['result_action_type' => null]);
        $row = $reader->find($this->account->fresh(), $delta->uid, $this->period());
        $this->assertNull($row->totals->results);
        $this->assertSame(5_000_000, $row->spendMicros());

        // Chosen type exists but NO result rows anywhere: unavailable, not 0.
        $this->account->update(['result_action_type' => 'link_click']);
        $row = $reader->find($this->account->fresh(), $delta->uid, $this->period());
        $this->assertNull($row->totals->results);
        $this->assertTrue($row->totals->resultTypeChosen);
    }

    public function test_campaign_detail_has_children_totals_trend_and_budget_facts(): void
    {
        [$alpha] = $this->seedThree();
        $setOne = $this->seedMetaAdSet($alpha, 'Set one', ['daily_budget_minor' => 500, 'frequency_7d' => '2.5000', 'reach_7d' => 9000, 'frequency_window_end' => '2026-10-03']);
        $setTwo = $this->seedMetaAdSet($alpha, 'Set two');
        $ad = $this->seedMetaAd($setOne, 'Ad one');
        $other = $this->seedMetaCampaign($this->account, 'Other');
        $otherSet = $this->seedMetaAdSet($other, 'Other set');
        $this->seedMetaAd($otherSet, 'Other ad');
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $setOne->id, '2026-10-01', '2026-10-03', 8_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::Ad, $ad->id, '2026-10-02', '2026-10-03', 4_000_000, 2);
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $otherSet->id, '2026-10-01', '2026-10-03', 99_000_000, 1);

        $detail = app(MetaAdsCampaignReader::class)->detail($this->account, $alpha->uid, $this->period());

        $this->assertNotNull($detail);
        $this->assertSame('Alpha', $detail->campaign->name);
        $this->assertSame(2, $detail->adSetsTotal);
        $this->assertSame(['Set one', 'Set two'], array_map(fn ($r) => $r->name, $detail->adSets));     // spend desc, null last
        $this->assertSame(24_000_000, $detail->adSets[0]->spendMicros());
        $this->assertNull($detail->adSets[1]->spendMicros());                                           // no rows: null
        $this->assertSame('2.5000', $detail->adSets[0]->frequency7d);
        $this->assertSame(9000, $detail->adSets[0]->reach7d);
        $this->assertSame('2026-10-03', $detail->adSets[0]->frequencyWindowEnd);
        $this->assertSame('Alpha', $detail->adSets[0]->campaignName);
        $this->assertSame(1, $detail->adsTotal);
        $this->assertSame('Ad one', $detail->ads[0]->name);
        $this->assertSame(8_000_000, $detail->ads[0]->spendMicros());
        $this->assertSame('4.000000', $detail->ads[0]->totals->results);
        $this->assertSame(30_000_000, array_sum($detail->trend['series']['spend_micros']));              // Alpha's campaign-level spend only
        $this->assertSame('daily', $detail->budget['budget_type']);
        $this->assertSame(10_000_000, $detail->budget['daily_budget_micros']);
        $this->assertSame(10_000_000, $detail->budget['average_daily_spend_micros']);
        $this->assertSame(1.0, $detail->budget['budget_utilisation']);
    }

    public function test_lifetime_budget_is_never_mixed_with_the_daily_budget_or_the_monthly_target(): void
    {
        [, $bravo] = $this->seedThree();
        $this->account->update(['monthly_budget_target_micros' => 500_000_000]);

        $row = app(MetaAdsCampaignReader::class)->find($this->account->fresh(), $bravo->uid, $this->period());
        $facts = app(MetaAdsCampaignReader::class)->budgetFacts($row);

        $this->assertNull($row->dailyBudgetMinor);
        $this->assertSame(90000, $row->lifetimeBudgetMinor);
        $this->assertSame('lifetime', $facts['budget_type']);
        $this->assertNull($facts['daily_budget_micros']);
        $this->assertSame(900_000_000, $facts['lifetime_budget_micros']);
        $this->assertNull($facts['budget_utilisation']);
    }

    public function test_ad_set_reader_filters_sorts_and_resolves_by_uid(): void
    {
        [$alpha, $bravo] = $this->seedThree();
        $s1 = $this->seedMetaAdSet($alpha, 'S1', ['frequency_7d' => '4.0000']);
        $s2 = $this->seedMetaAdSet($bravo, 'S2', ['status' => 'PAUSED', 'frequency_7d' => '1.2000']);
        $s3 = $this->seedMetaAdSet($alpha, 'S3');
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $s1->id, '2026-10-01', '2026-10-02', 5_000_000, 1);
        $this->seedMetaDays($this->account, MetaAdsLevel::AdSet, $s2->id, '2026-10-01', '2026-10-02', 9_000_000, 3);
        $reader = app(MetaAdsAdSetReader::class);

        $all = $reader->page($this->account, $this->period());
        $this->assertSame(['S2', 'S1', 'S3'], array_map(fn ($r) => $r->name, $all->items));
        $this->assertSame(['S1', 'S2', 'S3'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), null, null, 'frequency', 'desc')->items));
        $this->assertSame(['S2'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), 'PAUSED')->items));
        $this->assertSame(['S1', 'S3'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), null, $alpha->uid, 'name', 'asc')->items));
        $this->assertSame(0, $reader->page($this->account, $this->period(), null, '00000000-0000-0000-0000-000000000000')->total);
        $this->assertSame(3_000_000, $reader->find($this->account, $s2->uid, $this->period())->costPerResultMicros());
        $this->assertSame('Bravo', $reader->find($this->account, $s2->uid, $this->period())->campaignName);
        $this->assertSame(['S1' => true, 'S2' => true, 'S3' => true], array_fill_keys(array_map(fn ($n) => $n, array_values($reader->options($this->account))), true));
        $this->assertNull($reader->find($this->account, 'not-a-uid', $this->period()));
    }

    public function test_ad_reader_creative_summary_and_thumbnail_allow_list(): void
    {
        [$alpha] = $this->seedThree();
        $set = $this->seedMetaAdSet($alpha, 'Set');
        $this->seedMetaAd($set, 'Allowed', ['creative_title' => 'Book now', 'creative_body' => '<b>Photo booth</b> fun', 'creative_object_type' => 'VIDEO', 'creative_thumbnail_url' => 'https://scontent.xx.fbcdn.net/v/t1/a.jpg']);
        $this->seedMetaAd($set, 'Foreign host', ['creative_thumbnail_url' => 'https://evil.example.com/a.jpg']);
        $this->seedMetaAd($set, 'Plain http', ['creative_thumbnail_url' => 'http://scontent.xx.fbcdn.net/a.jpg']);
        $this->seedMetaAd($set, 'Credentials', ['creative_thumbnail_url' => 'https://user:pw@scontent.xx.fbcdn.net/a.jpg']);
        $this->seedMetaAd($set, 'No creative');

        $rows = [];
        foreach (app(MetaAdsAdReader::class)->page($this->account, $this->period(), null, null, null, 'name', 'asc')->items as $row) {
            $rows[$row->name] = $row;
        }

        $this->assertSame('https://scontent.xx.fbcdn.net/v/t1/a.jpg', $rows['Allowed']->creative['thumbnail_url']);
        $this->assertSame('Book now', $rows['Allowed']->creative['title']);
        $this->assertSame('<b>Photo booth</b> fun', $rows['Allowed']->creative['body']);   // raw: views escape
        $this->assertSame('VIDEO', $rows['Allowed']->creative['object_type']);
        $this->assertNull($rows['Foreign host']->creative['thumbnail_url']);
        $this->assertNull($rows['Plain http']->creative['thumbnail_url']);
        $this->assertNull($rows['Credentials']->creative['thumbnail_url']);
        $this->assertNull($rows['No creative']->creative['thumbnail_url']);
        $this->assertNull($rows['No creative']->creative['title']);
        $this->assertSame('Alpha', $rows['Allowed']->campaignName);
        $this->assertSame('Set', $rows['Allowed']->adSetName);
        $this->assertSame($set->uid, $rows['Allowed']->adSetUid);
    }

    public function test_ad_reader_filters_by_campaign_and_ad_set(): void
    {
        [$alpha, $bravo] = $this->seedThree();
        $a = $this->seedMetaAdSet($alpha, 'A set');
        $b = $this->seedMetaAdSet($bravo, 'B set');
        $this->seedMetaAd($a, 'Ad A1');
        $this->seedMetaAd($a, 'Ad A2', ['status' => 'PAUSED']);
        $this->seedMetaAd($b, 'Ad B1');
        $reader = app(MetaAdsAdReader::class);

        $this->assertSame(3, $reader->page($this->account, $this->period())->total);
        $this->assertSame(2, $reader->page($this->account, $this->period(), null, $alpha->uid)->total);
        $this->assertSame(1, $reader->page($this->account, $this->period(), null, null, $b->uid)->total);
        $this->assertSame(['Ad A2'], array_map(fn ($r) => $r->name, $reader->page($this->account, $this->period(), 'paused')->items));
        $this->assertSame(0, $reader->page($this->account, $this->period(), null, $alpha->uid, $b->uid)->total);   // set of another campaign
    }

    public function test_rows_carry_no_provider_ids(): void
    {
        [$alpha] = $this->seedThree();
        $set = $this->seedMetaAdSet($alpha, 'Set');
        $ad = $this->seedMetaAd($set, 'Ad');
        $period = $this->period();

        $dump = json_encode([
            app(MetaAdsCampaignReader::class)->page($this->account, $period)->items,
            app(MetaAdsAdSetReader::class)->page($this->account, $period)->items,
            app(MetaAdsAdReader::class)->page($this->account, $period)->items,
        ]);

        foreach ([$alpha->external_campaign_id, $set->external_ad_set_id, $ad->external_ad_id, $this->account->ad_account_id] as $providerId) {
            $this->assertStringNotContainsString($providerId, $dump);
        }
    }
}
