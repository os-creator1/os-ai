<?php

namespace Tests\Feature\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\FakeGoogleAdsClient;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\GoogleAdsMoney;
use App\Library\GoogleAds\PhotoBoothFixture as P;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract D1 — the scriptable fake provider and the
 * deterministic PhotoBooth fixture it serves. These tests pin the properties
 * every later phase's tests rely on.
 */
class FakeGoogleAdsClientTest extends TestCase
{
    private const TODAY = '2026-10-04';

    private FakeGoogleAdsClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = (new FakeGoogleAdsClient(null, new GoogleAdsConfig()))->usePhotoBoothFixture(new P(self::TODAY));
    }

    private function ctx(?string $login = P::MANAGER_ID, string $customer = P::CUSTOMER_ID): GoogleAdsAccessContext
    {
        return new GoogleAdsAccessContext('secret-token-value', $customer, $login);
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (GoogleAdsProviderException $e) {
            return $e->classification;
        }

        $this->fail('expected a provider failure');
    }

    // ---------------------------------------------------------------
    // The fixture
    // ---------------------------------------------------------------

    public function test_the_fixture_has_the_documented_shape(): void
    {
        $campaigns = $this->fake->campaigns($this->ctx());
        $this->assertSame(
            ['Photo Booth Rental', 'Wedding Photo Booth', '360 Booth'],
            array_map(fn ($c) => $c->name, $campaigns->rows),
        );
        $this->assertFalse($campaigns->truncated);

        $this->assertCount(6, $this->fake->adGroups($this->ctx())->rows);

        $keywords = $this->fake->keywords($this->ctx())->rows;
        $positives = array_filter($keywords, fn ($k) => ! $k->isNegative);
        $negatives = array_filter($keywords, fn ($k) => $k->isNegative);
        $this->assertCount(18, $positives, 'about twenty keywords');
        $this->assertCount(4, $negatives);
        $this->assertSame(
            ['BROAD', 'EXACT', 'PHRASE'],
            collect($positives)->map(fn ($k) => $k->matchType->value)->unique()->sort()->values()->all(),
            'all three match types are present',
        );
        $this->assertTrue(collect($negatives)->contains(fn ($k) => $k->level === GoogleAdsKeywordLevel::Campaign));
        $this->assertTrue(collect($negatives)->contains(fn ($k) => $k->level === GoogleAdsKeywordLevel::AdGroup));

        // Criterion tails are unique per level.
        $tails = array_map(fn ($k) => $k->level->value . '|' . $k->externalCriterionId, $keywords);
        $this->assertSame(count($tails), count(array_unique($tails)));
    }

    public function test_sixty_two_days_of_daily_metrics_end_yesterday(): void
    {
        $rows = $this->fake->dailyCampaignMetrics($this->ctx(), '2000-01-01', '2100-01-01')->rows;
        $this->assertCount(62 * 3, $rows);

        $dates = collect($rows)->where('entityKey', P::CAMPAIGN_RENTAL)->pluck('date')->sort()->values();
        $this->assertCount(62, $dates->unique());
        $this->assertSame('2026-08-03', $dates->first());
        $this->assertSame('2026-10-03', $dates->last(), 'the last day with data is the day before "today"');

        $this->assertSame(250_000_000, P::MONTHLY_BUDGET_TARGET_MICROS);
        $this->assertSame('USD', P::CURRENCY);
    }

    public function test_spend_is_realistic_for_a_250_dollar_monthly_scenario(): void
    {
        $rows = $this->fake->dailyCampaignMetrics($this->ctx(), '2026-09-04', '2026-10-03')->rows;
        $thirtyDays = array_sum(array_map(fn ($r) => $r->metrics->costMicros, $rows));

        $this->assertGreaterThan(200_000_000, $thirtyDays);
        $this->assertLessThan(300_000_000, $thirtyDays);

        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row->metrics->clicks);
            $this->assertGreaterThanOrEqual($row->metrics->clicks, $row->metrics->impressions);
        }
    }

    public function test_keyword_metrics_sum_exactly_to_their_campaign_for_every_day(): void
    {
        $campaignRows = $this->fake->dailyCampaignMetrics($this->ctx(), '2000-01-01', '2100-01-01')->rows;
        $keywordRows = $this->fake->dailyKeywordMetrics($this->ctx(), '2000-01-01', '2100-01-01')->rows;
        $this->assertCount(18 * 62, $keywordRows);

        $campaignOfAdGroup = [];
        foreach ($this->fake->adGroups($this->ctx())->rows as $adGroup) {
            $campaignOfAdGroup[$adGroup->externalAdGroupId] = $adGroup->externalCampaignId;
        }

        $sums = [];
        foreach ($keywordRows as $row) {
            $campaign = $campaignOfAdGroup[explode('~', $row->entityKey)[0]];
            $key = $campaign . '|' . $row->date;
            $sums[$key] ??= ['impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => '0'];
            $sums[$key]['impressions'] += $row->metrics->impressions;
            $sums[$key]['clicks'] += $row->metrics->clicks;
            $sums[$key]['cost'] += $row->metrics->costMicros;
            $sums[$key]['conversions'] = bcadd($sums[$key]['conversions'], (string) $row->metrics->conversions, 6);
        }

        foreach ($campaignRows as $row) {
            $sum = $sums[$row->entityKey . '|' . $row->date];
            $this->assertSame($row->metrics->impressions, $sum['impressions']);
            $this->assertSame($row->metrics->clicks, $sum['clicks']);
            $this->assertSame($row->metrics->costMicros, $sum['cost']);
            $this->assertSame(0, bccomp((string) $row->metrics->conversions, $sum['conversions'], 6));
        }
    }

    public function test_two_campaigns_convert_and_360_booth_spends_without_converting(): void
    {
        $rows = collect($this->fake->dailyCampaignMetrics($this->ctx(), '2000-01-01', '2100-01-01')->rows);

        $conversions = fn (string $campaign) => $rows->where('entityKey', $campaign)
            ->sum(fn ($r) => (float) $r->metrics->conversions);

        $this->assertGreaterThanOrEqual(8, $conversions(P::CAMPAIGN_RENTAL));
        $this->assertGreaterThanOrEqual(6, $conversions(P::CAMPAIGN_WEDDING));
        $this->assertSame(0.0, $conversions(P::CAMPAIGN_360));

        $spend360 = $rows->where('entityKey', P::CAMPAIGN_360)->sum(fn ($r) => $r->metrics->costMicros);
        $this->assertGreaterThan(50_000_000, $spend360, 'enough spend to be a zero-conversion campaign');

        // The Rental campaign's CPL beats the $25 target; the data is realistic, not degenerate.
        $rentalCost = $rows->where('entityKey', P::CAMPAIGN_RENTAL)->sum(fn ($r) => $r->metrics->costMicros);
        $cpl = GoogleAdsMoney::cpl($rentalCost, $conversions(P::CAMPAIGN_RENTAL));
        $this->assertNotNull($cpl);
        $this->assertLessThan(P::TARGET_CPL_MICROS, $cpl);
    }

    public function test_the_obvious_waste_search_term_is_29_dollars_11_clicks_and_zero_conversions(): void
    {
        $rows = collect($this->fake->searchTerms($this->ctx(), '2026-09-04', '2026-10-03')->rows)
            ->where('searchTerm', P::WASTE_TERM);

        $this->assertSame('360 photo booth machine for sale', P::WASTE_TERM);
        $this->assertSame(29_000_000, $rows->sum(fn ($r) => $r->metrics->costMicros));
        $this->assertSame(11, $rows->sum(fn ($r) => $r->metrics->clicks));
        $this->assertSame(0.0, $rows->sum(fn ($r) => (float) $r->metrics->conversions));
        $this->assertSame(P::CAMPAIGN_360, $rows->first()->externalCampaignId);
        $this->assertNotNull($rows->first()->metrics->conversions, 'zero conversions is a real zero, not absent');
    }

    public function test_converting_search_terms_exist_and_every_row_is_inside_the_thirty_day_window(): void
    {
        $all = collect($this->fake->searchTerms($this->ctx(), '2000-01-01', '2100-01-01')->rows);

        foreach ($all as $row) {
            $this->assertGreaterThanOrEqual('2026-09-04', $row->date);
            $this->assertLessThanOrEqual('2026-10-03', $row->date);
        }

        $converting = $all->groupBy('searchTerm')->filter(fn ($rows) => $rows->sum(fn ($r) => (float) $r->metrics->conversions) > 0);
        $this->assertGreaterThanOrEqual(3, $converting->count());
        $this->assertTrue($converting->has('wedding photo booth rental'));
        $this->assertGreaterThanOrEqual(8, $all->pluck('searchTerm')->unique()->count());
    }

    public function test_the_fixture_is_deterministic_and_dates_follow_the_as_of_argument(): void
    {
        $a = new P(self::TODAY);
        $b = new P(self::TODAY);

        $this->assertSame(serialize($a->dailyCampaignMetrics()), serialize($b->dailyCampaignMetrics()));
        $this->assertSame(serialize($a->dailyKeywordMetrics()), serialize($b->dailyKeywordMetrics()));
        $this->assertSame(serialize($a->searchTerms()), serialize($b->searchTerms()));
        $this->assertSame(serialize($a->keywords()), serialize($b->keywords()));

        $later = new P('2026-10-11');
        $this->assertSame(['2026-08-10', '2026-10-10'], $later->metricsWindow());
        // The same day index carries the same numbers a week later.
        $this->assertEquals(
            $a->dailyCampaignMetrics()[5]->metrics,
            $later->dailyCampaignMetrics()[5]->metrics,
        );
    }

    // ---------------------------------------------------------------
    // The provider rule callers most often get wrong
    // ---------------------------------------------------------------

    public function test_a_manager_reached_customer_requires_the_manager_as_login_customer_id(): void
    {
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->campaigns($this->ctx(null))));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->campaigns($this->ctx(P::DIRECT_CUSTOMER_ID))));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->customerDetails($this->ctx(null))));

        $this->assertCount(3, $this->fake->campaigns($this->ctx(P::MANAGER_ID))->rows);
    }

    public function test_a_directly_accessible_customer_needs_no_login_and_an_unknown_one_is_denied(): void
    {
        $this->assertSame([], $this->fake->campaigns($this->ctx(null, P::DIRECT_CUSTOMER_ID))->rows);
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->campaigns($this->ctx(null, '2223334444'))));
    }

    public function test_manager_clients_can_only_be_listed_for_a_manager(): void
    {
        $this->assertCount(6, $this->fake->managerClients($this->ctx(P::MANAGER_ID, P::MANAGER_ID))->rows);
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->managerClients($this->ctx(null, P::DIRECT_CUSTOMER_ID))));
    }

    public function test_customer_details_come_from_customers_or_manager_rows(): void
    {
        $this->assertSame('Snap Booth Co', $this->fake->customerDetails($this->ctx())->name);
        $this->assertSame('CAD', $this->fake->customerDetails($this->ctx(P::MANAGER_ID, P::SECOND_MANAGED_CUSTOMER_ID))->currencyCode);
    }

    // ---------------------------------------------------------------
    // Date windows
    // ---------------------------------------------------------------

    public function test_reports_honour_the_requested_window_and_validate_dates(): void
    {
        $week = $this->fake->dailyCampaignMetrics($this->ctx(), '2026-09-27', '2026-10-03')->rows;
        $this->assertCount(7 * 3, $week);

        $this->assertSame('validation', $this->code(fn () => $this->fake->dailyCampaignMetrics($this->ctx(), '2026-10-03', '2026-09-27')));
        $this->assertSame('validation', $this->code(fn () => $this->fake->searchTerms($this->ctx(), "x'", '2026-09-27')));
    }

    // ---------------------------------------------------------------
    // Failure injection, paging and truncation
    // ---------------------------------------------------------------

    public function test_failures_are_scripted_per_method_and_consumed_in_order(): void
    {
        $this->fake->failNext('campaigns', GoogleAdsProviderException::rateLimited(), 2);

        $this->assertSame('rate_limited', $this->code(fn () => $this->fake->campaigns($this->ctx())));
        $this->assertSame('rate_limited', $this->code(fn () => $this->fake->campaigns($this->ctx())));
        $this->assertCount(3, $this->fake->campaigns($this->ctx())->rows);

        // Other methods are unaffected by a script for 'campaigns'.
        $this->fake->failNext('campaigns', GoogleAdsProviderException::providerUnavailable());
        $this->assertCount(6, $this->fake->adGroups($this->ctx())->rows);
    }

    public function test_row_cap_truncates_and_says_so(): void
    {
        $this->fake->limitRows('keywords', 5);

        $result = $this->fake->keywords($this->ctx());

        $this->assertCount(5, $result->rows);
        $this->assertTrue($result->truncated);
        $this->assertFalse($this->fake->campaigns($this->ctx())->truncated, 'only the scripted method is capped');
    }

    public function test_pagination_counts_pages_and_the_page_cap_truncates(): void
    {
        $this->fake->paginate(100);
        $result = $this->fake->dailyCampaignMetrics($this->ctx(), '2000-01-01', '2100-01-01'); // 186 rows
        $this->assertSame(2, $result->pagesFetched);
        $this->assertFalse($result->truncated);
        $this->assertCount(186, $result->rows);

        config(['google_ads.sync.max_pages_per_report' => 1]);
        $capped = $this->fake->dailyCampaignMetrics($this->ctx(), '2000-01-01', '2100-01-01');
        $this->assertTrue($capped->truncated);
        $this->assertSame(1, $capped->pagesFetched);
        $this->assertCount(100, $capped->rows);
    }

    // ---------------------------------------------------------------
    // Mutations
    // ---------------------------------------------------------------

    public function test_pause_and_resume_a_campaign_and_return_the_resource_name(): void
    {
        $result = $this->fake->setCampaignStatus($this->ctx(), P::CAMPAIGN_360, GoogleAdsEntityStatus::Paused);

        $this->assertSame('customers/1234567890/campaigns/1000000003', $result->resourceName);
        $this->assertSame(GoogleAdsEntityStatus::Paused, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_360));
        $this->assertSame(GoogleAdsEntityStatus::Paused, $this->fake->campaigns($this->ctx())->rows[2]->status, 'the read side reflects the write');

        $this->fake->setCampaignStatus($this->ctx(), P::CAMPAIGN_360, GoogleAdsEntityStatus::Enabled);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_360));
    }

    public function test_removed_and_unknown_are_never_writable_and_bad_ids_fail_locally(): void
    {
        foreach ([GoogleAdsEntityStatus::Removed, GoogleAdsEntityStatus::Unknown] as $status) {
            $this->assertSame('validation', $this->code(fn () => $this->fake->setCampaignStatus($this->ctx(), P::CAMPAIGN_360, $status)));
        }

        $this->assertSame('validation', $this->code(fn () => $this->fake->setCampaignStatus($this->ctx(), '1; DROP', GoogleAdsEntityStatus::Paused)));
        $this->assertSame('not_found', $this->code(fn () => $this->fake->setCampaignStatus($this->ctx(), '9999999999', GoogleAdsEntityStatus::Paused)));
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_360));
    }

    public function test_pause_a_keyword(): void
    {
        $keyword = collect($this->fake->keywordsOf(P::CUSTOMER_ID))->first(fn ($k) => ! $k->isNegative);
        [$adGroupId, $criterionId] = explode('~', $keyword->externalCriterionId);

        $result = $this->fake->setKeywordStatus($this->ctx(), $adGroupId, $criterionId, GoogleAdsEntityStatus::Paused);

        $this->assertSame('customers/1234567890/adGroupCriteria/' . $keyword->externalCriterionId, $result->resourceName);
        $paused = collect($this->fake->keywordsOf(P::CUSTOMER_ID))->firstWhere('externalCriterionId', $keyword->externalCriterionId);
        $this->assertSame(GoogleAdsEntityStatus::Paused, $paused->status);

        $this->assertSame('not_found', $this->code(fn () => $this->fake->setKeywordStatus($this->ctx(), $adGroupId, '1', GoogleAdsEntityStatus::Paused)));
    }

    public function test_add_a_negative_keyword_at_campaign_and_ad_group_scope(): void
    {
        $campaign = $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, P::CAMPAIGN_360, '  Machine   for sale ', GoogleAdsMatchType::Phrase);
        $this->assertStringStartsWith('customers/1234567890/campaignCriteria/' . P::CAMPAIGN_360 . '~', $campaign->resourceName);

        $adGroup = $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::AdGroup, '3000000001', 'cheap', GoogleAdsMatchType::Exact);
        $this->assertStringStartsWith('customers/1234567890/adGroupCriteria/3000000001~', $adGroup->resourceName);

        $added = collect($this->fake->keywords($this->ctx())->rows)->where('isNegative', true)->whereIn('text', ['Machine for sale', 'cheap']);
        $this->assertCount(2, $added);
        $this->assertSame(P::CAMPAIGN_RENTAL, $added->firstWhere('text', 'cheap')->externalCampaignId);
        $this->assertNull($added->firstWhere('text', 'Machine for sale')->externalAdGroupId);

        // A duplicate is rejected, as Google rejects a duplicate criterion.
        $this->assertSame('validation', $this->code(fn () => $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, P::CAMPAIGN_360, 'machine for sale', GoogleAdsMatchType::Phrase)));
        // ...but the same text at a different match type is a different criterion.
        $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, P::CAMPAIGN_360, 'machine for sale', GoogleAdsMatchType::Exact);
    }

    public function test_invalid_negative_keywords_fail_locally_and_unknown_parents_are_not_found(): void
    {
        $before = count($this->fake->keywordsOf(P::CUSTOMER_ID));

        foreach (['', '   ', str_repeat('x', 81), implode(' ', range(1, 11))] as $bad) {
            $this->assertSame('validation', $this->code(fn () => $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, P::CAMPAIGN_RENTAL, $bad, GoogleAdsMatchType::Exact)), $bad);
        }

        $this->assertSame('not_found', $this->code(fn () => $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, '9999999999', 'free', GoogleAdsMatchType::Exact)));
        $this->assertSame('not_found', $this->code(fn () => $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::AdGroup, '9999999999', 'free', GoogleAdsMatchType::Exact)));
        $this->assertCount($before, $this->fake->keywordsOf(P::CUSTOMER_ID));
    }

    public function test_an_ambiguous_mutate_timeout_can_be_scripted_both_ways(): void
    {
        // Timed out and NOT applied.
        $this->fake->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(afterMutateSent: true));
        try {
            $this->fake->setCampaignStatus($this->ctx(), P::CAMPAIGN_RENTAL, GoogleAdsEntityStatus::Paused);
            $this->fail('expected an ambiguous timeout');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isAmbiguous());
        }
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_RENTAL));

        // Timed out but ACTUALLY applied: the case reconciliation must survive.
        $this->fake->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(afterMutateSent: true), 1, true);
        try {
            $this->fake->setCampaignStatus($this->ctx(), P::CAMPAIGN_RENTAL, GoogleAdsEntityStatus::Paused);
            $this->fail('expected an ambiguous timeout');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isAmbiguous());
        }
        $this->assertSame(GoogleAdsEntityStatus::Paused, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_RENTAL));
    }

    public function test_a_definite_mutation_rejection_does_not_apply(): void
    {
        $this->fake->failNext('addNegativeKeyword', GoogleAdsProviderException::validation());
        $before = count($this->fake->keywordsOf(P::CUSTOMER_ID));

        $this->assertSame('validation', $this->code(fn () => $this->fake->addNegativeKeyword($this->ctx(), GoogleAdsKeywordLevel::Campaign, P::CAMPAIGN_RENTAL, 'free stuff', GoogleAdsMatchType::Phrase)));
        $this->assertCount($before, $this->fake->keywordsOf(P::CUSTOMER_ID));
    }

    public function test_mutations_also_obey_the_login_customer_rule(): void
    {
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->setCampaignStatus($this->ctx(null), P::CAMPAIGN_RENTAL, GoogleAdsEntityStatus::Paused)));
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $this->fake->campaignStatus(P::CUSTOMER_ID, P::CAMPAIGN_RENTAL));
    }

    // ---------------------------------------------------------------
    // Call log and auth
    // ---------------------------------------------------------------

    public function test_every_call_is_recorded_without_any_token(): void
    {
        $this->fake->campaigns($this->ctx());
        $this->fake->dailyCampaignMetrics($this->ctx(), '2026-09-27', '2026-10-03');
        $this->fake->listAccessibleCustomers('another-secret-token');

        $this->assertSame(3, $this->fake->callCount());
        $this->assertSame(1, $this->fake->callCount('campaigns'));
        $this->assertSame(
            ['customer_id' => P::CUSTOMER_ID, 'login_customer_id' => P::MANAGER_ID],
            array_intersect_key($this->fake->callsTo('campaigns')[0], ['customer_id' => 1, 'login_customer_id' => 1]),
        );
        $this->assertSame(['start_date' => '2026-09-27', 'end_date' => '2026-10-03'], $this->fake->callsTo('dailyCampaignMetrics')[0]['args']);

        $log = json_encode($this->fake->calls);
        $this->assertStringNotContainsString('secret-token-value', $log);
        $this->assertStringNotContainsString('another-secret-token', $log);
    }

    public function test_the_auth_half_mirrors_the_real_client(): void
    {
        $url = $this->fake->authorizationUrl('signed.state', true);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('false', $query['include_granted_scopes']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('signed.state', $query['state']);
        $this->assertStringContainsString('/auth/adwords', $query['scope']);

        $grant = $this->fake->exchangeAuthorizationCode('code');
        $this->assertSame('fake-refresh-token', $grant->refreshToken);
        $this->assertSame('https://www.googleapis.com/auth/adwords', $grant->grantedScopes);

        $this->fake->grantNoRefreshToken();
        $this->assertNull($this->fake->exchangeAuthorizationCode('code')->refreshToken);

        $first = $this->fake->exchangeRefreshToken('r')->accessToken;
        $second = $this->fake->exchangeRefreshToken('r')->accessToken;
        $this->assertNotSame($first, $second);

        $this->fake->failNext('exchangeRefreshToken', GoogleAdsProviderException::invalidGrant());
        $this->assertSame('invalid_grant', $this->code(fn () => $this->fake->exchangeRefreshToken('r')));
    }

    public function test_the_fake_is_empty_until_scripted(): void
    {
        $empty = new FakeGoogleAdsClient();

        $this->assertSame([], $empty->listAccessibleCustomers('t'));
        $this->assertSame('access_denied', $this->code(fn () => $empty->campaigns($this->ctx())));
    }
}
