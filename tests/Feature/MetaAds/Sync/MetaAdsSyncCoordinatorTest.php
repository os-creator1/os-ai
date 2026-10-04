<?php

namespace Tests\Feature\MetaAds\Sync;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaAdsAdSetData;
use App\DTO\MetaAds\MetaApiUsage;
use App\DTO\MetaAds\MetaInsightRow;
use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\GoogleAds\GoogleAdsCallBudget;
use App\Library\MetaAds\MetaAdsCallBudget;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\MetaAds\Sync\Contracts\MetaAdsSyncObserver;
use App\Library\MetaAds\Sync\MetaAdsFreshness;
use App\Library\MetaAds\Sync\MetaAdsFreshnessState;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsSyncAbortException;
use App\Library\MetaAds\Sync\MetaAdsSyncClaim;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use App\Library\MetaAds\Sync\MetaAdsSyncCoordinator;
use App\Library\MetaAds\Sync\MetaAdsSyncDispatcher;
use App\Library\MetaAds\Sync\Stages\AccountSummaryStage;
use App\Library\MetaAds\Sync\Stages\AdSetsStage;
use App\Library\MetaAds\Sync\Stages\CampaignInsightsStage;
use App\Library\MetaAds\Sync\Stages\CampaignsStage;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\MetaAds\Sync\Concerns\PreparesMetaSync;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §6 — the sync stages and run lifecycle, on
 * the fake provider only (no real HTTP).
 */
class MetaAdsSyncCoordinatorTest extends TestCase
{
    use PreparesMetaSync;
    use RefreshDatabase;

    private const TABLES = ['meta_ads_campaigns', 'meta_ads_ad_sets', 'meta_ads_ads', 'meta_ads_daily_insights', 'meta_ads_daily_results'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runSync(MetaAdsAccount $account, ?int $actorUserId = null): MetaAdsSyncRun
    {
        $account = $account->fresh();
        $connection = $account->connection()->first();
        $run = app(MetaAdsSyncDispatcher::class)->createQueuedRun($account, MetaAdsSyncTrigger::Manual);

        return $this->coordinator()->execute($account, $connection, $run, $actorUserId)->fresh();
    }

    private function rows(string $table, MetaAdsAccount $account): int
    {
        return DB::table($table)->where('meta_ads_account_id', $account->id)->count();
    }

    private function insightRows(MetaAdsAccount $account, string $level): int
    {
        return DB::table('meta_ads_daily_insights')->where('meta_ads_account_id', $account->id)->where('level', $level)->count();
    }

    private function operation(): BusinessMetaOperation
    {
        return BusinessMetaOperation::query()->where('operation_type', MetaOperationType::MetaAdsSync->value)->latest('id')->firstOrFail();
    }

    private function assertNothingStored(MetaAdsAccount $account): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->rows($table, $account), $table);
        }
    }

    /** One campaign only, so a test controls the insights it feeds the stages. */
    private function singleCampaignDataset(array $insights): void
    {
        $this->fakeMeta->withDataset(MetaPhotoBoothFixture::AD_ACCOUNT_ID, [
            'campaigns' => [$this->fixture->campaigns()[0]],
            'insights' => ['campaign' => $insights],
        ]);
    }

    private function contextFor(MetaAdsAccount $account, string $token = 'plain-meta-access-token'): MetaAdsSyncContext
    {
        return new MetaAdsSyncContext($account->fresh(), $token, CarbonImmutable::now(), '2026-08-03', '2026-10-04', '2026-09-27', '2026-10-03');
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    public function test_full_sync_populates_every_normalised_table(): void
    {
        [$business, , $account] = $this->syncableAccount();

        $actor = (int) \App\Models\User::query()->value('id');
        $run = $this->runSync($account, $actor);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertNull($run->failure_code);
        $this->assertGreaterThan(0, $run->rows_counted);

        $this->assertSame(count($this->fixture->campaigns()), $this->rows('meta_ads_campaigns', $account));
        $this->assertSame(count($this->fixture->adSets()), $this->rows('meta_ads_ad_sets', $account));
        $this->assertSame(count($this->fixture->ads()), $this->rows('meta_ads_ads', $account));
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['campaign'], $this->insightRows($account, 'campaign'));
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['ad_set'], $this->insightRows($account, 'ad_set'));
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['ad'], $this->insightRows($account, 'ad'));

        // Money is exact micros and results are typed + allow-listed.
        $leads = DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->where('external_campaign_id', MetaPhotoBoothFixture::CAMPAIGN_LEADS)->first();
        $totals = DB::table('meta_ads_daily_insights')->where('meta_ads_account_id', $account->id)->where('level', 'campaign')->where('entity_id', $leads->id)
            ->selectRaw('SUM(spend_micros) s, SUM(impressions) i, SUM(clicks) c, SUM(link_clicks) l')->first();
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_LEADS_CAMPAIGN_SPEND_MICROS, (int) $totals->s);
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_LEADS_CAMPAIGN_IMPRESSIONS, (int) $totals->i);
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_LEADS_CAMPAIGN_CLICKS, (int) $totals->c);
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_LEADS_CAMPAIGN_LINK_CLICKS, (int) $totals->l);

        $lead = DB::table('meta_ads_daily_results')->where('meta_ads_account_id', $account->id)->where('level', 'campaign')->where('entity_id', $leads->id)->where('action_type', 'lead')
            ->selectRaw('SUM(results) r, SUM(result_value) v')->first();
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_LEADS_CAMPAIGN_LEADS, (int) $lead->r);
        $this->assertSame('5800.000000', number_format((float) $lead->v, 6, '.', ''), 'micros stored as exact major units');

        $types = DB::table('meta_ads_daily_results')->where('meta_ads_account_id', $account->id)->distinct()->pluck('action_type')->all();
        $this->assertNotEmpty($types);
        $this->assertSame([], array_values(array_diff($types, array_keys(app(MetaAdsConfig::class)->resultTypes()))), 'only allow-listed types are stored');
        $this->assertSame([], array_values(array_intersect($types, MetaPhotoBoothFixture::OUT_OF_ALLOW_LIST_TYPES)));

        // Entity facts.
        $campaign = DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->where('external_campaign_id', MetaPhotoBoothFixture::CAMPAIGN_LEADS)->first();
        $this->assertSame('ACTIVE', $campaign->status);
        $this->assertSame(1000, (int) $campaign->daily_budget_minor);
        $this->assertNull($campaign->lifetime_budget_minor);
        $awareness = DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->where('external_campaign_id', MetaPhotoBoothFixture::CAMPAIGN_AWARENESS)->first();
        $this->assertSame(50000, (int) $awareness->lifetime_budget_minor);
        $this->assertNull($awareness->daily_budget_minor);

        $fatigued = DB::table('meta_ads_ad_sets')->where('meta_ads_account_id', $account->id)->where('external_ad_set_id', MetaPhotoBoothFixture::AD_SET_FATIGUED)->first();
        $this->assertSame(4200, (int) $fatigued->reach_7d);
        $this->assertSame('3.4000', (string) $fatigued->frequency_7d);
        $this->assertSame('2026-10-03', (string) $fatigued->frequency_window_end, 'last complete day in the account time zone');
        $awarenessSet = DB::table('meta_ads_ad_sets')->where('meta_ads_account_id', $account->id)->where('external_ad_set_id', MetaPhotoBoothFixture::AD_SET_AWARENESS)->first();
        $this->assertNull($awarenessSet->reach_7d);
        $this->assertNull($awarenessSet->frequency_7d);

        $window = $this->fakeMeta->callsTo('frequency7d')[0]['args'];
        $this->assertSame('2026-09-27', $window['since']);
        $this->assertSame('2026-10-03', $window['until']);
        $insightWindow = $this->fakeMeta->callsTo('insights')[0]['args'];
        $this->assertSame('2026-08-03', $insightWindow['since'], '62 days back');
        $this->assertSame('2026-10-04', $insightWindow['until']);

        $account = $account->fresh();
        $this->assertSame('Photo Booth Co - Ads', $account->name);
        $this->assertSame(1, $account->account_status);
        $this->assertNotNull($account->last_successful_sync_at);
        $this->assertNotNull($account->last_sync_started_at);
        $this->assertNull($account->last_sync_failure_code);
        $this->assertSame('2026-10-03', $account->data_through_date->toDateString());
        $this->assertSame('2026-10-03', $run->data_through_date->toDateString());

        $this->assertSame(1, BusinessMetaOperation::query()->where('business_id', $business->id)->where('operation_type', MetaOperationType::MetaAdsSync->value)->count());
        $operation = $this->operation();
        $this->assertSame(MetaOperationStatus::Succeeded, $operation->status);
        $this->assertSame($operation->id, (int) $run->business_meta_operation_id);
        $this->assertSame(8, (int) $operation->provider_call_count, 'account + 3 lists + 3 insights + frequency, one page each');
        $this->assertSame($actor, (int) $operation->actor_user_id);

        foreach (array_merge(self::TABLES, ['meta_ads_sync_runs']) as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', '!=', $business->id)->count(), $table);
        }
    }

    public function test_rerunning_the_same_window_changes_nothing(): void
    {
        [, , $account] = $this->syncableAccount();

        $this->runSync($account);
        $before = $this->factChecksums($account->id);
        $this->assertNotEmpty($before);
        $counts = array_map(fn (string $t): int => $this->rows($t, $account), self::TABLES);

        $this->advance(120);
        $second = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $second->state);
        $this->assertSame($before, $this->factChecksums($account->id));
        $this->assertSame($counts, array_map(fn (string $t): int => $this->rows($t, $account), self::TABLES), 'no duplicate rows');
    }

    public function test_paginated_reports_are_aggregated_across_every_page(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.max_calls_per_business_per_hour' => 1000, 'meta_ads.sync.max_pages_per_report' => 200]);
        $this->fakeMeta->paginate(40);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['campaign'], $this->insightRows($account, 'campaign'));
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['ad'], $this->insightRows($account, 'ad'));
        $this->assertSame(4 + 6 + 9, $this->fakeMeta->callCount('insights'), '124 / 226 / 350 rows at 40 per page');
        $this->assertGreaterThan(8, (int) $this->operation()->provider_call_count, 'extra pages each cost one counted request');
    }

    public function test_absent_metrics_stay_null_and_are_never_zero(): void
    {
        [, , $account] = $this->syncableAccount();
        $leads = MetaPhotoBoothFixture::CAMPAIGN_LEADS;
        $this->singleCampaignDataset([
            new MetaInsightRow('campaign', $leads, '2026-10-01', 5_000_000, 100, 10, null, ['lead' => ['count' => 2, 'value' => null]]),
            new MetaInsightRow('campaign', $leads, '2026-10-02', null, 100, 10, 4, []),
            new MetaInsightRow('campaign', $leads, '2026-10-03', 0, 0, 0, 0, []),
        ]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $id = DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->value('id');
        $first = DB::table('meta_ads_daily_insights')->where('entity_id', $id)->where('metric_date', '2026-10-01')->first();
        $this->assertNull($first->link_clicks, 'no link-click figure from Meta is NULL, not 0');
        $this->assertSame(5_000_000, (int) $first->spend_micros);

        $result = DB::table('meta_ads_daily_results')->where('entity_id', $id)->where('metric_date', '2026-10-01')->get();
        $this->assertCount(1, $result);
        $this->assertSame('lead', $result[0]->action_type);
        $this->assertSame(2, (int) $result[0]->results);
        $this->assertNull($result[0]->result_value, 'no Meta-reported value is NULL, not 0');

        $this->assertSame(0, DB::table('meta_ads_daily_insights')->where('entity_id', $id)->where('metric_date', '2026-10-02')->count(), 'a row without spend is not invented as 0');
        $genuineZero = DB::table('meta_ads_daily_insights')->where('entity_id', $id)->where('metric_date', '2026-10-03')->first();
        $this->assertSame(0, (int) $genuineZero->spend_micros);
        $this->assertSame(0, (int) $genuineZero->link_clicks, 'a reported 0 stays 0');
        $this->assertSame(0, DB::table('meta_ads_daily_results')->where('entity_id', $id)->where('metric_date', '2026-10-03')->count(), 'no result row when Meta reported no result');
        $this->assertSame('2026-10-03', $account->fresh()->data_through_date->toDateString());
    }

    public function test_only_configured_result_types_are_stored(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.result_types' => ['lead' => 'Leads']]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(['lead'], DB::table('meta_ads_daily_results')->where('meta_ads_account_id', $account->id)->distinct()->pluck('action_type')->all());
        $this->assertGreaterThan(0, $this->rows('meta_ads_daily_results', $account));
    }

    public function test_a_stage_filters_result_types_again_even_if_a_client_leaked_one(): void
    {
        [, , $account] = $this->syncableAccount();
        $context = $this->contextFor($account);
        app(CampaignsStage::class)->persist($context, new MetaAdsStageReport([$this->fixture->campaigns()[0]]));

        $stage = app(CampaignInsightsStage::class);
        $result = $stage->persist($context, new MetaAdsStageReport([
            new MetaInsightRow('campaign', MetaPhotoBoothFixture::CAMPAIGN_LEADS, '2026-10-01', 1_000_000, 10, 1, 1, [
                'lead' => ['count' => 1, 'value' => 40_000_000],
                'landing_page_view' => ['count' => 9, 'value' => null],
                'definitely_not_configured' => ['count' => 3, 'value' => 5],
            ]),
        ]));

        $this->assertSame(1, $result->rows);
        $this->assertSame(['lead'], DB::table('meta_ads_daily_results')->where('meta_ads_account_id', $account->id)->pluck('action_type')->all());
        $this->assertSame('40.000000', number_format((float) DB::table('meta_ads_daily_results')->value('result_value'), 6, '.', ''));
    }

    public function test_rows_for_unknown_external_entities_are_skipped_and_counted_never_invented(): void
    {
        [, , $account] = $this->syncableAccount();
        $context = $this->contextFor($account);
        app(CampaignsStage::class)->persist($context, new MetaAdsStageReport([$this->fixture->campaigns()[0]]));

        $orphanAdSet = new MetaAdsAdSetData('6100000000009999', '6000000000009999', 'Orphan', 'ACTIVE', 'ACTIVE', null, null, null, null, null);
        $adSets = app(AdSetsStage::class)->persist($context, new MetaAdsStageReport([$orphanAdSet]));
        $this->assertSame(0, $adSets->rows);
        $this->assertSame(1, $adSets->skipped);
        $this->assertSame(0, $this->rows('meta_ads_ad_sets', $account));

        $insights = app(CampaignInsightsStage::class)->persist($context, new MetaAdsStageReport([
            new MetaInsightRow('campaign', '6000000000009999', '2026-10-01', 1_000_000, 10, 1, 1, ['lead' => ['count' => 1, 'value' => null]]),
            new MetaInsightRow('campaign', MetaPhotoBoothFixture::CAMPAIGN_LEADS, '2026-10-01', 2_000_000, 10, 1, 1, []),
            new MetaInsightRow('ad_set', MetaPhotoBoothFixture::CAMPAIGN_LEADS, '2026-10-02', 2_000_000, 10, 1, 1, []),
        ]));

        $this->assertSame(1, $insights->rows);
        $this->assertSame(2, $insights->skipped, 'unknown entity and a wrong-level row');
        $this->assertSame(1, $this->rows('meta_ads_campaigns', $account), 'no campaign was invented for the fact');
        $this->assertSame(1, $this->rows('meta_ads_daily_insights', $account));
        $this->assertSame(0, $this->rows('meta_ads_daily_results', $account));
    }

    public function test_a_full_run_skips_unknown_entities_from_the_provider(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->withDataset(MetaPhotoBoothFixture::AD_ACCOUNT_ID, [
            'campaigns' => [$this->fixture->campaigns()[0]],
            'adSets' => [new MetaAdsAdSetData('6100000000009999', '6000000000009999', 'Orphan', 'ACTIVE', 'ACTIVE', null, null, null, null, null)],
            'insights' => ['campaign' => [new MetaInsightRow('campaign', '6000000000009999', '2026-10-01', 1_000_000, 10, 1, 1, [])]],
        ]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(1, $this->rows('meta_ads_campaigns', $account));
        $this->assertSame(0, $this->rows('meta_ads_ad_sets', $account));
        $this->assertSame(0, $this->rows('meta_ads_daily_insights', $account));
        $this->assertNull($account->fresh()->data_through_date, 'nothing stored, so no data-through date');
    }

    // ------------------------------------------------------------------
    // Truncation / removals
    // ------------------------------------------------------------------

    public function test_a_row_capped_report_makes_the_run_partial_and_never_marks_it_complete(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.max_rows_per_report' => 10]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        $this->assertSame('row_cap', $run->failure_code);
        $this->assertSame(10, $this->insightRows($account, 'campaign'));

        $account = $account->fresh();
        $this->assertSame('row_cap', $account->last_sync_failure_code);
        $this->assertNull($account->last_successful_sync_at, 'a capped run is never recorded as a complete sync');
        $this->assertNull($account->data_through_date);
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation()->status);

        $snapshot = app(MetaAdsFreshness::class)->for($account);
        $this->assertSame('row_cap', $snapshot->lastFailureCode);
        $this->assertNotSame(MetaAdsFreshnessState::Fresh, $snapshot->state);
    }

    public function test_a_page_capped_report_is_partial_too(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.max_pages_per_report' => 2]);
        $this->fakeMeta->paginate(40);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        $this->assertSame('row_cap', $run->failure_code);
        $this->assertSame(80, $this->insightRows($account, 'campaign'), 'two pages of 40');
        $this->assertSame(6, $this->fakeMeta->callCount('insights'), 'two pages for each of the three levels');
    }

    public function test_a_partial_run_keeps_the_previous_complete_sync_stamp(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $stamp = $account->fresh()->last_successful_sync_at;

        $this->advance(60 * 25);
        config(['meta_ads.sync.max_rows_per_report' => 10]);
        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        $account = $account->fresh();
        $this->assertEquals($stamp, $account->last_successful_sync_at);
        $this->assertSame(MetaAdsFreshnessState::FailedWithData, app(MetaAdsFreshness::class)->for($account)->state);
    }

    public function test_a_truncated_listing_removes_nothing_and_an_incomplete_fetch_never_deletes_facts(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $insights = $this->rows('meta_ads_daily_insights', $account);
        $this->assertGreaterThan(1, $insights);

        $this->advance(120);
        config(['meta_ads.sync.max_rows_per_report' => 1]);
        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        foreach (['meta_ads_campaigns', 'meta_ads_ad_sets', 'meta_ads_ads'] as $table) {
            $this->assertSame(0, DB::table($table)->where('meta_ads_account_id', $account->id)->where('status', 'DELETED')->count(), $table);
        }
        $this->assertSame($insights, $this->rows('meta_ads_daily_insights', $account), 'daily facts are never deleted');
        $this->assertNotNull(DB::table('meta_ads_ad_sets')->where('meta_ads_account_id', $account->id)->whereNotNull('frequency_7d')->first(), 'a truncated frequency report does not null the figures');
    }

    public function test_an_entity_missing_from_a_complete_listing_is_marked_deleted_and_keeps_its_facts(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $insights = $this->rows('meta_ads_daily_insights', $account);

        $gone = MetaPhotoBoothFixture::CAMPAIGN_WEDDING;
        $this->fakeMeta->withDataset(MetaPhotoBoothFixture::AD_ACCOUNT_ID, [
            'campaigns' => array_values(array_filter($this->fixture->campaigns(), fn ($c) => $c->externalCampaignId !== $gone)),
            'adSets' => array_values(array_filter($this->fixture->adSets(), fn ($s) => $s->externalCampaignId !== $gone)),
            'ads' => array_values(array_filter($this->fixture->ads(), fn ($a) => $a->externalCampaignId !== $gone)),
            'insights' => [
                'campaign' => $this->fixture->insights('campaign'),
                'ad_set' => $this->fixture->insights('ad_set'),
                'ad' => $this->fixture->insights('ad'),
            ],
            'frequency' => $this->fixture->frequencyRows(),
        ]);

        $this->advance(120);
        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame([$gone], DB::table('meta_ads_campaigns')->where('meta_ads_account_id', $account->id)->where('status', 'DELETED')->pluck('external_campaign_id')->all());
        $this->assertGreaterThan(0, DB::table('meta_ads_ad_sets')->where('meta_ads_account_id', $account->id)->where('status', 'DELETED')->count());
        $this->assertGreaterThan(0, DB::table('meta_ads_ads')->where('meta_ads_account_id', $account->id)->where('status', 'DELETED')->count());
        $this->assertSame($insights, $this->rows('meta_ads_daily_insights', $account), 'history of a removed campaign stays');
    }

    // ------------------------------------------------------------------
    // Provider back-pressure
    // ------------------------------------------------------------------

    public function test_usage_at_the_stop_threshold_stops_cleanly_as_partial_usage_high(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->withUsage(new MetaApiUsage(callCountPct: 90.0));

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        $this->assertSame('usage_high', $run->failure_code);
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('meta_ads_campaigns', $account), 'the stage that reported the usage is persisted');
        $this->assertSame(0, $this->fakeMeta->callCount('adSets'), 'no further request once usage is high');
        $this->assertSame(0, $this->fakeMeta->callCount('insights'));

        $account = $account->fresh();
        $this->assertSame('usage_high', $account->last_sync_failure_code);
        $this->assertNull($account->last_successful_sync_at);
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation()->status, 'a clean stop is not a failure');
    }

    public function test_usage_below_the_threshold_does_not_stop_the_run(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->withUsage(new MetaApiUsage(callCountPct: 84.0, totalTimePct: 10.0));

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $this->runSync($account)->state);
    }

    public function test_usage_seen_only_on_the_final_stage_leaves_a_complete_run_complete(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method): void {
            if ($method === 'frequency7d') {
                $this->fakeMeta->withUsage(new MetaApiUsage(callCountPct: 99.0));
            }
        };

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state, 'nothing was left to skip');
        $this->assertNotNull($account->fresh()->last_successful_sync_at);
    }

    public function test_usage_high_on_a_report_that_still_had_pages_is_never_complete(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->paginate(40);
        $this->hook->before = function (string $method): void {
            if ($method === 'frequency7d') {
                $this->fakeMeta->withUsage(new MetaApiUsage(callCountPct: 99.0));
            }
        };
        $this->fakeMeta->limitRows('frequency7d', 1);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Partial, $run->state);
        $this->assertSame('usage_high', $run->failure_code);
    }

    public function test_a_429_is_deferred_with_no_inline_retry(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->failNext('campaigns', MetaProviderException::rateLimited(80004));

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('rate_limited', $run->failure_code);
        $this->assertSame(1, $this->fakeMeta->callCount('campaigns'), 'the throttle is never retried inside the run');
        $this->assertSame(0, $this->fakeMeta->callCount('adSets'));
        $this->assertSame(MetaOperationStatus::Deferred, $this->operation()->status);
        $this->assertSame('rate_limited', $account->fresh()->last_sync_failure_code);
        $this->assertNull($account->fresh()->last_successful_sync_at);
    }

    public function test_a_throttle_in_the_middle_of_pagination_loses_only_that_stage(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.max_calls_per_business_per_hour' => 1000]);
        $this->fakeMeta->paginate(40);
        $this->hook->before = function (string $method, int $ordinal): void {
            if ($method === 'insights' && $ordinal === 2) {
                throw MetaProviderException::rateLimited(17);
            }
        };

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('rate_limited', $run->failure_code);
        $this->assertSame(1, $this->fakeMeta->callCount('insights'), 'the second page never left, and nothing was retried');
        $this->assertSame(0, $this->insightRows($account, 'campaign'), 'a half-fetched report is never persisted');
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('meta_ads_campaigns', $account), 'earlier stages stay');
        $this->assertSame(MetaOperationStatus::Deferred, $this->operation()->status);
        $this->assertNull($account->fresh()->last_successful_sync_at);

        // The next run repeats the whole window and converges.
        $this->hook->before = null;
        $this->advance(120);
        $this->assertSame(MetaAdsSyncRunState::Succeeded, $this->runSync($account)->state);
        $this->assertSame(MetaPhotoBoothFixture::EXPECTED_ROW_COUNTS['campaign'], $this->insightRows($account, 'campaign'));
    }

    public function test_an_exhausted_call_budget_defers_the_run_the_same_way(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['meta_ads.sync.max_calls_per_business_per_hour' => 3]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('rate_limited', $run->failure_code);
        $operation = $this->operation();
        $this->assertSame(MetaOperationStatus::Deferred, $operation->status);
        $this->assertSame('budget_exhausted', $operation->failure_classification);
        $this->assertSame(3, (int) $operation->provider_call_count);
    }

    // ------------------------------------------------------------------
    // Failure behaviour
    // ------------------------------------------------------------------

    public function test_a_mid_run_provider_failure_keeps_earlier_stages_and_the_next_run_completes(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->failNext('ads', MetaProviderException::providerUnavailable(2));

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('provider_unavailable', $run->failure_code);
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('meta_ads_campaigns', $account));
        $this->assertSame(count($this->fixture->adSets()), $this->rows('meta_ads_ad_sets', $account));
        $this->assertSame(0, $this->rows('meta_ads_ads', $account));
        $this->assertSame(0, $this->rows('meta_ads_daily_insights', $account), 'no zero rows invented for stages that never ran');

        $account = $account->fresh();
        $this->assertNull($account->last_successful_sync_at);
        $this->assertSame('provider_unavailable', $account->last_sync_failure_code);
        $this->assertSame(MetaOperationStatus::Failed, $this->operation()->status);
        $this->assertSame(MetaAdsFreshnessState::NeverSynced, app(MetaAdsFreshness::class)->for($account)->state);

        $this->advance(120);
        $second = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $second->state);
        $this->assertSame(count($this->fixture->ads()), $this->rows('meta_ads_ads', $account));
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('meta_ads_campaigns', $account), 'no duplicate campaigns after the retry');
        $this->assertNull($account->fresh()->last_sync_failure_code);
    }

    public function test_failure_keeps_the_last_good_data_and_freshness_says_failed_with_data(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $good = $this->factChecksums($account->id);
        $lastGood = $account->fresh()->last_successful_sync_at;

        $this->advance(60 * 25);
        $this->fakeMeta->failNext('insights', MetaProviderException::timeout());
        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('timeout', $run->failure_code);
        $this->assertSame($good, $this->factChecksums($account->id), 'no zeros, nothing erased');

        $account = $account->fresh();
        $this->assertEquals($lastGood, $account->last_successful_sync_at);
        $this->assertSame('timeout', $account->last_sync_failure_code);

        $snapshot = app(MetaAdsFreshness::class)->for($account);
        $this->assertSame(MetaAdsFreshnessState::FailedWithData, $snapshot->state);
        $this->assertSame('timeout', $snapshot->lastFailureCode);
        $this->assertTrue($snapshot->shouldWarn());
    }

    public function test_error_190_expires_the_connection_and_stops_the_run(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        $this->fakeMeta->failNext('campaigns', MetaProviderException::invalidToken(467));

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('token_expired', $run->failure_code);
        $connection = $connection->fresh();
        $this->assertSame(MetaConnectionState::Expired, $connection->state);
        $this->assertNull($connection->access_token_encrypted, 'the dead token is wiped');
        $this->assertSame(0, $this->fakeMeta->callCount('adSets'));
        $this->assertSame(MetaOperationStatus::Failed, $this->operation()->status);
        $this->assertNotNull($account->fresh()->selected_at, 'history and selection are kept');
    }

    public function test_other_190_subcodes_are_invalid_token_and_458_revokes(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        $this->fakeMeta->failNext('accountDetails', MetaProviderException::invalidToken(460));

        $run = $this->runSync($account);

        $this->assertSame('invalid_token', $run->failure_code);
        $this->assertSame(MetaConnectionState::Expired, $connection->fresh()->state);

        [, $second, $secondAccount] = $this->syncableAccount('Revoked Co');
        $this->fakeMeta->failNext('accountDetails', MetaProviderException::invalidToken(458));

        $run = $this->runSync($secondAccount);

        $this->assertSame('invalid_token', $run->failure_code);
        $this->assertSame(MetaConnectionState::Revoked, $second->fresh()->state);
        $this->assertNull($second->fresh()->access_token_encrypted);
    }

    public function test_a_token_past_its_expiry_makes_no_provider_call_and_expires_the_connection(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->subMinute()]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('token_expired', $run->failure_code);
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame(MetaConnectionState::Expired, $connection->fresh()->state);
    }

    public function test_a_lost_claim_stops_the_run_before_any_provider_call(): void
    {
        [, , $account] = $this->syncableAccount();
        $account = $account->fresh();
        $run = app(MetaAdsSyncDispatcher::class)->createQueuedRun($account, MetaAdsSyncTrigger::Manual);

        // Someone else owns the claim now (ours was never or no longer stored).
        DB::table('meta_ads_accounts')->where('id', $account->id)->update(['sync_claimed_at' => '2026-10-04 12:05:00']);
        $result = $this->coordinator()->execute($account, $account->connection()->first(), $run, null, new MetaAdsSyncClaim((int) $account->id, '2026-10-04 11:00:00'))->fresh();

        $this->assertSame(MetaAdsSyncRunState::Failed, $result->state);
        $this->assertSame('claim_lost', $result->failure_code);
        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertSame('2026-10-04 12:05:00', (string) DB::table('meta_ads_accounts')->where('id', $account->id)->value('sync_claimed_at'));
        $this->assertNull($account->fresh()->last_successful_sync_at);
    }

    public function test_a_programming_error_is_rethrown_after_the_run_is_finalised(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method): void {
            if ($method === 'campaigns') {
                throw new RuntimeException('boom');
            }
        };

        try {
            $this->runSync($account);
            $this->fail('A programming error must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $run = MetaAdsSyncRun::query()->latest('id')->first();
        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('internal_error', $run->failure_code);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('internal_error', $account->fresh()->last_sync_failure_code);
        $this->assertSame(MetaOperationStatus::Failed, $this->operation()->status);
    }

    // ------------------------------------------------------------------
    // Account / connection identity
    // ------------------------------------------------------------------

    public function test_a_currency_change_at_meta_stops_the_run_and_writes_nothing(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeMeta->withAdAccounts([new MetaAdsAccountCandidate(MetaPhotoBoothFixture::AD_ACCOUNT_ID, 'Renamed', 'EUR', 'Europe/Berlin', 1)]);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('account_changed', $run->failure_code);
        $this->assertNothingStored($account);
        $this->assertSame(1, $this->fakeMeta->callCount('accountDetails'));
        $this->assertSame(0, $this->fakeMeta->callCount('campaigns'));

        $account = $account->fresh();
        $this->assertSame('Old name', $account->name, 'nothing from a mismatched account is stored');
        $this->assertSame(MetaPhotoBoothFixture::TIME_ZONE, $account->time_zone);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame('account_changed', $account->last_sync_failure_code);
        $this->assertNull($account->last_successful_sync_at);
    }

    public function test_the_account_stage_refuses_another_account_id_and_writes_nothing(): void
    {
        [, , $account] = $this->syncableAccount();
        $context = $this->contextFor($account);

        try {
            app(AccountSummaryStage::class)->persist($context, new MetaAdsStageReport([
                new MetaAdsAccountCandidate('9234567890123456', 'Someone else', 'USD', 'America/New_York', 1),
            ]));
            $this->fail('A different ad account must abort the run.');
        } catch (MetaAdsSyncAbortException $exception) {
            $this->assertSame('account_changed', $exception->failureCode);
        }

        $this->assertSame('Old name', $account->fresh()->name);
    }

    public function test_a_run_stops_cleanly_when_the_ad_account_was_reselected_mid_run(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->hook->before = function (string $method) use ($account): void {
            if ($method === 'campaigns') {
                DB::table('meta_ads_accounts')->where('id', $account->id)->update(['ad_account_id' => '3234567890123456']);
            }
        };

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('account_changed', $run->failure_code);
        $this->assertSame(0, $this->rows('meta_ads_campaigns', $account), 'nothing is written for the old account after the change');
        $this->assertSame(0, $this->fakeMeta->callCount('adSets'));
    }

    public function test_a_run_stops_cleanly_when_the_currency_changed_or_the_account_was_unselected_mid_run(): void
    {
        foreach ([['currency_code' => 'EUR'], ['selected_at' => null]] as $change) {
            [, , $account] = $this->syncableAccount('Biz ' . json_encode($change));
            $this->hook->before = function (string $method) use ($account, $change): void {
                if ($method === 'campaigns') {
                    DB::table('meta_ads_accounts')->where('id', $account->id)->update($change);
                }
            };

            $run = $this->runSync($account);

            $this->assertSame('account_changed', $run->failure_code, json_encode($change));
            $this->assertSame(0, $this->rows('meta_ads_campaigns', $account));
        }
    }

    public function test_a_run_stops_when_the_connection_changes_meta_user_mid_run(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        $this->hook->before = function (string $method) use ($connection): void {
            if ($method === 'campaigns') {
                // Re-authorised as a different Meta user while the run was in flight.
                DB::table('business_meta_connections')->where('id', $connection->id)->update(['meta_user_id' => '77777777777']);
            }
        };

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Failed, $run->state);
        $this->assertSame('connection_mismatch', $run->failure_code);
        $this->assertSame(0, $this->rows('meta_ads_campaigns', $account));
    }

    public function test_a_run_stops_when_the_connection_is_disconnected_mid_run(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        $this->hook->before = function (string $method) use ($connection): void {
            if ($method === 'adSets') {
                DB::table('business_meta_connections')->where('id', $connection->id)->update(['state' => MetaConnectionState::Expired->value, 'access_token_encrypted' => null]);
            }
        };

        $run = $this->runSync($account);

        $this->assertSame('connection_mismatch', $run->failure_code);
        $this->assertSame(0, $this->rows('meta_ads_ad_sets', $account));
        $this->assertSame(0, $this->fakeMeta->callCount('ads'));
    }

    // ------------------------------------------------------------------
    // Structural guarantees
    // ------------------------------------------------------------------

    public function test_no_provider_call_ever_happens_inside_a_database_transaction(): void
    {
        [, , $account] = $this->syncableAccount();
        $baseline = DB::transactionLevel();
        $levels = [];
        $this->hook->before = function (string $method) use (&$levels): void {
            $levels[$method] = DB::transactionLevel();
        };

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        foreach (['accountDetails', 'campaigns', 'adSets', 'ads', 'insights', 'frequency7d'] as $method) {
            $this->assertArrayHasKey($method, $levels);
        }
        $this->assertCount(1, array_unique(array_values($levels)));
        $this->assertSame($baseline, array_values($levels)[0]);
    }

    public function test_every_request_is_inside_the_one_ledger_operation(): void
    {
        [, , $account] = $this->syncableAccount();

        $this->runSync($account);

        $this->assertSame(1, BusinessMetaOperation::query()->where('operation_type', MetaOperationType::MetaAdsSync->value)->count());
        $this->assertSame(count($this->fakeMeta->calls), (int) $this->operation()->provider_call_count, 'every outbound request was counted on the run operation');
    }

    public function test_the_access_token_goes_only_to_the_provider_and_is_never_stored_or_dumped(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);

        $this->assertNotEmpty($this->hook->tokensSeen);
        $this->assertSame(['plain-meta-access-token'], array_values(array_unique($this->hook->tokensSeen)));

        foreach (['meta_ads_accounts', 'meta_ads_sync_runs', 'business_meta_operations', 'meta_ads_campaigns', 'meta_ads_ads'] as $table) {
            $this->assertStringNotContainsString('plain-meta-access-token', json_encode(DB::table($table)->get()), $table);
        }

        $context = $this->contextFor($account);
        $this->assertStringNotContainsString('plain-meta-access-token', print_r($context, true));
        ob_start();
        var_dump($context);
        $this->assertStringNotContainsString('plain-meta-access-token', (string) ob_get_clean());

        // A failure ends in a safe code, never in provider text or the token.
        $this->advance(120);
        $this->fakeMeta->failNext('campaigns', MetaProviderException::providerUnavailable(2));
        $run = $this->runSync($account);
        $this->assertStringNotContainsString('plain-meta-access-token', json_encode($run->toArray()) . json_encode(DB::table('business_meta_operations')->get()));
    }

    public function test_one_businesss_sync_never_writes_another_businesss_rows(): void
    {
        $otherAdAccount = '4234567890123456';
        [$a, , $accountA] = $this->syncableAccount('Business A');
        [$b, $connectionB, $accountB] = $this->syncableAccount('Business B', $otherAdAccount);
        $this->fakeMeta->withAdAccounts(array_merge($this->fixture->adAccounts(), [new MetaAdsAccountCandidate($otherAdAccount, 'Other Co', 'USD', 'America/New_York', 1)]));
        $this->fakeMeta->withDataset($otherAdAccount, ['campaigns' => [$this->fixture->campaigns()[0]]]);

        $this->runSync($accountA);

        $this->assertSame(0, DB::table('meta_ads_campaigns')->where('business_id', $b->id)->count());
        $this->assertSame(0, DB::table('meta_ads_daily_insights')->where('meta_ads_account_id', $accountB->id)->count());
        $this->assertSame('Old name', $accountB->fresh()->name);
        $this->assertNull($accountB->fresh()->last_successful_sync_at);
        $this->assertNull($accountB->fresh()->last_sync_started_at);
        $this->assertSame(0, BusinessMetaOperation::query()->where('business_id', $b->id)->where('operation_type', MetaOperationType::MetaAdsSync->value)->count());

        $this->runSync($accountB);

        $this->assertSame(1, DB::table('meta_ads_campaigns')->where('business_id', $b->id)->count());
        $this->assertSame(count($this->fixture->campaigns()), DB::table('meta_ads_campaigns')->where('business_id', $a->id)->count());
        $this->assertSame(0, DB::table('meta_ads_campaigns')->whereNotIn('business_id', [$a->id, $b->id])->count());
        $this->assertSame(0, DB::table('meta_ads_daily_insights')->where('business_id', $b->id)->where('meta_ads_account_id', $accountA->id)->count());
    }

    public function test_meta_and_google_call_budgets_are_independent(): void
    {
        [$business, , $account] = $this->syncableAccount();
        $google = app(GoogleAdsCallBudget::class);
        $meta = app(MetaAdsCallBudget::class);

        // Google is already at its ceiling for this Business: Meta is unaffected.
        $ledger = app(\App\Library\GoogleAds\GoogleAdsOperationLedger::class);
        $googleOperation = $ledger->open((int) $business->id, \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsSync, null, 'google budget fixture');
        DB::table('business_google_operations')->where('id', $googleOperation->id)->update(['provider_call_count' => 5000]);
        $this->assertSame(5000, $google->usedThisHour((int) $business->id));

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state, 'Google usage never reduces the Meta budget');
        $this->assertSame(8, $meta->usedThisHour((int) $business->id));
        $this->assertSame(5000, $google->usedThisHour((int) $business->id), 'Meta calls never reduce Google\'s budget');
        $this->assertSame(0, DB::table('business_google_operations')->where('operation_type', 'ads_sync')->where('id', '!=', $googleOperation->id)->count());
    }

    // ------------------------------------------------------------------
    // Observers
    // ------------------------------------------------------------------

    public function test_observers_receive_the_truncated_flag_of_the_stage(): void
    {
        [, , $account] = $this->syncableAccount();
        $observer = new class implements MetaAdsSyncObserver {
            /** @var array<string, bool> */
            public array $truncated = [];

            public function afterStage(MetaAdsAccount $account, string $stageKey, bool $truncated = false): void
            {
                $this->truncated[$stageKey] = $truncated;
            }
        };
        $this->app->instance('test.meta.observer2', $observer);
        $this->app->tag(['test.meta.observer2'], MetaAdsSyncCoordinator::OBSERVER_TAG);
        config(['meta_ads.sync.max_rows_per_report' => 4]);

        $this->runSync($account);

        $this->assertFalse($observer->truncated['campaigns']);
        $this->assertTrue($observer->truncated['ads'], '8 ads against a cap of 4');
        $this->assertTrue($observer->truncated['campaign_insights']);
    }

    public function test_tagged_observers_are_told_after_each_stage_and_an_observer_failure_does_not_abort(): void
    {
        [, , $account] = $this->syncableAccount();
        $observer = new class implements MetaAdsSyncObserver {
            /** @var array<int, string> */
            public array $stages = [];

            public function afterStage(MetaAdsAccount $account, string $stageKey, bool $truncated = false): void
            {
                $this->stages[] = $stageKey;

                if ($stageKey === 'ads') {
                    throw new RuntimeException('observer defect');
                }
            }
        };
        $this->app->instance('test.meta.observer', $observer);
        $this->app->tag(['test.meta.observer'], MetaAdsSyncCoordinator::OBSERVER_TAG);

        $run = $this->runSync($account);

        $this->assertSame(MetaAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(
            ['account', 'campaigns', 'ad_sets', 'ads', 'campaign_insights', 'ad_set_insights', 'ad_insights', 'frequency'],
            $observer->stages,
        );
    }

    public function test_the_observer_tag_name_is_the_documented_one(): void
    {
        $this->assertSame('meta_ads.sync_observers', MetaAdsSyncCoordinator::OBSERVER_TAG);
        $this->assertTrue(class_exists(AdsFeatureAccess::class));
    }
}
