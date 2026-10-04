<?php

namespace Tests\Feature\MetaAds;

use App\DTO\MetaAds\MetaApiUsage;
use App\DTO\MetaAds\MetaInsightRow;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAdsCallCounter;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\Contracts\MetaMutationClient;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Library\MetaAds\FakeMetaClient;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaPhotoBoothFixture as P;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract §14 — the scriptable fake provider and the
 * deterministic Photo Booth fixture it serves. These tests pin the properties
 * every later lane's tests rely on. No database is used.
 */
class FakeMetaClientTest extends TestCase
{
    private const TOKEN = 'secret-token-value';

    private const AS_OF = '2026-10-04';

    private FakeMetaClient $fake;

    private object $counter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counter = new class implements MetaAdsCallCounter
        {
            public int $reserved = 0;

            public function reserve(): void
            {
                $this->reserved++;
            }
        };

        $this->fake = $this->newFake();
    }

    private function newFake(): FakeMetaClient
    {
        return (new FakeMetaClient($this->counter, new MetaAdsConfig()))
            ->usePhotoBoothFixture(null, CarbonImmutable::parse(self::AS_OF, P::TIME_ZONE));
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (MetaProviderException $e) {
            return $e->classification;
        }

        return 'none';
    }

    /**
     * @param  'campaign'|'ad_set'|'ad'  $level
     * @return array<int, MetaInsightRow>
     */
    private function allInsights(string $level, ?FakeMetaClient $fake = null): array
    {
        $fake ??= $this->fake;
        $rows = [];
        $cursor = null;

        do {
            $page = $fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, $level, '2026-01-01', '2026-12-31', $cursor);
            array_push($rows, ...$page->rows);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $rows;
    }

    public function test_it_implements_all_three_provider_contracts(): void
    {
        $this->assertInstanceOf(MetaAuthClient::class, $this->fake);
        $this->assertInstanceOf(MetaReadClient::class, $this->fake);
        $this->assertInstanceOf(MetaMutationClient::class, $this->fake);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_the_oauth_half_is_scripted(): void
    {
        $this->assertStringContainsString('dialog/oauth', $this->fake->authorizationUrl('state-1'));
        $this->assertStringContainsString('state-1', $this->fake->authorizationUrl('state-1'));

        $grant = $this->fake->exchangeCode('c');
        $this->assertStringStartsWith('fake-long-lived-token-', $grant->accessToken);
        $this->assertGreaterThan(now()->addDays(59)->timestamp, $grant->expiresAt->timestamp);
        $this->assertNotSame($grant->accessToken, $this->fake->exchangeCode('c')->accessToken);

        $profile = $this->fake->profile(self::TOKEN);
        $this->assertSame(P::META_USER_ID, $profile->id);

        $this->assertSame(['public_profile', 'ads_read', 'ads_management'], $this->fake->grantedPermissions(self::TOKEN));
    }

    public function test_permissions_can_be_scripted(): void
    {
        $this->assertSame(['public_profile'], $this->fake->grantNoAdsRead()->grantedPermissions(self::TOKEN));
        $this->assertSame(['ads_read'], $this->fake->grantScopes(['ads_read'])->grantedPermissions(self::TOKEN));
    }

    public function test_the_code_exchange_reserves_two_requests_like_the_real_client(): void
    {
        $before = $this->counter->reserved;
        $this->fake->exchangeCode('c');

        $this->assertSame(2, $this->counter->reserved - $before);
        $this->assertSame(1, $this->fake->callCount('exchangeCode'));
    }

    public function test_token_exchange_failures_can_be_scripted(): void
    {
        $this->fake->failNext('exchangeCode', MetaProviderException::validation(100));

        $this->assertSame('validation', $this->code(fn () => $this->fake->exchangeCode('c')));
        $this->assertSame('none', $this->code(fn () => $this->fake->exchangeCode('c')), 'a scripted failure is consumed');
    }

    // ------------------------------------------------------------------
    // Account discovery
    // ------------------------------------------------------------------

    public function test_ad_accounts_include_active_disabled_and_foreign_currency_but_never_the_foreign_id(): void
    {
        $accounts = $this->fake->listAdAccounts(self::TOKEN);
        $byId = [];

        foreach ($accounts as $account) {
            $byId[$account->adAccountId] = $account;
        }

        $this->assertCount(3, $accounts);
        $this->assertTrue($byId[P::AD_ACCOUNT_ID]->isSelectable());
        $this->assertSame('USD', $byId[P::AD_ACCOUNT_ID]->currencyCode);
        $this->assertFalse($byId[P::DISABLED_AD_ACCOUNT_ID]->isSelectable());
        $this->assertSame(2, $byId[P::DISABLED_AD_ACCOUNT_ID]->accountStatus);
        $this->assertTrue($byId[P::EUR_AD_ACCOUNT_ID]->isSelectable());
        $this->assertSame('EUR', $byId[P::EUR_AD_ACCOUNT_ID]->currencyCode);
        $this->assertArrayNotHasKey(P::FOREIGN_AD_ACCOUNT_ID, $byId);
    }

    public function test_a_foreign_ad_account_is_access_denied_everywhere(): void
    {
        $f = P::FOREIGN_AD_ACCOUNT_ID;

        $this->assertSame('access_denied', $this->code(fn () => $this->fake->accountDetails(self::TOKEN, $f)));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->campaigns(self::TOKEN, $f)));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->adSets(self::TOKEN, $f)));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->ads(self::TOKEN, $f)));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->insights(self::TOKEN, $f, 'campaign', '2026-09-01', '2026-09-02')));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->frequency7d(self::TOKEN, $f, '2026-09-01', '2026-09-02')));
        $this->assertSame('access_denied', $this->code(fn () => $this->fake->setStatus(self::TOKEN, $f, '6000000000000001', 'campaign', 'PAUSED')));
    }

    public function test_account_details_accepts_an_act_prefix(): void
    {
        $this->assertSame(P::AD_ACCOUNT_ID, $this->fake->accountDetails(self::TOKEN, 'act_' . P::AD_ACCOUNT_ID)->adAccountId);
    }

    // ------------------------------------------------------------------
    // Entities
    // ------------------------------------------------------------------

    public function test_the_fixture_shape(): void
    {
        $campaigns = $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)->rows;
        $adSets = $this->fake->adSets(self::TOKEN, P::AD_ACCOUNT_ID)->rows;
        $ads = $this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID)->rows;

        $this->assertCount(3, $campaigns);
        $this->assertCount(5, $adSets);
        $this->assertCount(8, $ads);

        $ids = array_map(fn ($c) => $c->externalCampaignId, $campaigns);
        foreach ($adSets as $adSet) {
            $this->assertContains($adSet->externalCampaignId, $ids, 'every ad set belongs to a campaign');
        }

        $adSetIds = array_map(fn ($s) => $s->externalAdSetId, $adSets);
        foreach ($ads as $ad) {
            $this->assertContains($ad->externalAdSetId, $adSetIds, 'every ad belongs to an ad set');
        }

        $this->assertSame('Photo Booth Co', P::BUSINESS_NAME);
        $this->assertSame('Ages 25–54 · Women · US', $adSets[0]->targetingSummary);
        $this->assertLessThanOrEqual(255, max(array_map(fn ($s) => mb_strlen((string) $s->targetingSummary), $adSets)));
    }

    public function test_one_ad_has_a_thumbnail_on_an_allowed_host_and_the_rest_have_none(): void
    {
        $config = new MetaAdsConfig();
        $withThumb = array_filter($this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID)->rows, fn ($a) => $a->creativeThumbnailUrl !== null);

        $this->assertCount(1, $withThumb);
        $ad = array_values($withThumb)[0];
        $this->assertSame(P::AD_WITH_THUMBNAIL, $ad->externalAdId);
        $this->assertSame($ad->creativeThumbnailUrl, $config->allowedThumbnailUrl($ad->creativeThumbnailUrl));
    }

    public function test_delivery_problems_are_present_on_active_entities(): void
    {
        $ads = [];
        foreach ($this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID)->rows as $ad) {
            $ads[$ad->externalAdId] = $ad;
        }

        $this->assertSame('ACTIVE', $ads[P::AD_WITH_ISSUES]->status);
        $this->assertSame('WITH_ISSUES', $ads[P::AD_WITH_ISSUES]->effectiveStatus);
        $this->assertSame('ACTIVE', $ads[P::AD_DISAPPROVED]->status);
        $this->assertSame('DISAPPROVED', $ads[P::AD_DISAPPROVED]->effectiveStatus);
    }

    public function test_campaign_budgets_are_minor_units_and_a_lifetime_budget_exists(): void
    {
        $byId = [];
        foreach ($this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)->rows as $c) {
            $byId[$c->externalCampaignId] = $c;
        }

        $this->assertSame(1000, $byId[P::CAMPAIGN_LEADS]->dailyBudgetMinor);
        $this->assertNull($byId[P::CAMPAIGN_LEADS]->lifetimeBudgetMinor);
        $this->assertSame(50000, $byId[P::CAMPAIGN_AWARENESS]->lifetimeBudgetMinor);
        $this->assertSame(30000, $byId[P::CAMPAIGN_AWARENESS]->budgetRemainingMinor);
        $this->assertSame('PAUSED', $byId[P::CAMPAIGN_AWARENESS]->status);
    }

    // ------------------------------------------------------------------
    // Insights
    // ------------------------------------------------------------------

    public function test_insights_are_62_days_ending_the_day_before_as_of(): void
    {
        $dates = array_unique(array_map(fn (MetaInsightRow $r) => $r->date, $this->allInsights('campaign')));
        sort($dates);

        $this->assertCount(62, $dates);
        $this->assertSame('2026-08-03', $dates[0]);
        $this->assertSame('2026-10-03', $dates[61]);
    }

    public function test_row_counts_and_pinned_totals_match_the_generator(): void
    {
        foreach (P::EXPECTED_ROW_COUNTS as $level => $count) {
            $this->assertCount($count, $this->allInsights($level), $level);
        }

        $leads = (new P())->campaignTotals(P::CAMPAIGN_LEADS);

        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_SPEND_MICROS, $leads['spend']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_IMPRESSIONS, $leads['impressions']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_CLICKS, $leads['clicks']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_LINK_CLICKS, $leads['link_clicks']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_LEADS, $leads['results']['lead']['count']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_LEAD_VALUE_MICROS, $leads['results']['lead']['value']);
        $this->assertSame(P::EXPECTED_LEADS_CAMPAIGN_LEADS * P::LEAD_VALUE_MICROS, $leads['results']['lead']['value']);

        $wedding = (new P())->campaignTotals(P::CAMPAIGN_WEDDING);
        $this->assertSame(P::EXPECTED_WEDDING_CAMPAIGN_SPEND_MICROS, $wedding['spend']);
        $this->assertSame(P::EXPECTED_WEDDING_CAMPAIGN_LINK_CLICKS, $wedding['results']['link_click']['count']);
    }

    public function test_the_data_is_deterministic_and_independent_of_the_as_of_date_except_for_labels(): void
    {
        $again = $this->allInsights('ad', $this->newFake());
        $this->assertEquals($this->allInsights('ad'), $again, 'same as-of, byte-identical data');

        $later = (new FakeMetaClient(null, new MetaAdsConfig()))->usePhotoBoothFixture(null, CarbonImmutable::parse('2027-03-01', P::TIME_ZONE));
        $rows = [];
        $cursor = null;
        do {
            $page = $later->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'campaign', '2026-01-01', '2028-01-01', $cursor);
            array_push($rows, ...$page->rows);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        $this->assertSame(
            array_sum(array_map(fn ($r) => $r->spendMicros, $this->allInsights('campaign'))),
            array_sum(array_map(fn ($r) => $r->spendMicros, $rows)),
        );
    }

    public function test_ad_set_and_campaign_rows_are_exact_sums_of_ad_rows(): void
    {
        $ads = $this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID)->rows;
        $adSetOfAd = [];
        foreach ($ads as $ad) {
            $adSetOfAd[$ad->externalAdId] = $ad->externalAdSetId;
        }

        $sumByAdSet = [];
        foreach ($this->allInsights('ad') as $row) {
            $key = $adSetOfAd[$row->externalId] . '|' . $row->date;
            $sumByAdSet[$key] = ($sumByAdSet[$key] ?? 0) + $row->spendMicros;
        }

        foreach ($this->allInsights('ad_set') as $row) {
            $this->assertSame($sumByAdSet[$row->externalId . '|' . $row->date], $row->spendMicros);
        }

        $this->assertSame(
            array_sum(array_map(fn ($r) => $r->spendMicros, $this->allInsights('ad'))),
            array_sum(array_map(fn ($r) => $r->spendMicros, $this->allInsights('campaign'))),
        );
    }

    public function test_a_campaign_has_no_insight_rows_so_its_metrics_are_missing_not_zero(): void
    {
        $ids = array_unique(array_map(fn (MetaInsightRow $r) => $r->externalId, $this->allInsights('campaign')));

        $this->assertContains(P::CAMPAIGN_LEADS, $ids);
        $this->assertContains(P::CAMPAIGN_WEDDING, $ids);
        $this->assertNotContains(P::CAMPAIGN_AWARENESS, $ids);
    }

    public function test_only_allow_listed_result_types_are_served_while_the_fixture_emits_others(): void
    {
        $fixtureTypes = [];
        foreach ((new P())->insights('campaign') as $row) {
            array_push($fixtureTypes, ...array_keys($row->results));
        }
        $fixtureTypes = array_unique($fixtureTypes);

        foreach (P::OUT_OF_ALLOW_LIST_TYPES as $outside) {
            $this->assertContains($outside, $fixtureTypes, 'the fixture really emits ' . $outside);
        }

        $served = [];
        foreach ($this->allInsights('campaign') as $row) {
            array_push($served, ...array_keys($row->results));
        }

        $served = array_unique($served);
        sort($served);

        $this->assertSame(['lead', 'link_click', 'onsite_conversion.lead_grouped'], $served);
    }

    public function test_the_allow_list_follows_config(): void
    {
        config(['meta_ads.result_types' => ['link_click' => 'Link clicks']]);

        $served = [];
        foreach ($this->allInsights('campaign') as $row) {
            array_push($served, ...array_keys($row->results));
        }

        $this->assertSame(['link_click'], array_values(array_unique($served)));
    }

    public function test_a_campaign_spends_with_zero_lead_actions(): void
    {
        $wedding = array_filter($this->allInsights('campaign'), fn (MetaInsightRow $r) => $r->externalId === P::CAMPAIGN_WEDDING);

        $this->assertNotEmpty($wedding);

        foreach ($wedding as $row) {
            $this->assertGreaterThan(0, $row->spendMicros);
            $this->assertArrayNotHasKey('lead', $row->results);
            $this->assertArrayNotHasKey('onsite_conversion.lead_grouped', $row->results);
        }
    }

    public function test_the_fatigued_ad_set_stops_producing_leads_in_the_final_seven_days_while_still_spending(): void
    {
        $recentCutoff = CarbonImmutable::parse(self::AS_OF)->subDays(7)->format('Y-m-d');
        $recentLeads = $recentSpend = $earlierLeads = 0;

        foreach ($this->allInsights('ad_set') as $row) {
            if ($row->externalId !== P::AD_SET_FATIGUED) {
                continue;
            }

            if ($row->date >= $recentCutoff) {
                $recentSpend += $row->spendMicros;
                $recentLeads += $row->results['lead']['count'] ?? 0;
            } else {
                $earlierLeads += $row->results['lead']['count'] ?? 0;
            }
        }

        $this->assertGreaterThan(0, $recentSpend);
        $this->assertSame(0, $recentLeads);
        $this->assertGreaterThanOrEqual(3, $earlierLeads);
    }

    public function test_insights_are_filtered_to_the_requested_window_and_validated(): void
    {
        $rows = $this->fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'campaign', '2026-09-01', '2026-09-03')->rows;

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual('2026-09-01', $row->date);
            $this->assertLessThanOrEqual('2026-09-03', $row->date);
        }

        $this->assertSame('validation', $this->code(fn () => $this->fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'account', '2026-09-01', '2026-09-03')));
        $this->assertSame('validation', $this->code(fn () => $this->fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'ad', '2026-09-03', '2026-09-01')));
        $this->assertSame('validation', $this->code(fn () => $this->fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'ad', 'x', '2026-09-01')));
    }

    public function test_frequency_windows_are_per_ad_set_and_skip_the_paused_set(): void
    {
        $rows = $this->fake->frequency7d(self::TOKEN, P::AD_ACCOUNT_ID, '2026-09-27', '2026-10-03')->rows;
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->externalAdSetId] = $row;
        }

        $this->assertCount(4, $rows);
        $this->assertArrayNotHasKey(P::AD_SET_AWARENESS, $byId);
        $this->assertEqualsWithDelta(3.4, $byId[P::AD_SET_FATIGUED]->frequency, 1e-9);
        $this->assertSame('2026-09-27', $byId[P::AD_SET_FATIGUED]->windowStart);
        $this->assertSame('2026-10-03', $byId[P::AD_SET_FATIGUED]->windowEnd);
    }

    // ------------------------------------------------------------------
    // Paging, caps, usage, counter
    // ------------------------------------------------------------------

    public function test_paginate_serves_pages_with_cursors_until_the_end(): void
    {
        $this->fake->paginate(3);

        $first = $this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID);
        $this->assertCount(3, $first->rows);
        $this->assertNotNull($first->nextCursor);

        $second = $this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID, $first->nextCursor);
        $third = $this->fake->ads(self::TOKEN, P::AD_ACCOUNT_ID, $second->nextCursor);

        $this->assertCount(2, $third->rows);
        $this->assertNull($third->nextCursor);
        $this->assertSame(8, count($first->rows) + count($second->rows) + count($third->rows));
        $this->assertSame(3, $this->fake->callCount('ads'));
    }

    public function test_every_page_reserves_exactly_one_request(): void
    {
        $this->fake->paginate(100);
        $before = $this->counter->reserved;

        $this->allInsights('ad'); // 350 rows -> 4 pages

        $this->assertSame(4, $this->counter->reserved - $before);
    }

    public function test_limit_rows_caps_one_method_only(): void
    {
        $this->fake->limitRows('campaigns', 2);

        $page = $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID);
        $this->assertCount(2, $page->rows);
        $this->assertNotNull($page->nextCursor);

        $this->assertCount(5, $this->fake->adSets(self::TOKEN, P::AD_ACCOUNT_ID)->rows);
    }

    public function test_a_bad_cursor_is_rejected(): void
    {
        $this->assertSame('validation', $this->code(fn () => $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID, 'garbage')));
    }

    public function test_usage_can_be_scripted_on_read_pages(): void
    {
        $this->assertNull($this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)->usage);

        $this->fake->withUsage(new MetaApiUsage(90.0, 10.0, 5.0, null));

        $this->assertTrue($this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)->usage->isAtOrAbove(85));
    }

    public function test_the_counter_can_refuse_every_call_and_the_fake_makes_none(): void
    {
        $refusing = new class implements MetaAdsCallCounter
        {
            public function reserve(): void
            {
                throw MetaProviderException::budgetExhausted();
            }
        };

        $fake = (new FakeMetaClient($refusing, new MetaAdsConfig()))->usePhotoBoothFixture();

        $this->assertSame('budget_exhausted', $this->code(fn () => $fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)));
    }

    // ------------------------------------------------------------------
    // Scripted failures
    // ------------------------------------------------------------------

    public function test_a_read_failure_is_scripted_and_consumed(): void
    {
        $this->fake->failNext('campaigns', MetaProviderException::rateLimited(80004), 2);

        $this->assertSame('rate_limited', $this->code(fn () => $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)));
        $this->assertSame('rate_limited', $this->code(fn () => $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)));
        $this->assertSame('none', $this->code(fn () => $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)));
        $this->assertSame('none', $this->code(fn () => $this->fake->adSets(self::TOKEN, P::AD_ACCOUNT_ID)), 'only the named method fails');
    }

    public function test_each_scripted_failure_kind_is_thrown_as_given(): void
    {
        foreach ([
            MetaProviderException::rateLimited(80004),
            MetaProviderException::providerUnavailable(2),
            MetaProviderException::timeout(),
            MetaProviderException::invalidToken(463),
        ] as $exception) {
            $fake = $this->newFake()->failNext('insights', $exception);

            $this->assertSame($exception->classification, $this->code(fn () => $fake->insights(self::TOKEN, P::AD_ACCOUNT_ID, 'ad', '2026-09-01', '2026-09-02')));
        }
    }

    // ------------------------------------------------------------------
    // Mutations
    // ------------------------------------------------------------------

    public function test_pausing_a_campaign_pauses_its_active_ad_sets_and_resuming_restores_them(): void
    {
        $result = $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS, 'campaign', 'PAUSED');

        $this->assertSame(P::CAMPAIGN_LEADS, $result->externalId);
        $this->assertSame('PAUSED', $this->fake->campaignStatus(P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS));

        $effective = [];
        foreach ($this->fake->adSets(self::TOKEN, P::AD_ACCOUNT_ID)->rows as $s) {
            $effective[$s->externalAdSetId] = $s->effectiveStatus;
        }

        $this->assertSame('CAMPAIGN_PAUSED', $effective[P::AD_SET_FATIGUED]);
        $this->assertSame('CAMPAIGN_PAUSED', $effective[P::AD_SET_LOOKALIKE]);
        $this->assertSame('ACTIVE', $effective[P::AD_SET_WEDDING_ENGAGED], 'another campaign is untouched');

        $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS, 'campaign', 'ACTIVE');

        $this->assertSame('ACTIVE', $this->fake->campaignStatus(P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS));
        $this->assertSame('ACTIVE', $this->fake->adSets(self::TOKEN, P::AD_ACCOUNT_ID)->rows[0]->effectiveStatus);
    }

    public function test_ad_sets_and_ads_can_be_paused_and_resumed(): void
    {
        $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::AD_SET_LOOKALIKE, 'ad_set', 'PAUSED');
        $this->assertSame('PAUSED', $this->fake->adSetStatus(P::AD_ACCOUNT_ID, P::AD_SET_LOOKALIKE));

        $this->fake->setStatus(self::TOKEN, null, P::AD_WITH_THUMBNAIL, 'ad', 'PAUSED');
        $this->assertSame('PAUSED', $this->fake->adStatus(P::AD_ACCOUNT_ID, P::AD_WITH_THUMBNAIL));

        $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::AD_WITH_THUMBNAIL, 'ad', 'ACTIVE');
        $this->assertSame('ACTIVE', $this->fake->adStatus(P::AD_ACCOUNT_ID, P::AD_WITH_THUMBNAIL));
    }

    public function test_mutation_validation_and_provider_rules(): void
    {
        $this->assertSame('validation', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS, 'campaign', 'DELETED')));
        $this->assertSame('validation', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS, 'account', 'PAUSED')));
        $this->assertSame('validation', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, 'abc', 'campaign', 'PAUSED')));
        $this->assertSame('not_found', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, '1', 'campaign', 'PAUSED')));
        $this->assertSame('not_found', $this->code(fn () => $this->fake->setStatus(self::TOKEN, null, '1', 'ad', 'PAUSED')));
        // A campaign id used as an ad id is not an ad.
        $this->assertSame('not_found', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_LEADS, 'ad', 'PAUSED')));
        // Meta refuses to resume a disapproved ad.
        $this->assertSame('validation', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::AD_DISAPPROVED, 'ad', 'ACTIVE')));
    }

    public function test_an_applied_then_failed_mutation_is_the_ambiguous_case_the_reconciler_must_survive(): void
    {
        $this->fake->failNext('setStatus', MetaProviderException::timeout(afterMutateSent: true), 1, applyBeforeFailing: true);

        try {
            $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_WEDDING, 'campaign', 'PAUSED');
            $this->fail('expected an ambiguous timeout');
        } catch (MetaProviderException $e) {
            $this->assertTrue($e->isAmbiguous());
        }

        $this->assertSame('PAUSED', $this->fake->campaignStatus(P::AD_ACCOUNT_ID, P::CAMPAIGN_WEDDING), 'it was applied before failing');
    }

    public function test_a_plain_failed_mutation_changes_nothing(): void
    {
        $this->fake->failNext('setStatus', MetaProviderException::timeout(afterMutateSent: true));

        $this->assertSame('timeout', $this->code(fn () => $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::CAMPAIGN_WEDDING, 'campaign', 'PAUSED')));
        $this->assertSame('ACTIVE', $this->fake->campaignStatus(P::AD_ACCOUNT_ID, P::CAMPAIGN_WEDDING));
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    public function test_calls_are_recorded_without_the_token(): void
    {
        $this->fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID);
        $this->fake->insights(self::TOKEN, 'act_' . P::AD_ACCOUNT_ID, 'ad', '2026-09-01', '2026-09-02');
        $this->fake->setStatus(self::TOKEN, P::AD_ACCOUNT_ID, P::AD_SET_LOOKALIKE, 'ad_set', 'PAUSED');

        $this->assertSame(1, $this->fake->callCount('campaigns'));
        $this->assertSame(P::AD_ACCOUNT_ID, $this->fake->callsTo('insights')[0]['ad_account_id']);
        $this->assertSame('ad', $this->fake->callsTo('insights')[0]['args']['level']);
        $this->assertSame('PAUSED', $this->fake->callsTo('setStatus')[0]['args']['requested_state']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($this->fake->calls));
    }

    public function test_a_custom_dataset_replaces_the_fixture_for_that_account(): void
    {
        $fake = (new FakeMetaClient())->withAdAccounts([
            new \App\DTO\MetaAds\MetaAdsAccountCandidate('555', 'Mine', 'GBP', 'Europe/London', 1),
        ])->withDataset('555', ['frequency' => []]);

        $this->assertSame([], $fake->campaigns(self::TOKEN, '555')->rows);
        $this->assertSame([], $fake->frequency7d(self::TOKEN, '555', '2026-09-01', '2026-09-07')->rows);
        $this->assertCount(1, $fake->listAdAccounts(self::TOKEN));
        $this->assertSame('access_denied', $this->code(fn () => $fake->campaigns(self::TOKEN, P::AD_ACCOUNT_ID)));
    }
}
