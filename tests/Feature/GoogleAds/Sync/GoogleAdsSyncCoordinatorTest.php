<?php

namespace Tests\Feature\GoogleAds\Sync;

use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsMetrics;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Library\GoogleAds\Sync\Contracts\GoogleAdsSyncObserver;
use App\Library\GoogleAds\Sync\GoogleAdsFreshness;
use App\Library\GoogleAds\Sync\GoogleAdsFreshnessState;
use App\Library\GoogleAds\Sync\GoogleAdsSyncCoordinator;
use App\Library\GoogleAds\Sync\GoogleAdsSyncDispatcher;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\GoogleAds\Sync\Concerns\PreparesAdsSync;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §5 — the sync stages and run lifecycle, on
 * the fake provider only (no real HTTP).
 */
class GoogleAdsSyncCoordinatorTest extends TestCase
{
    use PreparesAdsSync;
    use RefreshDatabase;

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

    private function runSync(GoogleAdsAccount $account, ?int $actorUserId = null): GoogleAdsSyncRun
    {
        $account = $account->fresh();
        $connection = $account->connection()->first();
        $run = app(GoogleAdsSyncDispatcher::class)->createQueuedRun($account, GoogleAdsSyncTrigger::Manual);

        return $this->coordinator()->execute($account, $connection, $run, $actorUserId)->fresh();
    }

    private function rows(string $table, GoogleAdsAccount $account): int
    {
        return DB::table($table)->where('google_ads_account_id', $account->id)->count();
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    public function test_full_sync_populates_every_normalised_table(): void
    {
        [$business, , $account] = $this->syncableAccount();

        $actor = (int) \App\Models\User::query()->value('id');
        $run = $this->runSync($account, $actor);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $this->assertNull($run->failure_code);
        $this->assertGreaterThan(0, $run->rows_counted);

        $campaigns = count($this->fixture->campaigns());
        $this->assertGreaterThan(0, $campaigns);
        $this->assertSame($campaigns, $this->rows('google_ads_campaigns', $account));
        $this->assertSame(count($this->fixture->adGroups()), $this->rows('google_ads_ad_groups', $account));
        $this->assertSame(count($this->fixture->keywords()), $this->rows('google_ads_keywords', $account));
        $this->assertSame(
            count($this->fixture->dailyCampaignMetrics()),
            DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $account->id)->where('level', 'campaign')->count(),
        );
        $this->assertSame(
            count($this->fixture->dailyKeywordMetrics()),
            DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $account->id)->where('level', 'keyword')->count(),
        );
        $this->assertSame(count($this->fixture->searchTerms()), $this->rows('google_ads_search_terms', $account));
        $this->assertGreaterThan(0, DB::table('google_ads_keywords')->where('google_ads_account_id', $account->id)->where('is_negative', true)->count());

        $first = DB::table('google_ads_campaigns')->where('google_ads_account_id', $account->id)->first();
        $this->assertNotNull($first->budget_amount_micros);
        $this->assertNotNull($first->budget_external_id);

        $account = $account->fresh();
        $this->assertSame('Snap Booth Co', $account->descriptive_name);
        $this->assertNotNull($account->last_successful_sync_at);
        $this->assertNotNull($account->last_sync_started_at);
        $this->assertNull($account->last_sync_failure_code);

        $newest = collect($this->fixture->dailyCampaignMetrics())->max(fn (GoogleAdsDailyMetricData $m) => $m->date);
        $this->assertSame($newest, $account->data_through_date->toDateString());
        $this->assertSame($newest, $run->data_through_date->toDateString());
        $this->assertNotSame(self::FIXTURE_TODAY, $account->data_through_date->toDateString(), 'data_through_date is the latest returned date, not today');

        $this->assertSame(1, BusinessGoogleOperation::query()->where('business_id', $business->id)->where('operation_type', GoogleOperationType::AdsSync->value)->count());
        $operation = BusinessGoogleOperation::query()->where('operation_type', GoogleOperationType::AdsSync->value)->first();
        $this->assertSame(GoogleOperationStatus::Succeeded, $operation->status);
        $this->assertSame($operation->id, (int) $run->business_google_operation_id);
        $this->assertGreaterThanOrEqual(8, (int) $operation->provider_call_count);
        $this->assertSame($actor, (int) $operation->actor_user_id);

        foreach (['google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_keywords', 'google_ads_daily_metrics', 'google_ads_search_terms'] as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', '!=', $business->id)->count());
        }
    }

    public function test_rerunning_the_same_window_changes_nothing(): void
    {
        [, , $account] = $this->syncableAccount();

        $this->runSync($account);
        $before = $this->factChecksums($account->id);
        $this->assertNotEmpty($before);

        $this->advance(120);
        $second = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $second->state);
        $this->assertSame($before, $this->factChecksums($account->id));
    }

    public function test_paginated_reports_are_aggregated_across_every_page(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['google_ads.sync.max_calls_per_business_per_hour' => 1000, 'google_ads.sync.max_pages_per_report' => 200]);
        $this->fakeAds->paginate(40);

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(
            count($this->fixture->dailyCampaignMetrics()),
            DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $account->id)->where('level', 'campaign')->count(),
        );
        $this->assertSame(count($this->fixture->searchTerms()), $this->rows('google_ads_search_terms', $account));
        $operation = BusinessGoogleOperation::query()->where('operation_type', GoogleOperationType::AdsSync->value)->first();
        $this->assertGreaterThan(12, (int) $operation->provider_call_count, 'extra pages each cost one counted request');
    }

    public function test_absent_conversions_stay_null_and_are_never_zero(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->withDataset(PhotoBoothFixture::CUSTOMER_ID, [
            'campaigns' => $this->fixture->campaigns(),
            'dailyCampaign' => [new GoogleAdsDailyMetricData(GoogleAdsMetricLevel::Campaign, PhotoBoothFixture::CAMPAIGN_RENTAL, '2026-10-01', new GoogleAdsMetrics(100, 10, 10, 5_000_000, null, null))],
        ]);

        $this->runSync($account);

        $row = DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $account->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->conversions);
        $this->assertNull($row->conversions_value);
        $this->assertSame(5_000_000, (int) $row->cost_micros);
    }

    // ------------------------------------------------------------------
    // Truncation / removals
    // ------------------------------------------------------------------

    public function test_a_truncated_report_makes_the_run_partial_and_is_not_complete(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->limitRows('dailyCampaignMetrics', 10);

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Partial, $run->state);
        $this->assertSame('row_cap', $run->failure_code);
        $this->assertSame(
            10,
            DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $account->id)->where('level', 'campaign')->count(),
        );

        $account = $account->fresh();
        $this->assertSame('row_cap', $account->last_sync_failure_code);
        $this->assertNotNull($account->last_successful_sync_at, 'partial data is still a refresh');
        $this->assertSame(GoogleOperationStatus::Succeeded, BusinessGoogleOperation::query()->first()->status);
        $this->assertSame(GoogleAdsFreshnessState::FailedWithData, app(GoogleAdsFreshness::class)->for($account)->state, 'a capped refresh is never presented as clean');
    }

    public function test_a_truncated_listing_removes_nothing_and_an_incomplete_fetch_never_deletes_facts(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $metrics = $this->rows('google_ads_daily_metrics', $account);
        $this->assertGreaterThan(1, $metrics);

        $this->advance(120);
        $this->fakeAds->limitRows('campaigns', 1);
        $this->fakeAds->limitRows('keywords', 1);
        $this->fakeAds->limitRows('dailyCampaignMetrics', 1);
        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Partial, $run->state);
        $this->assertSame(0, DB::table('google_ads_campaigns')->where('google_ads_account_id', $account->id)->where('status', 'REMOVED')->count());
        $this->assertSame(0, DB::table('google_ads_keywords')->where('google_ads_account_id', $account->id)->where('status', 'REMOVED')->count());
        $this->assertSame($metrics, $this->rows('google_ads_daily_metrics', $account), 'daily facts are never deleted');
    }

    public function test_an_entity_missing_from_a_complete_listing_is_marked_removed_and_keeps_its_facts(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $metrics = $this->rows('google_ads_daily_metrics', $account);

        $gone = PhotoBoothFixture::CAMPAIGN_360;
        $this->fakeAds->withDataset(PhotoBoothFixture::CUSTOMER_ID, [
            'campaigns' => array_values(array_filter($this->fixture->campaigns(), fn ($c) => $c->externalCampaignId !== $gone)),
            'adGroups' => array_values(array_filter($this->fixture->adGroups(), fn ($a) => $a->externalCampaignId !== $gone)),
            'keywords' => array_values(array_filter($this->fixture->keywords(), fn ($k) => $k->externalCampaignId !== $gone)),
            'dailyCampaign' => $this->fixture->dailyCampaignMetrics(),
            'dailyKeyword' => $this->fixture->dailyKeywordMetrics(),
            'searchTerms' => $this->fixture->searchTerms(),
        ]);

        $this->advance(120);
        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $removed = DB::table('google_ads_campaigns')->where('google_ads_account_id', $account->id)->where('status', 'REMOVED')->pluck('external_campaign_id')->all();
        $this->assertSame([$gone], $removed);
        $this->assertGreaterThan(0, DB::table('google_ads_ad_groups')->where('google_ads_account_id', $account->id)->where('status', 'REMOVED')->count());
        $this->assertGreaterThan(0, DB::table('google_ads_keywords')->where('google_ads_account_id', $account->id)->where('status', 'REMOVED')->count());
        $this->assertSame($metrics, $this->rows('google_ads_daily_metrics', $account));
    }

    public function test_a_resync_never_resets_the_owners_search_term_review_state(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $id = DB::table('google_ads_search_terms')->where('google_ads_account_id', $account->id)->value('id');
        DB::table('google_ads_search_terms')->where('id', $id)->update(['review_state' => GoogleAdsSearchTermReviewState::Ignored->value]);

        $this->advance(120);
        $this->runSync($account);

        $this->assertSame('ignored', DB::table('google_ads_search_terms')->where('id', $id)->value('review_state'));
    }

    // ------------------------------------------------------------------
    // Failure behaviour
    // ------------------------------------------------------------------

    public function test_a_mid_run_provider_failure_keeps_earlier_stages_and_the_next_run_completes(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->failNext('keywords', GoogleAdsProviderException::providerUnavailable());

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->state);
        $this->assertSame('provider_unavailable', $run->failure_code);
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('google_ads_campaigns', $account));
        $this->assertSame(count($this->fixture->adGroups()), $this->rows('google_ads_ad_groups', $account));
        $this->assertSame(0, $this->rows('google_ads_keywords', $account));
        $this->assertSame(0, $this->rows('google_ads_daily_metrics', $account), 'no zero rows invented for stages that never ran');

        $account = $account->fresh();
        $this->assertNull($account->last_successful_sync_at);
        $this->assertSame('provider_unavailable', $account->last_sync_failure_code);
        $this->assertSame(GoogleOperationStatus::Failed, BusinessGoogleOperation::query()->first()->status);
        $this->assertSame(GoogleAdsFreshnessState::NeverSynced, app(GoogleAdsFreshness::class)->for($account)->state);

        $this->advance(120);
        $second = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $second->state);
        $this->assertSame(count($this->fixture->keywords()), $this->rows('google_ads_keywords', $account));
        $this->assertSame(count($this->fixture->campaigns()), $this->rows('google_ads_campaigns', $account), 'no duplicate campaigns after the retry');
        $this->assertNull($account->fresh()->last_sync_failure_code);
    }

    public function test_failure_keeps_the_last_good_data_and_freshness_says_failed_with_data(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->runSync($account);
        $good = $this->factChecksums($account->id);
        $lastGood = $account->fresh()->last_successful_sync_at;

        $this->advance(60 * 25);
        $this->fakeAds->failNext('campaigns', GoogleAdsProviderException::timeout());
        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->state);
        $this->assertSame('timeout', $run->failure_code);
        $this->assertSame($good, $this->factChecksums($account->id));

        $account = $account->fresh();
        $this->assertEquals($lastGood, $account->last_successful_sync_at);
        $this->assertSame('timeout', $account->last_sync_failure_code);

        $snapshot = app(GoogleAdsFreshness::class)->for($account);
        $this->assertSame(GoogleAdsFreshnessState::FailedWithData, $snapshot->state);
        $this->assertSame('timeout', $snapshot->lastFailureCode);
        $this->assertTrue($snapshot->shouldWarn());
    }

    public function test_a_currency_change_stops_the_run_and_writes_nothing(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->withCustomer(new GoogleAdsCustomerDetails(PhotoBoothFixture::CUSTOMER_ID, 'Renamed', 'EUR', 'Europe/Berlin', false, true, 'ENABLED'));

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->state);
        $this->assertSame('currency_changed', $run->failure_code);
        foreach (['google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_keywords', 'google_ads_daily_metrics', 'google_ads_search_terms'] as $table) {
            $this->assertSame(0, $this->rows($table, $account), $table);
        }
        $this->assertSame(1, $this->fakeAds->callCount('customerDetails'));
        $this->assertSame(0, $this->fakeAds->callCount('campaigns'));

        $account = $account->fresh();
        $this->assertSame('Old name', $account->descriptive_name, 'nothing from a mismatched customer is stored');
        $this->assertSame(PhotoBoothFixture::TIME_ZONE, $account->time_zone);
        $this->assertSame('USD', $account->currency_code);
        $this->assertSame('currency_changed', $account->last_sync_failure_code);
        $this->assertNull($account->last_successful_sync_at);
    }

    public function test_a_429_is_deferred_with_no_inline_retry(): void
    {
        [, , $account] = $this->syncableAccount();
        $this->fakeAds->failNext('campaigns', GoogleAdsProviderException::rateLimited());

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('rate_limited', $run->failure_code);
        $this->assertSame(1, $this->fakeAds->callCount('campaigns'), 'the 429 is never retried inside the run');
        $this->assertSame(0, $this->fakeAds->callCount('adGroups'));
        $this->assertSame(GoogleOperationStatus::Deferred, BusinessGoogleOperation::query()->first()->status);
        $this->assertSame('rate_limited', $account->fresh()->last_sync_failure_code);
        $this->assertNull($account->fresh()->last_successful_sync_at);
    }

    public function test_an_exhausted_call_budget_defers_the_run_the_same_way(): void
    {
        [, , $account] = $this->syncableAccount();
        config(['google_ads.sync.max_calls_per_business_per_hour' => 3]);

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Skipped, $run->state);
        $this->assertSame('rate_limited', $run->failure_code);
        $operation = BusinessGoogleOperation::query()->first();
        $this->assertSame(GoogleOperationStatus::Deferred, $operation->status);
        $this->assertSame('budget_exhausted', $operation->failure_classification);
    }

    public function test_revocation_during_the_token_exchange_revokes_the_connection_and_stops(): void
    {
        [, $connection, $account] = $this->syncableAccount();
        $this->fakeAds->failNext('exchangeRefreshToken', GoogleAdsProviderException::invalidGrant());

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->state);
        $this->assertSame('invalid_grant', $run->failure_code);
        $this->assertSame(GoogleConnectionState::Revoked, $connection->fresh()->state);
        $this->assertNull($connection->fresh()->refresh_token_encrypted);
        $this->assertSame(0, $this->fakeAds->callCount('customerDetails'));
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

        $run = GoogleAdsSyncRun::query()->latest('id')->first();
        $this->assertSame(GoogleAdsSyncRunState::Failed, $run->state);
        $this->assertSame('internal_error', $run->failure_code);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('internal_error', $account->fresh()->last_sync_failure_code);
        $this->assertSame(GoogleOperationStatus::Failed, BusinessGoogleOperation::query()->first()->status);
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

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $this->assertArrayHasKey('exchangeRefreshToken', $levels);
        $this->assertArrayHasKey('searchTerms', $levels);
        $this->assertCount(1, array_unique(array_values($levels)));
        $this->assertSame($baseline, array_values($levels)[0]);
    }

    public function test_one_businesss_sync_never_writes_another_businesss_rows(): void
    {
        [$a, , $accountA] = $this->syncableAccount('Business A');
        [$b, , $accountB] = $this->syncableAccount('Business B', PhotoBoothFixture::DIRECT_CUSTOMER_ID);
        $this->fakeAds->withAccessibleCustomers([PhotoBoothFixture::MANAGER_ID, PhotoBoothFixture::DIRECT_CUSTOMER_ID]);
        $this->fakeAds->withCustomer(new GoogleAdsCustomerDetails(PhotoBoothFixture::DIRECT_CUSTOMER_ID, 'Other Co', 'USD', 'America/New_York', false, false, 'ENABLED'));
        $this->fakeAds->withDataset(PhotoBoothFixture::DIRECT_CUSTOMER_ID, [
            'campaigns' => [$this->fixture->campaigns()[0]],
        ]);

        $this->runSync($accountA);

        $this->assertSame(0, DB::table('google_ads_campaigns')->where('business_id', $b->id)->count());
        $this->assertSame(0, DB::table('google_ads_daily_metrics')->where('google_ads_account_id', $accountB->id)->count());
        $this->assertSame('Old name', $accountB->fresh()->descriptive_name);
        $this->assertNull($accountB->fresh()->last_successful_sync_at);

        $this->runSync($accountB);

        $this->assertSame(1, DB::table('google_ads_campaigns')->where('business_id', $b->id)->count());
        $this->assertSame(count($this->fixture->campaigns()), DB::table('google_ads_campaigns')->where('business_id', $a->id)->count());
        $this->assertSame(0, DB::table('google_ads_campaigns')->whereNotIn('business_id', [$a->id, $b->id])->count());
    }

    public function test_tagged_observers_are_told_after_each_stage_and_an_observer_failure_does_not_abort(): void
    {
        [, , $account] = $this->syncableAccount();
        $observer = new class implements GoogleAdsSyncObserver {
            /** @var array<int, string> */
            public array $stages = [];

            public function afterStage(GoogleAdsAccount $account, string $stageKey): void
            {
                $this->stages[] = $stageKey;

                if ($stageKey === 'keywords') {
                    throw new RuntimeException('observer defect');
                }
            }
        };
        $this->app->instance('test.ads.observer', $observer);
        $this->app->tag(['test.ads.observer'], GoogleAdsSyncCoordinator::OBSERVER_TAG);

        $run = $this->runSync($account);

        $this->assertSame(GoogleAdsSyncRunState::Succeeded, $run->state);
        $this->assertSame(
            ['account', 'campaigns', 'ad_groups', 'keywords', 'campaign_metrics', 'keyword_metrics', 'search_terms'],
            $observer->stages,
        );
    }
}
