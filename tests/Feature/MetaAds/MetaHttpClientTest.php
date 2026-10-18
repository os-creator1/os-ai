<?php

namespace Tests\Feature\MetaAds;

use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAdsCallCounter;
use App\Library\MetaAds\HttpMetaAuthClient;
use App\Library\MetaAds\HttpMetaMutationClient;
use App\Library\MetaAds\HttpMetaReadClient;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaAdsTargetingSummary;
use App\Library\MetaAds\MetaGraphTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract §2 / §6 / §7 — the REAL HTTP clients, exercised
 * ONLY against Http::fake (no request ever leaves the process). They pin the
 * wire shape: URL + version, headers, appsecret_proof, cursor paging, field
 * lists, error classification (incl. mutate ambiguity), usage headers,
 * thumbnail allow-list and action filtering. No database is used.
 */
class MetaHttpClientTest extends TestCase
{
    private const TOKEN = 'tok-secret-123';

    private const APP_SECRET = 'app-secret-xyz';

    private const BASE = 'https://graph.facebook.com/v26.0';

    private object $counter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.app_id' => '555000111',
            'services.meta_ads.app_secret' => self::APP_SECRET,
            'services.meta_ads.redirect' => 'https://app.example.test/ads/meta/oauth/callback',
        ]);

        $this->counter = new class implements MetaAdsCallCounter
        {
            public int $reserved = 0;

            public ?MetaProviderException $failWith = null;

            public function reserve(): void
            {
                $this->reserved++;

                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
            }
        };
    }

    private function transport(): MetaGraphTransport
    {
        return new MetaGraphTransport(new MetaAdsConfig(), $this->counter);
    }

    private function reads(): HttpMetaReadClient
    {
        return new HttpMetaReadClient($this->transport(), new MetaAdsConfig());
    }

    private function mutations(): HttpMetaMutationClient
    {
        return new HttpMetaMutationClient($this->transport());
    }

    private function auth(): HttpMetaAuthClient
    {
        return new HttpMetaAuthClient($this->transport(), new MetaAdsConfig());
    }

    private function proof(): string
    {
        return hash_hmac('sha256', self::TOKEN, self::APP_SECRET);
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    private function fakeGraph(mixed $response): void
    {
        Http::fake(['graph.facebook.com/*' => $response]);
    }

    // ------------------------------------------------------------------
    // Wire shape
    // ------------------------------------------------------------------

    public function test_a_read_uses_the_pinned_version_bearer_header_and_appsecret_proof(): void
    {
        $this->fakeGraph(Http::response(['data' => []]));

        $page = $this->reads()->campaigns(self::TOKEN, '1234567890123456');

        $this->assertSame([], $page->rows);
        $this->assertNull($page->nextCursor);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $query = $this->query($request);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), self::BASE . '/act_1234567890123456/campaigns?')
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && ($query['appsecret_proof'] ?? null) === $this->proof()
                && ! array_key_exists('access_token', $query)
                && ! str_contains($request->url(), self::TOKEN)
                && ($query['limit'] ?? null) === '500'
                && ! array_key_exists('after', $query)
                && ($query['fields'] ?? null) === HttpMetaReadClient::CAMPAIGN_FIELDS;
        });

        $this->assertSame(1, $this->counter->reserved);
    }

    public function test_the_api_version_and_base_url_come_from_config(): void
    {
        config(['meta_ads.api_version' => 'v27.1', 'meta_ads.base_url' => 'https://graph.example.test']);
        Http::fake(['graph.example.test/*' => Http::response(['data' => []])]);

        $this->reads()->campaigns(self::TOKEN, '1');

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://graph.example.test/v27.1/act_1/campaigns'));
    }

    public function test_paging_uses_our_own_after_cursor_and_never_follows_paging_next(): void
    {
        $leakingNext = 'https://graph.facebook.com/v26.0/act_1/campaigns?access_token=LEAKED&after=QVFIUjJ&limit=500';

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['data' => [['id' => '11', 'name' => 'A', 'status' => 'ACTIVE']], 'paging' => ['cursors' => ['before' => 'b0', 'after' => 'QVFIUjJ'], 'next' => $leakingNext]])
            ->push(['data' => [['id' => '12', 'name' => 'B', 'status' => 'PAUSED']], 'paging' => ['cursors' => ['before' => 'b1', 'after' => 'QVFIUjK']]]),
        ]);

        $first = $this->reads()->campaigns(self::TOKEN, '1');
        $this->assertSame('QVFIUjJ', $first->nextCursor);
        $this->assertTrue($first->hasMore());

        $second = $this->reads()->campaigns(self::TOKEN, '1', $first->nextCursor);
        $this->assertNull($second->nextCursor, 'cursors.after alone (no paging.next) means the last page');

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('QVFIUjJ', $this->query($requests[1][0])['after']);

        foreach ($requests as [$request]) {
            $this->assertStringNotContainsString('LEAKED', $request->url());
            $this->assertStringStartsWith(self::BASE . '/', $request->url());
        }
    }

    public function test_a_next_page_without_a_usable_cursor_is_an_unexpected_response(): void
    {
        $this->fakeGraph(Http::response(['data' => [], 'paging' => ['next' => 'https://graph.facebook.com/x', 'cursors' => ['after' => 'bad cursor!']]]));

        $this->expectException(MetaProviderException::class);
        $this->reads()->campaigns(self::TOKEN, '1');
    }

    public function test_an_invalid_caller_cursor_or_account_id_is_rejected_before_any_request(): void
    {
        Http::fake();

        foreach ([
            fn () => $this->reads()->campaigns(self::TOKEN, '1', 'bad cursor'),
            fn () => $this->reads()->campaigns(self::TOKEN, '1/../2'),
            fn () => $this->reads()->insights(self::TOKEN, '1', 'creative', '2026-01-01', '2026-01-02'),
            fn () => $this->reads()->insights(self::TOKEN, '1', 'ad', '2026-02-01', '2026-01-02'),
            fn () => $this->reads()->frequency7d(self::TOKEN, '1', 'nope', '2026-01-02'),
        ] as $call) {
            try {
                $call();
                $this->fail('expected a validation failure');
            } catch (MetaProviderException $e) {
                $this->assertSame(MetaProviderException::VALIDATION, $e->classification);
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->counter->reserved);
    }

    public function test_an_act_prefixed_account_id_is_tolerated(): void
    {
        $this->fakeGraph(Http::response(['data' => []]));

        $this->reads()->adSets(self::TOKEN, 'act_42');

        Http::assertSent(fn (Request $r): bool => $this->path($r) === '/v26.0/act_42/adsets');
    }

    public function test_the_budget_counter_is_reserved_before_every_request_and_can_stop_all_traffic(): void
    {
        $this->fakeGraph(Http::response(['data' => []]));

        $this->reads()->campaigns(self::TOKEN, '1');
        $this->reads()->adSets(self::TOKEN, '1');
        $this->reads()->ads(self::TOKEN, '1');
        $this->assertSame(3, $this->counter->reserved);

        $this->counter->failWith = MetaProviderException::budgetExhausted();

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
            $this->fail('expected budget_exhausted');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::BUDGET_EXHAUSTED, $e->classification);
            $this->assertTrue($e->isDeferrable());
        }

        Http::assertSentCount(3);
    }

    public function test_a_missing_app_secret_fails_closed_before_any_request(): void
    {
        config(['services.meta_ads.app_secret' => null]);
        Http::fake();

        $this->expectException(MetaConfigurationException::class);

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
        } finally {
            Http::assertNothingSent();
        }
    }

    // ------------------------------------------------------------------
    // Row mapping
    // ------------------------------------------------------------------

    public function test_listing_ad_accounts_maps_candidates_strips_act_and_skips_malformed_rows(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['account_id' => '1234567890123456', 'id' => 'act_1234567890123456', 'name' => 'Main', 'currency' => 'usd', 'timezone_name' => 'America/New_York', 'account_status' => 1],
            ['account_id' => '2234567890123456', 'name' => 'Old', 'currency' => 'EUR', 'timezone_name' => 'Europe/Berlin', 'account_status' => 2],
            ['account_id' => 'not-digits', 'currency' => 'USD', 'timezone_name' => 'UTC', 'account_status' => 1],
            ['account_id' => '3', 'currency' => 'US', 'timezone_name' => 'UTC', 'account_status' => 1],
        ]]));

        $accounts = $this->reads()->listAdAccounts(self::TOKEN);

        $this->assertCount(2, $accounts);
        $this->assertSame('1234567890123456', $accounts[0]->adAccountId);
        $this->assertSame('USD', $accounts[0]->currencyCode);
        $this->assertTrue($accounts[0]->isSelectable());
        $this->assertFalse($accounts[1]->isSelectable());

        Http::assertSent(fn (Request $r): bool => $this->path($r) === '/v26.0/me/adaccounts'
            && $this->query($r)['fields'] === HttpMetaReadClient::ACCOUNT_FIELDS);
    }

    public function test_listing_ad_accounts_pages_within_the_configured_cap(): void
    {
        config(['meta_ads.sync.max_pages_per_report' => 2]);

        $row = ['account_id' => '7', 'currency' => 'USD', 'timezone_name' => 'UTC', 'account_status' => 1];
        $this->fakeGraph(Http::response(['data' => [$row], 'paging' => ['cursors' => ['after' => 'NEXT'], 'next' => 'https://graph.facebook.com/n']]));

        $this->assertCount(2, $this->reads()->listAdAccounts(self::TOKEN));
        Http::assertSentCount(2);
        $this->assertSame(2, $this->counter->reserved);
    }

    public function test_campaigns_ad_sets_and_ads_are_mapped_with_minor_unit_budgets_and_a_server_built_targeting_summary(): void
    {
        Http::fake([
            'graph.facebook.com/*/campaigns*' => Http::response(['data' => [[
                'id' => '60', 'name' => 'Leads', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS',
                'daily_budget' => '1000', 'budget_remaining' => '640', 'start_time' => '2026-08-01T10:00:00+0000',
            ]]]),
            'graph.facebook.com/*/adsets*' => Http::response(['data' => [[
                'id' => '61', 'campaign_id' => '60', 'name' => 'Set', 'status' => 'ACTIVE', 'effective_status' => 'CAMPAIGN_PAUSED',
                'daily_budget' => '600', 'optimization_goal' => 'LEAD_GENERATION', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting' => ['age_min' => 25, 'age_max' => 54, 'genders' => [2], 'geo_locations' => ['countries' => ['US']]],
            ]]]),
            'graph.facebook.com/*/ads*' => Http::response(['data' => [[
                'id' => '62', 'campaign_id' => '60', 'adset_id' => '61', 'name' => 'Ad', 'status' => 'ACTIVE', 'effective_status' => 'WITH_ISSUES',
                'creative' => ['title' => 'T', 'body' => 'B', 'object_type' => 'SHARE', 'thumbnail_url' => 'https://scontent.xx.fbcdn.net/t.jpg'],
            ]]]),
        ]);

        $campaign = $this->reads()->campaigns(self::TOKEN, '1')->rows[0];
        $this->assertSame('60', $campaign->externalCampaignId);
        $this->assertSame(1000, $campaign->dailyBudgetMinor);
        $this->assertNull($campaign->lifetimeBudgetMinor);
        $this->assertSame(640, $campaign->budgetRemainingMinor);
        $this->assertSame('2026-08-01', $campaign->startTime?->utc()->format('Y-m-d'));

        $adSet = $this->reads()->adSets(self::TOKEN, '1')->rows[0];
        $this->assertSame('CAMPAIGN_PAUSED', $adSet->effectiveStatus);
        $this->assertSame('Ages 25–54 · Women · US', $adSet->targetingSummary);

        $ad = $this->reads()->ads(self::TOKEN, '1')->rows[0];
        $this->assertSame('WITH_ISSUES', $ad->effectiveStatus);
        $this->assertSame('https://scontent.xx.fbcdn.net/t.jpg', $ad->creativeThumbnailUrl);

        Http::assertSent(fn (Request $r): bool => str_contains($this->path($r), '/ads') && $this->query($r)['fields'] === HttpMetaReadClient::AD_FIELDS);
    }

    #[DataProvider('thumbnails')]
    public function test_only_https_thumbnails_on_an_allow_listed_host_survive(string $url, bool $kept): void
    {
        $this->fakeGraph(Http::response(['data' => [[
            'id' => '62', 'campaign_id' => '60', 'adset_id' => '61', 'name' => 'Ad', 'status' => 'ACTIVE',
            'creative' => ['thumbnail_url' => $url],
        ]]]));

        $ad = $this->reads()->ads(self::TOKEN, '1')->rows[0];

        $this->assertSame($kept ? $url : null, $ad->creativeThumbnailUrl);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function thumbnails(): array
    {
        return [
            'fbcdn sub-domain' => ['https://scontent-iad3-1.xx.fbcdn.net/v/t45/a.jpg', true],
            'instagram cdn' => ['https://scontent.cdninstagram.com/a.jpg', true],
            'plain http' => ['http://scontent.xx.fbcdn.net/a.jpg', false],
            'other host' => ['https://evil.example.com/a.jpg', false],
            'look-alike suffix' => ['https://notfbcdn.net/a.jpg', false],
            'bare suffix domain' => ['https://fbcdn.net/a.jpg', false],
            'credentials in url' => ['https://user:pw@scontent.xx.fbcdn.net/a.jpg', false],
            'javascript scheme' => ['javascript:alert(1)', false],
            'data uri' => ['data:image/png;base64,AAAA', false],
            'host trick' => ['https://scontent.xx.fbcdn.net.evil.com/a.jpg', false],
        ];
    }

    public function test_insights_send_the_documented_parameters_and_map_spend_without_floats_and_filter_actions(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            [
                'campaign_id' => '60', 'date_start' => '2026-09-01', 'date_stop' => '2026-09-01', 'account_currency' => 'USD',
                'spend' => '12.340000', 'impressions' => '1500', 'clicks' => '40', 'inline_link_clicks' => '31',
                'actions' => [
                    ['action_type' => 'lead', 'value' => '3'],
                    ['action_type' => 'link_click', 'value' => '31'],
                    ['action_type' => 'post_engagement', 'value' => '99'],
                    ['action_type' => 'landing_page_view', 'value' => '12'],
                    ['action_type' => 'lead', 'value' => '999'],
                ],
                'action_values' => [
                    ['action_type' => 'lead', 'value' => '120.5'],
                    ['action_type' => 'post_engagement', 'value' => '1'],
                ],
            ],
            ['campaign_id' => '61', 'date_start' => '2026-09-01', 'spend' => '0.1', 'impressions' => '5'],
            ['campaign_id' => '62', 'date_start' => '2026-09-01'],
        ]]));

        $page = $this->reads()->insights(self::TOKEN, '1234567890123456', 'campaign', '2026-09-01', '2026-09-02', 'CUR1');

        [$full, $sparse, $empty] = $page->rows;

        $this->assertSame('campaign', $full->level);
        $this->assertSame('60', $full->externalId);
        $this->assertSame('2026-09-01', $full->date);
        $this->assertSame(12_340_000, $full->spendMicros);
        $this->assertSame(1500, $full->impressions);
        $this->assertSame(40, $full->clicks);
        $this->assertSame(31, $full->linkClicks);
        $this->assertSame(
            ['lead' => ['count' => 3, 'value' => 120_500_000], 'link_click' => ['count' => 31, 'value' => null]],
            $full->results,
            'only allow-listed types; first occurrence wins; outside types are dropped',
        );

        $this->assertSame(100_000, $sparse->spendMicros);
        $this->assertNull($sparse->clicks, 'absent is null, never 0');
        $this->assertNull($sparse->linkClicks);
        $this->assertSame([], $sparse->results);

        $this->assertNull($empty->spendMicros, 'absent spend is null, not 0');
        $this->assertNull($empty->impressions);

        Http::assertSent(function (Request $request): bool {
            $q = $this->query($request);

            return $this->path($request) === '/v26.0/act_1234567890123456/insights'
                && $q['level'] === 'campaign'
                && $q['time_increment'] === '1'
                && json_decode($q['time_range'], true) === ['since' => '2026-09-01', 'until' => '2026-09-02']
                && $q['after'] === 'CUR1'
                && $q['limit'] === '500'
                && $q['fields'] === HttpMetaReadClient::INSIGHT_FIELDS . ',campaign_id';
        });
    }

    public function test_insight_levels_map_to_graph_levels_and_id_fields(): void
    {
        $this->fakeGraph(Http::response(['data' => [['adset_id' => '9', 'ad_id' => '8', 'date_start' => '2026-09-01']]]));

        $adSet = $this->reads()->insights(self::TOKEN, '1', 'ad_set', '2026-09-01', '2026-09-01')->rows[0];
        $this->assertSame('ad_set', $adSet->level);
        $this->assertSame('9', $adSet->externalId);

        $ad = $this->reads()->insights(self::TOKEN, '1', 'ad', '2026-09-01', '2026-09-01')->rows[0];
        $this->assertSame('8', $ad->externalId);

        $levels = array_map(fn (array $pair): string => $this->query($pair[0])['level'], Http::recorded()->all());
        $this->assertSame(['adset', 'ad'], $levels);
    }

    #[DataProvider('badSpend')]
    public function test_a_malformed_spend_is_an_unexpected_response_never_rounded(string $spend): void
    {
        $this->fakeGraph(Http::response(['data' => [['campaign_id' => '1', 'date_start' => '2026-09-01', 'spend' => $spend]]]));

        try {
            $this->reads()->insights(self::TOKEN, '1', 'campaign', '2026-09-01', '2026-09-01');
            $this->fail('expected unexpected_response');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::UNEXPECTED_RESPONSE, $e->classification);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function badSpend(): array
    {
        return [
            'seven decimals' => ['1.1234567'],
            'negative' => ['-1.00'],
            'text' => ['twelve'],
            'exponent' => ['1e3'],
            'overflow' => ['99999999999999999999'],
        ];
    }

    public function test_frequency_rows_are_mapped_from_one_non_daily_call(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['adset_id' => '61', 'reach' => '4200', 'frequency' => '3.4123', 'date_start' => '2026-09-26', 'date_stop' => '2026-10-02'],
            ['adset_id' => '62'],
        ]]));

        [$a, $b] = $this->reads()->frequency7d(self::TOKEN, '1', '2026-09-26', '2026-10-02')->rows;

        $this->assertSame(4200, $a->reach);
        $this->assertEqualsWithDelta(3.4123, $a->frequency, 1e-9);
        $this->assertNull($b->reach);
        $this->assertNull($b->frequency);

        Http::assertSent(function (Request $r): bool {
            $q = $this->query($r);

            return $q['level'] === 'adset'
                && ! array_key_exists('time_increment', $q)
                && $q['fields'] === HttpMetaReadClient::FREQUENCY_FIELDS
                && json_decode($q['time_range'], true) === ['since' => '2026-09-26', 'until' => '2026-10-02'];
        });
    }

    public function test_the_configured_page_size_is_sent_and_capped_at_500(): void
    {
        config(['meta_ads.sync.page_size' => 200]);
        $this->fakeGraph(Http::response(['data' => []]));
        $this->reads()->ads(self::TOKEN, '1');

        config(['meta_ads.sync.page_size' => 9999]);
        $this->reads()->ads(self::TOKEN, '1');

        $limits = array_map(fn (array $pair): string => $this->query($pair[0])['limit'], Http::recorded()->all());
        $this->assertSame(['200', '500'], $limits);
    }

    #[DataProvider('targetingSpecs')]
    public function test_the_targeting_summary_is_built_server_side(array $targeting, ?string $expected): void
    {
        $this->assertSame($expected, MetaAdsTargetingSummary::build($targeting));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?string}> */
    public static function targetingSpecs(): array
    {
        return [
            'empty' => [[], null],
            'ages and country' => [['age_min' => 25, 'age_max' => 54, 'geo_locations' => ['countries' => ['US']]], 'Ages 25–54 · US'],
            'open ended age' => [['age_min' => 18, 'age_max' => 65], 'Ages 18–65+'],
            'both genders adds nothing' => [['genders' => [1, 2]], null],
            'men and cities' => [['genders' => [1], 'geo_locations' => ['cities' => [['name' => 'Austin'], ['name' => 'Dallas']]]], 'Men · Austin, Dallas'],
            'location overflow' => [['geo_locations' => ['countries' => ['US', 'CA', 'MX', 'GB', 'FR']]], 'US, CA, MX +2 more'],
            'control chars stripped' => [['geo_locations' => ['regions' => [['name' => "Tex\nas"]]]], 'Tex as'],
        ];
    }

    // ------------------------------------------------------------------
    // Error mapping (reads)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $error
     */
    #[DataProvider('readErrors')]
    public function test_read_errors_are_classified_from_the_graph_error_object(int $status, array $error, string $classification, bool $deferrable, bool $revocation): void
    {
        $this->fakeGraph(Http::response($error === [] ? [] : ['error' => $error + ['message' => 'PROVIDER SECRET TEXT', 'type' => 'OAuthException']], $status));

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
            $this->fail('expected a failure');
        } catch (MetaProviderException $e) {
            $this->assertSame($classification, $e->classification);
            $this->assertSame($deferrable, $e->isDeferrable());
            $this->assertSame($revocation, $e->isRevocation());
            $this->assertFalse($e->isAmbiguous(), 'a failed READ is never ambiguous');
            $this->assertStringNotContainsString('PROVIDER SECRET TEXT', $e->userMessage() . $e->getMessage());
        }
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string, 3: bool, 4: bool}> */
    public static function readErrors(): array
    {
        return [
            '190 plain' => [400, ['code' => 190], 'invalid_token', false, false],
            '190 expired 463' => [401, ['code' => 190, 'error_subcode' => 463], 'token_expired', false, false],
            '190 invalidated 467' => [401, ['code' => 190, 'error_subcode' => 467], 'token_expired', false, false],
            '190 password changed 460' => [400, ['code' => 190, 'error_subcode' => 460], 'invalid_token', false, false],
            '190 app not authorised 458' => [400, ['code' => 190, 'error_subcode' => 458], 'invalid_token', false, true],
            'app level throttle 4' => [400, ['code' => 4], 'rate_limited', true, false],
            'user level throttle 17' => [400, ['code' => 17], 'rate_limited', true, false],
            'page throttle 32' => [400, ['code' => 32], 'rate_limited', true, false],
            'custom throttle 613' => [400, ['code' => 613], 'rate_limited', true, false],
            'ads insights throttle 80000' => [400, ['code' => 80000], 'rate_limited', true, false],
            'ads throttle 80004' => [400, ['code' => 80004], 'rate_limited', true, false],
            'last 80000 series 80014' => [400, ['code' => 80014], 'rate_limited', true, false],
            'throttle that says transient' => [400, ['code' => 17, 'is_transient' => true], 'rate_limited', true, false],
            'http 429 no body' => [429, [], 'rate_limited', true, false],
            'permission 10' => [403, ['code' => 10], 'access_denied', false, false],
            'permission 200' => [403, ['code' => 200], 'access_denied', false, false],
            'permission 299' => [400, ['code' => 299], 'access_denied', false, false],
            'invalid parameter 100' => [400, ['code' => 100], 'validation', false, false],
            'unsupported get 100/33' => [400, ['code' => 100, 'error_subcode' => 33], 'not_found', false, false],
            'object missing 803' => [400, ['code' => 803], 'not_found', false, false],
            'http 404' => [404, [], 'not_found', false, false],
            'transient flag' => [400, ['code' => 2, 'is_transient' => true], 'provider_unavailable', false, false],
            'code 1 unknown' => [500, ['code' => 1], 'provider_unavailable', false, false],
            'code 2 service' => [500, ['code' => 2], 'provider_unavailable', false, false],
            'http 503 no body' => [503, [], 'provider_unavailable', false, false],
            'http 401 no code' => [401, [], 'access_denied', false, false],
            'http 400 no code' => [400, [], 'validation', false, false],
            'http 302 redirect is not followed' => [302, [], 'unexpected_response', false, false],
        ];
    }

    public function test_a_connection_failure_on_a_read_is_a_plain_timeout_not_ambiguous(): void
    {
        $this->fakeGraph(fn () => throw new ConnectionException('cURL error 28 for https://graph.facebook.com/?access_token=' . self::TOKEN));

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
            $this->fail('expected timeout');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::TIMEOUT, $e->classification);
            $this->assertFalse($e->isAmbiguous());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->userMessage());
        }
    }

    public function test_a_non_json_success_body_is_an_unexpected_response(): void
    {
        $this->fakeGraph(Http::response('<html>nope</html>', 200));

        $this->expectException(MetaProviderException::class);
        $this->reads()->campaigns(self::TOKEN, '1');
    }

    public function test_the_fbtrace_id_is_carried_when_safe_and_dropped_when_not(): void
    {
        $this->fakeGraph(Http::response(['error' => ['code' => 4, 'fbtrace_id' => 'AbC123_-xyz']], 400));

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
        } catch (MetaProviderException $e) {
            $this->assertSame('AbC123_-xyz', $e->fbtraceId);
            $this->assertSame(4, $e->providerCode);
        }

        $this->fakeGraph(Http::response(['error' => ['code' => 4, 'fbtrace_id' => "evil\r\nheader: x"]], 400));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 4, 'fbtrace_id' => "evil\nx"]], 400)]);

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
        } catch (MetaProviderException $e) {
            $this->assertNull($e->fbtraceId);
        }
    }

    public function test_failures_log_safe_facts_only_never_a_token_or_a_url(): void
    {
        Log::spy();
        $this->fakeGraph(Http::response(['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'Token ' . self::TOKEN . ' expired', 'fbtrace_id' => 'TRACE1']], 401));

        try {
            $this->reads()->campaigns(self::TOKEN, '1');
        } catch (MetaProviderException) {
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $dump = $message . json_encode($context);

            return $message === 'meta_ads.request_failed'
                && $context['classification'] === 'token_expired'
                && $context['http_status'] === 401
                && $context['code'] === 190
                && $context['fbtrace_id'] === 'TRACE1'
                && ! str_contains($dump, self::TOKEN)
                && ! str_contains($dump, self::APP_SECRET)
                && ! str_contains($dump, 'graph.facebook.com')
                && ! str_contains($dump, $this->proof());
        });
    }

    // ------------------------------------------------------------------
    // Usage headers
    // ------------------------------------------------------------------

    public function test_business_use_case_usage_is_parsed_keeping_the_maximum_and_converting_minutes(): void
    {
        $header = json_encode([
            '123' => [
                ['type' => 'ads_insights', 'call_count' => 28, 'total_time' => 25, 'total_cputime' => 31, 'estimated_time_to_regain_access' => 0],
                ['type' => 'ads_management', 'call_count' => 91, 'total_time' => 12, 'total_cputime' => 12, 'estimated_time_to_regain_access' => 5],
            ],
        ]);

        $this->fakeGraph(Http::response(['data' => []], 200, ['X-Business-Use-Case-Usage' => $header]));

        $usage = $this->reads()->campaigns(self::TOKEN, '1')->usage;

        $this->assertNotNull($usage);
        $this->assertSame(91.0, $usage->callCountPct);
        $this->assertSame(25.0, $usage->totalTimePct);
        $this->assertSame(31.0, $usage->totalCpuPct);
        $this->assertSame(300, $usage->regainSeconds);
        $this->assertTrue($usage->isAtOrAbove(85));
        $this->assertFalse($usage->isAtOrAbove(95));
    }

    public function test_ad_account_usage_is_parsed(): void
    {
        $this->fakeGraph(Http::response(['data' => []], 200, ['X-Ad-Account-Usage' => json_encode(['acc_id_util_pct' => 9.67, 'reset_time_duration' => 100, 'ads_api_access_tier' => 'standard_access'])]));

        $usage = $this->reads()->campaigns(self::TOKEN, '1')->usage;

        $this->assertEqualsWithDelta(9.67, $usage->callCountPct, 1e-9);
        $this->assertNull($usage->regainSeconds, 'a reset time only matters once the account is at 100%');
    }

    public function test_garbage_or_absent_usage_headers_yield_no_usage(): void
    {
        $this->fakeGraph(Http::response(['data' => []], 200, ['X-Business-Use-Case-Usage' => 'not json']));
        $this->assertNull($this->reads()->campaigns(self::TOKEN, '1')->usage);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
        $this->assertNull($this->reads()->campaigns(self::TOKEN, '1')->usage);
    }

    public function test_the_throttle_regain_time_is_carried_on_a_rate_limit_error(): void
    {
        $header = json_encode(['1' => [['call_count' => 100, 'total_time' => 100, 'total_cputime' => 100, 'estimated_time_to_regain_access' => 12]]]);
        $this->fakeGraph(Http::response(['error' => ['code' => 80004]], 400, ['X-Business-Use-Case-Usage' => $header]));

        try {
            $this->reads()->insights(self::TOKEN, '1', 'ad', '2026-09-01', '2026-09-02');
            $this->fail('expected rate_limited');
        } catch (MetaProviderException $e) {
            $this->assertSame(720, $e->regainSeconds);
        }
    }

    // ------------------------------------------------------------------
    // Mutations
    // ------------------------------------------------------------------

    public function test_a_pause_posts_the_status_to_the_entity_id_with_the_proof(): void
    {
        $this->fakeGraph(Http::response(['success' => true]));

        $result = $this->mutations()->setStatus(self::TOKEN, '1234567890123456', '60', 'campaign', 'PAUSED');

        $this->assertSame('60', $result->externalId);
        $this->assertSame('PAUSED', $result->requestedState);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::BASE . '/60'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && $data['status'] === 'PAUSED'
                && $data['appsecret_proof'] === $this->proof()
                && ! str_contains($request->url(), self::TOKEN)
                && count($data) === 2;
        });
        $this->assertSame(1, $this->counter->reserved);
    }

    public function test_an_invalid_mutation_is_rejected_before_any_request(): void
    {
        Http::fake();

        foreach ([
            ['60', 'campaign', 'DELETED'],
            ['60', 'campaign', 'ARCHIVED'],
            ['60', 'account', 'PAUSED'],
            ['60/../61', 'ad', 'PAUSED'],
            ['abc', 'ad', 'ACTIVE'],
        ] as [$id, $type, $state]) {
            try {
                $this->mutations()->setStatus(self::TOKEN, '1', $id, $type, $state);
                $this->fail('expected validation');
            } catch (MetaProviderException $e) {
                $this->assertSame(MetaProviderException::VALIDATION, $e->classification);
                $this->assertFalse($e->isAmbiguous());
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->counter->reserved);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    #[DataProvider('mutationErrors')]
    public function test_mutation_failures_are_ambiguous_unless_definitely_rejected(int $status, array $error, string $classification, bool $ambiguous, bool $deferrable): void
    {
        $this->fakeGraph(Http::response($error === [] ? [] : ['error' => $error], $status));

        try {
            $this->mutations()->setStatus(self::TOKEN, '1', '60', 'ad_set', 'ACTIVE');
            $this->fail('expected a failure');
        } catch (MetaProviderException $e) {
            $this->assertSame($classification, $e->classification);
            $this->assertSame($ambiguous, $e->isAmbiguous());
            $this->assertSame($deferrable, $e->isDeferrable());
        }

        Http::assertSentCount(1); // never replayed
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: string, 3: bool, 4: bool}> */
    public static function mutationErrors(): array
    {
        return [
            '500 after send' => [500, [], 'timeout', true, false],
            '503 after send' => [503, ['code' => 2], 'timeout', true, false],
            '408' => [408, [], 'timeout', true, false],
            'transient flag' => [400, ['code' => 2, 'is_transient' => true], 'timeout', true, false],
            'code 1 unknown error' => [400, ['code' => 1], 'timeout', true, false],
            'code 2 service error' => [400, ['code' => 2], 'timeout', true, false],
            'unclassifiable status' => [418, [], 'unexpected_response', true, false],
            'redirect not followed' => [302, [], 'unexpected_response', true, false],
            '400 invalid parameter' => [400, ['code' => 100], 'validation', false, false],
            '400 no code' => [400, [], 'validation', false, false],
            '403 permission' => [403, ['code' => 200], 'access_denied', false, false],
            '403 no code' => [403, [], 'access_denied', false, false],
            '404' => [404, [], 'not_found', false, false],
            'object missing' => [400, ['code' => 803], 'not_found', false, false],
            'dead token' => [401, ['code' => 190, 'error_subcode' => 463], 'token_expired', false, false],
            'throttled' => [400, ['code' => 80004], 'rate_limited', false, true],
            'throttled but flagged transient' => [400, ['code' => 17, 'is_transient' => true], 'rate_limited', false, true],
            'http 429' => [429, [], 'rate_limited', false, true],
        ];
    }

    public function test_a_dropped_connection_after_a_mutate_is_ambiguous(): void
    {
        $this->fakeGraph(fn () => throw new ConnectionException('cURL error 28'));

        try {
            $this->mutations()->setStatus(self::TOKEN, '1', '60', 'campaign', 'PAUSED');
            $this->fail('expected an ambiguous timeout');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::TIMEOUT, $e->classification);
            $this->assertTrue($e->isAmbiguous());
        }
    }

    public function test_a_2xx_mutation_without_success_true_is_ambiguous(): void
    {
        foreach ([['success' => false], [], ['id' => '60']] as $body) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['graph.facebook.com/*' => Http::response($body)]);

            try {
                $this->mutations()->setStatus(self::TOKEN, '1', '60', 'ad', 'PAUSED');
                $this->fail('expected unexpected_response');
            } catch (MetaProviderException $e) {
                $this->assertSame(MetaProviderException::UNEXPECTED_RESPONSE, $e->classification);
                $this->assertTrue($e->isAmbiguous());
            }
        }

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['graph.facebook.com/*' => Http::response('not json', 200)]);

        try {
            $this->mutations()->setStatus(self::TOKEN, '1', '60', 'ad', 'PAUSED');
            $this->fail('expected unexpected_response');
        } catch (MetaProviderException $e) {
            $this->assertTrue($e->isAmbiguous());
        }
    }

    // ------------------------------------------------------------------
    // OAuth / profile
    // ------------------------------------------------------------------

    public function test_the_authorization_url_requests_only_the_two_ads_permissions(): void
    {
        $url = $this->auth()->authorizationUrl('signed.state');

        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('555000111', $q['client_id']);
        $this->assertSame('https://app.example.test/ads/meta/oauth/callback', $q['redirect_uri']);
        $this->assertSame('signed.state', $q['state']);
        $this->assertSame('ads_read,ads_management', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->assertArrayNotHasKey('client_secret', $q);
        $this->assertStringNotContainsString(self::APP_SECRET, $url);
    }

    public function test_the_code_exchange_makes_two_requests_and_returns_only_the_long_lived_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['access_token' => 'SHORT-LIVED', 'token_type' => 'bearer', 'expires_in' => 3600])
            ->push(['access_token' => 'LONG-LIVED', 'token_type' => 'bearer', 'expires_in' => 5183944]),
        ]);

        $grant = $this->auth()->exchangeCode('the-code');

        $this->assertSame('LONG-LIVED', $grant->accessToken);
        $this->assertNotNull($grant->expiresAt);
        $this->assertEqualsWithDelta(now()->addSeconds(5183944)->timestamp, $grant->expiresAt->timestamp, 5);
        $this->assertStringNotContainsString('LONG-LIVED', print_r($grant, true), 'the token is redacted from dumps');
        $this->assertSame(2, $this->counter->reserved);

        $requests = Http::recorded();
        $first = $this->query($requests[0][0]);
        $second = $this->query($requests[1][0]);

        $this->assertSame($this->path($requests[0][0]), '/v26.0/oauth/access_token');
        $this->assertSame('the-code', $first['code']);
        $this->assertSame(self::APP_SECRET, $first['client_secret']);
        $this->assertSame('https://app.example.test/ads/meta/oauth/callback', $first['redirect_uri']);
        $this->assertFalse($requests[0][0]->hasHeader('Authorization'));

        $this->assertSame('fb_exchange_token', $second['grant_type']);
        $this->assertSame('SHORT-LIVED', $second['fb_exchange_token']);
        $this->assertSame('555000111', $second['client_id']);
    }

    public function test_a_failed_code_exchange_is_classified_and_makes_no_second_request(): void
    {
        $this->fakeGraph(Http::response(['error' => ['code' => 100, 'error_subcode' => 36009, 'message' => 'This authorization code has been used.']], 400));

        try {
            $this->auth()->exchangeCode('used-code');
            $this->fail('expected a failure');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::VALIDATION, $e->classification);
        }

        Http::assertSentCount(1);
    }

    public function test_a_code_exchange_without_an_access_token_is_unexpected(): void
    {
        $this->fakeGraph(Http::response(['expires_in' => 10]));

        $this->expectException(MetaProviderException::class);
        $this->auth()->exchangeCode('c');
    }

    public function test_oauth_configuration_gaps_fail_closed_with_no_request(): void
    {
        Http::fake();

        config(['services.meta_ads.app_id' => null]);
        $this->assertConfigFailure(MetaConfigurationException::MISSING_APP_ID, fn () => $this->auth()->authorizationUrl('s'));

        config(['services.meta_ads.app_id' => '1', 'services.meta_ads.redirect' => null]);
        $this->assertConfigFailure(MetaConfigurationException::MISSING_REDIRECT, fn () => $this->auth()->authorizationUrl('s'));

        config(['services.meta_ads.redirect' => 'http://insecure.example.test/cb']);
        $this->assertConfigFailure(MetaConfigurationException::REDIRECT_NOT_HTTPS, fn () => $this->auth()->exchangeCode('c'));

        config(['services.meta_ads.redirect' => 'https://ok.example.test/cb', 'services.meta_ads.app_secret' => null]);
        $this->assertConfigFailure(MetaConfigurationException::MISSING_APP_SECRET, fn () => $this->auth()->exchangeCode('c'));

        Http::assertNothingSent();
    }

    private function assertConfigFailure(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('expected a configuration failure');
        } catch (MetaConfigurationException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringNotContainsString('555000111', $e->operatorMessage() . $e->customerMessage());
        }
    }

    public function test_profile_and_permissions_are_read_with_the_proof(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => [
                ['permission' => 'public_profile', 'status' => 'granted'],
                ['permission' => 'ads_read', 'status' => 'granted'],
                ['permission' => 'ads_management', 'status' => 'declined'],
                ['permission' => 'BAD PERM', 'status' => 'granted'],
            ]]),
            'graph.facebook.com/*/me?*' => Http::response(['id' => '10001000100010001', 'name' => 'Pat Booth']),
        ]);

        $profile = $this->auth()->profile(self::TOKEN);
        $this->assertSame('10001000100010001', $profile->id);
        $this->assertSame('Pat Booth', $profile->name);

        $this->assertSame(['public_profile', 'ads_read'], $this->auth()->grantedPermissions(self::TOKEN));

        Http::assertSent(fn (Request $r): bool => $this->path($r) === '/v26.0/me'
            && $this->query($r)['fields'] === 'id,name'
            && $this->query($r)['appsecret_proof'] === $this->proof()
            && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
        Http::assertSent(fn (Request $r): bool => $this->path($r) === '/v26.0/me/permissions');
    }

    public function test_a_profile_without_a_numeric_id_is_unexpected(): void
    {
        $this->fakeGraph(Http::response(['id' => 'abc', 'name' => 'x']));

        $this->expectException(MetaProviderException::class);
        $this->auth()->profile(self::TOKEN);
    }
}
