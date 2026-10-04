<?php

namespace Tests\Feature\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Library\GoogleAds\HttpGoogleAdsAuthClient;
use App\Library\GoogleAds\HttpGoogleAdsMutationClient;
use App\Library\GoogleAds\HttpGoogleAdsReadClient;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §2 — the REAL HTTP clients, exercised ONLY
 * against Http::fake (no request ever leaves the process). These tests pin the
 * wire shape: URL, headers, GAQL, paging, caps, error classification and the
 * mutate payloads.
 */
class HttpGoogleAdsClientTest extends TestCase
{
    use CreatesGoogleAdsFixtures;
    use RefreshDatabase;

    private const BASE = 'https://googleads.googleapis.com/v25';

    private BusinessGoogleConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureValidAdsOAuth();

        [, $business] = $this->adsTenant();
        $this->connection = $this->activeAdsConnection($business);
    }

    private function context(?string $login = '5550001111', string $customer = '1234567890'): GoogleAdsAccessContext
    {
        return new GoogleAdsAccessContext('tok-secret', $customer, $login);
    }

    private function reads(): HttpGoogleAdsReadClient
    {
        return app(HttpGoogleAdsReadClient::class);
    }

    private function mutations(): HttpGoogleAdsMutationClient
    {
        return app(HttpGoogleAdsMutationClient::class);
    }

    /** @template T @param callable():T $work @return T */
    private function inOp(callable $work): mixed
    {
        return $this->inAdsOperation($this->connection, $work);
    }

    /**
     * Http::fake() stubs accumulate and the FIRST match wins, so a test that
     * needs a different answer mid-test starts from a fresh factory.
     */
    private function fakeHttp(array|callable $stubs): void
    {
        Http::swap(new HttpFactory());
        Http::fake($stubs);
    }

    /** @return array<int, Request> */
    private function sent(): array
    {
        return array_map(fn (array $pair) => $pair[0], Http::recorded()->all());
    }

    private function searchResponse(array $results = [], ?string $next = null): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(array_filter(['results' => $results, 'nextPageToken' => $next]));
    }

    private function failure(callable $call): GoogleAdsProviderException
    {
        try {
            $this->inOp($call);
        } catch (GoogleAdsProviderException $e) {
            return $e;
        }

        $this->fail('expected a provider failure');
    }

    private function latestOperation(): BusinessGoogleOperation
    {
        return BusinessGoogleOperation::query()->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Request shape
    // ---------------------------------------------------------------

    public function test_list_accessible_customers_is_a_get_with_only_a_bearer_token(): void
    {
        Http::fake([
            'googleads.googleapis.com/*' => Http::response(['resourceNames' => ['customers/1234567890', 'customers/5550001111', 'bogus', 'customers/12', 'customers/1234567890']]),
        ]);

        $ids = $this->inOp(fn () => $this->reads()->listAccessibleCustomers('tok-secret'));

        $this->assertSame(['1234567890', '5550001111'], $ids, 'malformed names are dropped, duplicates collapsed');

        $request = $this->sent()[0];
        $this->assertSame('GET', $request->method());
        $this->assertSame(self::BASE . '/customers:listAccessibleCustomers', $request->url());
        $this->assertSame('Bearer tok-secret', $request->header('Authorization')[0]);
        $this->assertFalse($request->hasHeader('login-customer-id'));
        $this->assertFalse($request->hasHeader('developer-token'), 'the developer-token header is OFF by default');
    }

    public function test_search_posts_to_the_customer_with_login_customer_id_and_no_developer_token_by_default(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);

        $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $request = $this->sent()[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::BASE . '/customers/1234567890/googleAds:search', $request->url());
        $this->assertSame('Bearer tok-secret', $request->header('Authorization')[0]);
        $this->assertSame('5550001111', $request->header('login-customer-id')[0]);
        $this->assertFalse($request->hasHeader('developer-token'));
        $this->assertArrayNotHasKey('pageToken', $request->data(), 'no page token on the first page');
    }

    public function test_login_customer_id_is_omitted_for_direct_access(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);

        $this->inOp(fn () => $this->reads()->campaigns($this->context(null)));

        $this->assertFalse($this->sent()[0]->hasHeader('login-customer-id'));
    }

    public function test_developer_token_is_sent_only_when_configured(): void
    {
        config(['google_ads.developer_token' => 'dev-token-123']);
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);

        $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame('dev-token-123', $this->sent()[0]->header('developer-token')[0]);

        config(['google_ads.developer_token' => '  ']);
        $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $this->assertFalse($this->sent()[1]->hasHeader('developer-token'));
    }

    public function test_customer_ids_are_normalised_to_digits_in_the_url_and_header(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);

        $this->inOp(fn () => $this->reads()->campaigns($this->context('555-000-1111', '123-456-7890')));

        $request = $this->sent()[0];
        $this->assertSame(self::BASE . '/customers/1234567890/googleAds:search', $request->url());
        $this->assertSame('5550001111', $request->header('login-customer-id')[0]);
    }

    public function test_a_malformed_customer_id_never_reaches_the_network(): void
    {
        Http::fake();

        foreach ([['123', null], ['1234567890', 'x'], ['../1234567890', null]] as [$customer, $login]) {
            try {
                new GoogleAdsAccessContext('tok', $customer, $login);
                $this->fail('a malformed id must be refused');
            } catch (GoogleAdsProviderException $e) {
                $this->assertSame('validation', $e->classification);
            }
        }

        Http::assertNothingSent();
    }

    public function test_base_url_and_api_version_come_from_config(): void
    {
        config(['google_ads.api_version' => 'v26', 'google_ads.base_url' => 'https://ads-proxy.example.test/gateway/']);
        Http::fake(['ads-proxy.example.test/*' => $this->searchResponse()]);

        $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame('https://ads-proxy.example.test/gateway/v26/customers/1234567890/googleAds:search', $this->sent()[0]->url());
    }

    // ---------------------------------------------------------------
    // GAQL
    // ---------------------------------------------------------------

    public function test_report_queries_use_the_verified_sources_and_the_requested_window(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);
        $c = $this->context();

        $this->inOp(function () use ($c) {
            $this->reads()->campaigns($c);
            $this->reads()->adGroups($c);
            $this->reads()->dailyCampaignMetrics($c, '2026-08-03', '2026-10-03');
            $this->reads()->dailyKeywordMetrics($c, '2026-08-03', '2026-10-03');
            $this->reads()->searchTerms($c, '2026-09-04', '2026-10-03');
        });

        $queries = array_map(fn (Request $r) => $r->data()['query'], $this->sent());

        $this->assertStringContainsString('FROM campaign', $queries[0]);
        $this->assertStringContainsString('campaign_budget.amount_micros', $queries[0]);
        $this->assertStringContainsString('FROM ad_group', $queries[1]);
        $this->assertStringContainsString("FROM campaign WHERE segments.date BETWEEN '2026-08-03' AND '2026-10-03'", $queries[2]);
        $this->assertStringContainsString('metrics.cost_micros', $queries[2]);
        $this->assertStringNotContainsString('all_conversions', $queries[2]);
        $this->assertStringContainsString('FROM keyword_view', $queries[3], 'keyword metrics come from keyword_view, not ad_group_criterion');
        $this->assertStringContainsString('FROM search_term_view', $queries[4]);
        $this->assertStringContainsString("BETWEEN '2026-09-04' AND '2026-10-03'", $queries[4]);
        $this->assertStringContainsString('search_term_view.search_term', $queries[4]);
    }

    public function test_keywords_are_three_reports_merged_with_negatives_from_the_right_resources(): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::sequence()
            ->push(['results' => [[
                'adGroupCriterion' => ['criterionId' => '4000000001', 'status' => 'ENABLED', 'negative' => false, 'keyword' => ['text' => 'photo booth rental', 'matchType' => 'PHRASE'], 'qualityInfo' => ['qualityScore' => 8]],
                'adGroup' => ['id' => '3000000001'], 'campaign' => ['id' => '1000000001'],
            ]]])
            ->push(['results' => [[
                'adGroupCriterion' => ['criterionId' => '4000000002', 'status' => 'ENABLED', 'negative' => true, 'keyword' => ['text' => 'for sale', 'matchType' => 'PHRASE']],
                'adGroup' => ['id' => '3000000005'], 'campaign' => ['id' => '1000000003'],
            ]]])
            ->push(['results' => [
                ['campaignCriterion' => ['criterionId' => '4000000003', 'status' => 'ENABLED', 'negative' => true, 'keyword' => ['text' => 'free', 'matchType' => 'BROAD']], 'campaign' => ['id' => '1000000001']],
                ['campaignCriterion' => ['criterionId' => '4000000004', 'negative' => true, 'location' => ['geoTargetConstant' => 'geoTargetConstants/1']], 'campaign' => ['id' => '1000000001']],
            ]])]);

        $result = $this->inOp(fn () => $this->reads()->keywords($this->context()));

        $queries = array_map(fn (Request $r) => $r->data()['query'], $this->sent());
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('FROM ad_group_criterion WHERE ad_group_criterion.negative = FALSE', $queries[0]);
        $this->assertStringContainsString('FROM ad_group_criterion WHERE ad_group_criterion.negative = TRUE', $queries[1]);
        $this->assertStringContainsString('FROM campaign_criterion WHERE campaign_criterion.negative = TRUE', $queries[2]);

        $this->assertSame(
            ['3000000001~4000000001', '3000000005~4000000002', '1000000001~4000000003'],
            array_map(fn ($k) => $k->externalCriterionId, $result->rows),
            'the non-keyword (location) criterion is skipped',
        );
        $this->assertSame([false, true, true], array_map(fn ($k) => $k->isNegative, $result->rows));
        $this->assertSame([GoogleAdsKeywordLevel::AdGroup, GoogleAdsKeywordLevel::AdGroup, GoogleAdsKeywordLevel::Campaign], array_map(fn ($k) => $k->level, $result->rows));
        $this->assertFalse($result->truncated);
    }

    public function test_keywords_report_truncated_when_any_of_the_three_reports_is_capped(): void
    {
        config(['google_ads.sync.max_pages_per_report' => 1]);
        Http::fake(['googleads.googleapis.com/*' => Http::sequence()
            ->push(['results' => []])
            ->push(['results' => [], 'nextPageToken' => 'more'])
            ->push(['results' => []])]);

        $result = $this->inOp(fn () => $this->reads()->keywords($this->context()));

        $this->assertTrue($result->truncated);
    }

    // ---------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------

    public function test_int64_strings_and_doubles_are_parsed_safely(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse([
            ['campaign' => ['id' => '1000000001'], 'segments' => ['date' => '2026-10-03'], 'metrics' => [
                'impressions' => '9007199254740993', 'clicks' => '4', 'interactions' => '4', 'costMicros' => '3600000', 'conversions' => 1.5, 'conversionsValue' => 150,
            ]],
            ['campaign' => ['id' => '1000000002'], 'segments' => ['date' => '2026-10-03'], 'metrics' => ['clicks' => '3']],
            ['campaign' => ['id' => 'not-an-id'], 'segments' => ['date' => '2026-10-03']],
        ])]);

        $rows = $this->inOp(fn () => $this->reads()->dailyCampaignMetrics($this->context(), '2026-10-03', '2026-10-03'))->rows;

        $this->assertCount(2, $rows, 'a row that does not parse is skipped');
        $this->assertSame(9_007_199_254_740_993, $rows[0]->metrics->impressions, 'int64 must not pass through a double');
        $this->assertSame(3_600_000, $rows[0]->metrics->costMicros);
        $this->assertSame('1.500000', $rows[0]->metrics->conversions);
        $this->assertSame('150.000000', $rows[0]->metrics->conversionsValue);
        $this->assertNull($rows[1]->metrics->conversions, 'absent conversions stay null, not 0');
    }

    public function test_customer_details_and_manager_clients_are_mapped(): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::sequence()
            ->push(['results' => [['customer' => ['id' => '1234567890', 'descriptiveName' => 'Snap Booth Co', 'currencyCode' => 'usd', 'timeZone' => 'America/New_York', 'manager' => false, 'testAccount' => true, 'status' => 'ENABLED']]]])
            ->push(['results' => [
                ['customerClient' => ['id' => '5550001111', 'level' => '0', 'manager' => true, 'descriptiveName' => 'Agency', 'currencyCode' => 'USD', 'timeZone' => 'UTC', 'status' => 'ENABLED']],
                ['customerClient' => ['id' => '1234567890', 'level' => '1', 'manager' => false, 'descriptiveName' => 'Snap Booth Co', 'currencyCode' => 'USD', 'timeZone' => 'America/New_York', 'status' => 'ENABLED', 'testAccount' => true, 'hidden' => false]],
                ['customerClient' => ['id' => 'bad', 'level' => '1']],
            ]])]);

        $details = $this->inOp(fn () => $this->reads()->customerDetails($this->context(null)));
        $this->assertSame('USD', $details->currencyCode);
        $this->assertTrue($details->isTest);
        $this->assertFalse($details->isManager);

        $clients = $this->inOp(fn () => $this->reads()->managerClients($this->context('5550001111', '5550001111')))->rows;
        $this->assertCount(2, $clients);
        $this->assertSame(0, $clients[0]->level);
        $this->assertTrue($clients[0]->isManager);
        $this->assertSame(1, $clients[1]->level);
        $this->assertTrue($clients[1]->isTest);

        $queries = array_map(fn (Request $r) => $r->data()['query'], $this->sent());
        $this->assertStringContainsString('FROM customer ', $queries[0] . ' ');
        $this->assertStringContainsString('FROM customer_client', $queries[1]);
        $this->assertSame('5550001111', $this->sent()[1]->header('login-customer-id')[0]);
    }

    public function test_customer_details_for_a_different_customer_than_asked_is_unexpected(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse([['customer' => ['id' => '9999999999', 'currencyCode' => 'USD']]])]);

        $e = $this->failure(fn () => $this->reads()->customerDetails($this->context(null)));

        $this->assertSame('unexpected_response', $e->classification);
    }

    // ---------------------------------------------------------------
    // Paging, caps, budget
    // ---------------------------------------------------------------

    public function test_pagination_follows_the_page_token_and_charges_one_call_per_page(): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::sequence()
            ->push(['results' => [['campaign' => ['id' => '1000000001', 'name' => 'A']]], 'nextPageToken' => 'page-2-token'])
            ->push(['results' => [['campaign' => ['id' => '1000000002', 'name' => 'B']]]])]);

        $result = $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame(['1000000001', '1000000002'], array_map(fn ($c) => $c->externalCampaignId, $result->rows));
        $this->assertSame(2, $result->pagesFetched);
        $this->assertFalse($result->truncated);

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $this->assertArrayNotHasKey('pageToken', $sent[0]->data());
        $this->assertSame('page-2-token', $sent[1]->data()['pageToken']);
        $this->assertSame(2, (int) $this->latestOperation()->provider_call_count);
    }

    public function test_the_page_cap_truncates_and_stops_requesting(): void
    {
        config(['google_ads.sync.max_pages_per_report' => 2]);
        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => [['campaign' => ['id' => '1000000001', 'name' => 'A']]], 'nextPageToken' => 'again'])]);

        $result = $this->inOp(fn () => $this->reads()->campaigns($this->context()));

        $this->assertTrue($result->truncated, 'truncated data is never presented as complete');
        $this->assertSame(2, $result->pagesFetched);
        $this->assertCount(2, $this->sent());
    }

    public function test_the_row_cap_truncates_only_when_google_still_had_more(): void
    {
        config(['google_ads.sync.max_rows_per_report' => 2]);
        $rows = [
            ['campaign' => ['id' => '1000000001', 'name' => 'A']],
            ['campaign' => ['id' => '1000000002', 'name' => 'B']],
            ['campaign' => ['id' => '1000000003', 'name' => 'C']],
        ];

        Http::fake(['googleads.googleapis.com/*' => Http::sequence()
            ->push(['results' => $rows])                                       // 3 rows > cap
            ->push(['results' => array_slice($rows, 0, 2), 'nextPageToken' => 'more'])  // exactly cap, more pending
            ->push(['results' => array_slice($rows, 0, 2)])]);                // exactly cap, nothing more

        $over = $this->inOp(fn () => $this->reads()->campaigns($this->context()));
        $this->assertCount(2, $over->rows);
        $this->assertTrue($over->truncated);

        $pending = $this->inOp(fn () => $this->reads()->campaigns($this->context()));
        $this->assertCount(2, $pending->rows);
        $this->assertTrue($pending->truncated);

        $exact = $this->inOp(fn () => $this->reads()->campaigns($this->context()));
        $this->assertCount(2, $exact->rows);
        $this->assertFalse($exact->truncated, 'a report that fit exactly is complete');
    }

    public function test_the_hourly_budget_stops_the_second_request_before_it_is_sent(): void
    {
        config(['google_ads.sync.max_calls_per_business_per_hour' => 1]);
        Http::fake(['googleads.googleapis.com/*' => $this->searchResponse()]);

        $this->inOp(function () {
            $this->reads()->campaigns($this->context());

            try {
                $this->reads()->adGroups($this->context());
                $this->fail('the second request must be refused');
            } catch (GoogleAdsProviderException $e) {
                $this->assertSame('budget_exhausted', $e->classification);
                $this->assertTrue($e->isDeferrable(), 'our own budget defers, never fails');
                $this->assertFalse($e->isAmbiguous());
            }
        });

        $this->assertCount(1, $this->sent(), 'zero requests are made once the budget is spent');
    }

    public function test_a_provider_call_outside_any_operation_is_refused_and_never_sent(): void
    {
        Http::fake();

        try {
            $this->reads()->campaigns($this->context());
            $this->fail('an unaccounted request must not reach Google');
        } catch (GoogleAdsProviderException $e) {
            $this->assertSame('budget_exhausted', $e->classification);
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Error classification
    // ---------------------------------------------------------------

    /** @return array<string, array{0:int, 1:array<string,mixed>, 2:string}> */
    public static function errorCases(): array
    {
        return [
            '429 is rate limited' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], 'rate_limited'],
            'RESOURCE_EXHAUSTED on any status is rate limited' => [400, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], 'rate_limited'],
            '401 is access denied' => [401, ['error' => ['status' => 'UNAUTHENTICATED']], 'access_denied'],
            '403 is access denied' => [403, ['error' => ['status' => 'PERMISSION_DENIED']], 'access_denied'],
            '404 is not found' => [404, ['error' => ['status' => 'NOT_FOUND']], 'not_found'],
            '400 is validation' => [400, ['error' => ['status' => 'INVALID_ARGUMENT']], 'validation'],
            '500 is provider unavailable' => [500, ['error' => ['status' => 'INTERNAL']], 'provider_unavailable'],
            '503 is provider unavailable' => [503, [], 'provider_unavailable'],
            'anything else is unexpected' => [418, [], 'unexpected_response'],
        ];
    }

    #[DataProvider('errorCases')]
    public function test_a_failed_search_is_classified_into_the_closed_vocabulary(int $status, array $body, string $expected): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::response($body, $status)]);

        $e = $this->failure(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame($expected, $e->classification);
        $this->assertSame($expected === 'rate_limited', $e->isDeferrable(), 'only a 429 is deferred');
        $this->assertFalse($e->isAmbiguous(), 'a failed READ is never ambiguous');
    }

    public function test_the_provider_message_never_reaches_the_exception(): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::response(['error' => [
            'status' => 'PERMISSION_DENIED',
            'message' => 'User tok-secret cannot access customer 1234567890 secret-detail',
        ]], 403)]);

        $e = $this->failure(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame('access_denied', $e->getMessage());
        $this->assertStringNotContainsString('secret-detail', $e->getMessage() . $e->userMessage());
    }

    public function test_a_failure_logs_the_request_id_and_classification_but_never_a_secret(): void
    {
        Log::spy();
        Http::fake(['googleads.googleapis.com/*' => Http::response(
            ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'tok-secret leaked']],
            403,
            ['request-id' => 'req-abc-123'],
        )]);

        $this->failure(fn () => $this->reads()->campaigns($this->context()));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            return $message === 'google_ads.request_failed'
                && $context['request_id'] === 'req-abc-123'
                && $context['classification'] === 'access_denied'
                && $context['http_status'] === 403
                && ! str_contains($encoded, 'tok-secret')
                && ! str_contains($encoded, 'leaked');
        })->once();
    }

    public function test_a_connection_failure_on_a_read_is_a_plain_timeout_not_ambiguous(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $e = $this->failure(fn () => $this->reads()->campaigns($this->context()));

        $this->assertSame('timeout', $e->classification);
        $this->assertFalse($e->isAmbiguous());
        $this->assertFalse($e->isDeferrable());
    }

    public function test_a_non_json_success_body_is_unexpected(): void
    {
        Http::fake(['googleads.googleapis.com/*' => Http::response('<html>proxy error</html>', 200)]);

        $this->assertSame('unexpected_response', $this->failure(fn () => $this->reads()->campaigns($this->context()))->classification);
    }

    // ---------------------------------------------------------------
    // Mutations
    // ---------------------------------------------------------------

    private function mutateResponse(string $resourceName): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['results' => [['resourceName' => $resourceName]]]);
    }

    public function test_pause_a_campaign_uses_update_with_a_status_mask(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->mutateResponse('customers/1234567890/campaigns/1000000001')]);

        $result = $this->inOp(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', GoogleAdsEntityStatus::Paused));

        $this->assertSame('customers/1234567890/campaigns/1000000001', $result->resourceName);

        $request = $this->sent()[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::BASE . '/customers/1234567890/campaigns:mutate', $request->url());
        $this->assertSame('5550001111', $request->header('login-customer-id')[0]);
        $this->assertSame([
            'operations' => [[
                'update' => ['resourceName' => 'customers/1234567890/campaigns/1000000001', 'status' => 'PAUSED'],
                'updateMask' => 'status',
            ]],
            'validateOnly' => false,
            'partialFailure' => false,
        ], $request->data());
    }

    public function test_resume_a_keyword_targets_the_ad_group_criterion(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->mutateResponse('customers/1234567890/adGroupCriteria/3000000001~4000000001')]);

        $this->inOp(fn () => $this->mutations()->setKeywordStatus($this->context(), '3000000001', '4000000001', GoogleAdsEntityStatus::Enabled));

        $request = $this->sent()[0];
        $this->assertSame(self::BASE . '/customers/1234567890/adGroupCriteria:mutate', $request->url());
        $this->assertSame([
            'update' => ['resourceName' => 'customers/1234567890/adGroupCriteria/3000000001~4000000001', 'status' => 'ENABLED'],
            'updateMask' => 'status',
        ], $request->data()['operations'][0]);
    }

    public function test_a_campaign_negative_is_a_create_on_campaign_criteria(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->mutateResponse('customers/1234567890/campaignCriteria/1000000003~99')]);

        $result = $this->inOp(fn () => $this->mutations()->addNegativeKeyword(
            $this->context(), GoogleAdsKeywordLevel::Campaign, '1000000003', '  machine   for sale ', GoogleAdsMatchType::Phrase,
        ));

        $this->assertSame('customers/1234567890/campaignCriteria/1000000003~99', $result->resourceName);

        $request = $this->sent()[0];
        $this->assertSame(self::BASE . '/customers/1234567890/campaignCriteria:mutate', $request->url());
        $this->assertSame([
            'create' => [
                'campaign' => 'customers/1234567890/campaigns/1000000003',
                'negative' => true,
                'keyword' => ['text' => 'machine for sale', 'matchType' => 'PHRASE'],
            ],
        ], $request->data()['operations'][0]);
        $this->assertArrayNotHasKey('updateMask', $request->data()['operations'][0], 'a negative is created, never updated');
    }

    public function test_an_ad_group_negative_is_a_create_on_ad_group_criteria(): void
    {
        Http::fake(['googleads.googleapis.com/*' => $this->mutateResponse('customers/1234567890/adGroupCriteria/3000000005~77')]);

        $this->inOp(fn () => $this->mutations()->addNegativeKeyword(
            $this->context(), GoogleAdsKeywordLevel::AdGroup, '3000000005', 'for sale', GoogleAdsMatchType::Exact,
        ));

        $request = $this->sent()[0];
        $this->assertSame(self::BASE . '/customers/1234567890/adGroupCriteria:mutate', $request->url());
        $this->assertSame([
            'create' => [
                'adGroup' => 'customers/1234567890/adGroups/3000000005',
                'negative' => true,
                'keyword' => ['text' => 'for sale', 'matchType' => 'EXACT'],
            ],
        ], $request->data()['operations'][0]);
    }

    public function test_invalid_mutations_fail_locally_with_zero_requests_and_zero_budget(): void
    {
        Http::fake();

        $this->inOp(function () {
            foreach ([GoogleAdsEntityStatus::Removed, GoogleAdsEntityStatus::Unknown] as $status) {
                $this->assertSame('validation', $this->capture(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', $status)));
            }

            $this->assertSame('validation', $this->capture(fn () => $this->mutations()->setCampaignStatus($this->context(), '1; DROP', GoogleAdsEntityStatus::Paused)));
            $this->assertSame('validation', $this->capture(fn () => $this->mutations()->setKeywordStatus($this->context(), 'x', '1', GoogleAdsEntityStatus::Paused)));

            foreach (['', '   ', str_repeat('a', 81), implode(' ', range(1, 11))] as $bad) {
                $this->assertSame('validation', $this->capture(fn () => $this->mutations()->addNegativeKeyword($this->context(), GoogleAdsKeywordLevel::Campaign, '1000000001', $bad, GoogleAdsMatchType::Exact)), $bad);
            }
        });

        Http::assertNothingSent();
        $this->assertSame(0, (int) $this->latestOperation()->provider_call_count);
    }

    private function capture(callable $call): string
    {
        try {
            $call();
        } catch (GoogleAdsProviderException $e) {
            return $e->classification;
        }

        return 'no failure';
    }

    public function test_a_connection_failure_after_a_mutate_is_ambiguous_and_never_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $e = $this->failure(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', GoogleAdsEntityStatus::Paused));

        $this->assertSame('timeout', $e->classification);
        $this->assertTrue($e->isAmbiguous(), 'the write may or may not have happened');
        $this->assertFalse($e->isDeferrable());
        $this->assertSame(1, $attempts, 'a mutation is never replayed automatically');
    }

    public function test_an_unusable_success_body_after_a_mutate_is_also_ambiguous(): void
    {
        foreach ([Http::response('<html>gateway</html>', 200), Http::response(['results' => []], 200), Http::response(['results' => [['resourceName' => '']]], 200)] as $response) {
            $this->fakeHttp(['googleads.googleapis.com/*' => $response]);

            $e = $this->failure(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', GoogleAdsEntityStatus::Paused));

            $this->assertSame('unexpected_response', $e->classification);
            $this->assertTrue($e->isAmbiguous());
        }
    }

    public function test_definite_mutation_rejections_are_not_ambiguous(): void
    {
        foreach ([[400, 'validation', false], [403, 'access_denied', false], [404, 'not_found', false], [429, 'rate_limited', true]] as [$status, $expected, $deferrable]) {
            $this->fakeHttp(['googleads.googleapis.com/*' => Http::response(['error' => ['status' => 'X']], $status)]);

            $e = $this->failure(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', GoogleAdsEntityStatus::Paused));

            $this->assertSame($expected, $e->classification);
            $this->assertFalse($e->isAmbiguous(), (string) $status);
            $this->assertSame($deferrable, $e->isDeferrable(), (string) $status);
        }
    }

    /** @return array<string, array{0: int, 1: ?string}> */
    public static function ambiguousMutateStatuses(): array
    {
        return [
            '500' => [500, null],
            '503' => [503, null],
            '408' => [408, null],
            '409 ABORTED' => [409, 'ABORTED'],
            '409 UNKNOWN' => [409, 'UNKNOWN'],
            'DEADLINE_EXCEEDED' => [504, 'DEADLINE_EXCEEDED'],
            'INTERNAL' => [500, 'INTERNAL'],
            'UNAVAILABLE' => [503, 'UNAVAILABLE'],
        ];
    }

    #[DataProvider('ambiguousMutateStatuses')]
    public function test_an_ambiguous_http_answer_to_a_mutate_is_unknown_and_never_retried(int $status, ?string $googleStatus): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts, $status, $googleStatus) {
            $attempts++;

            return Http::response($googleStatus === null ? [] : ['error' => ['status' => $googleStatus]], $status);
        });

        $e = $this->failure(fn () => $this->mutations()->setCampaignStatus($this->context(), '1000000001', GoogleAdsEntityStatus::Paused));

        $this->assertSame('timeout', $e->classification);
        $this->assertTrue($e->isAmbiguous(), 'Google may have executed it before answering');
        $this->assertFalse($e->isDeferrable());
        $this->assertSame(1, $attempts);
    }

    public function test_the_same_ambiguous_statuses_stay_provider_unavailable_for_a_read(): void
    {
        $this->fakeHttp(['googleads.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 503)]);

        $e = $this->failure(fn () => $this->reads()->listAccessibleCustomers($this->context()->accessToken));

        $this->assertSame('provider_unavailable', $e->classification);
        $this->assertFalse($e->isAmbiguous());
    }

    public function test_the_ledger_records_each_failure_kind_with_the_right_status(): void
    {
        $ledger = app(GoogleAdsOperationLedger::class);

        $cases = [
            [GoogleAdsProviderException::rateLimited(), GoogleOperationStatus::Deferred],
            [GoogleAdsProviderException::budgetExhausted(), GoogleOperationStatus::Deferred],
            [GoogleAdsProviderException::timeout(afterMutateSent: true), GoogleOperationStatus::Unknown],
            [GoogleAdsProviderException::timeout(), GoogleOperationStatus::Failed],
            [GoogleAdsProviderException::notFound(), GoogleOperationStatus::Failed],
            [GoogleAdsProviderException::validation(), GoogleOperationStatus::Failed],
            [GoogleAdsProviderException::accessDenied(), GoogleOperationStatus::Failed],
        ];

        foreach ($cases as [$exception, $status]) {
            $operation = $ledger->open((int) $this->connection->business_id, \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsSync);
            $ledger->fail($operation, $exception, 'summary');

            $fresh = $operation->fresh();
            $this->assertSame($status, $fresh->status, $exception->classification);
            $this->assertSame($exception->classification, $fresh->failure_classification);
            $this->assertNotNull($fresh->completed_at);
        }
    }

    // ---------------------------------------------------------------
    // OAuth
    // ---------------------------------------------------------------

    public function test_the_authorization_url_requests_the_adwords_scope_with_forced_offline_consent(): void
    {
        $url = app(HttpGoogleAdsAuthClient::class)->authorizationUrl('signed.state', true);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('test-ads-client-id', $q['client_id']);
        $this->assertSame(config('services.google_ads.redirect'), $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('https://www.googleapis.com/auth/adwords', $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('false', $q['include_granted_scopes']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('signed.state', $q['state']);
        $this->assertStringNotContainsString('test-ads-client-secret', $url);

        parse_str((string) parse_url(app(HttpGoogleAdsAuthClient::class)->authorizationUrl('s', false), PHP_URL_QUERY), $noConsent);
        $this->assertArrayNotHasKey('prompt', $noConsent);
    }

    public function test_the_code_exchange_posts_the_dedicated_credentials_to_the_token_endpoint(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/adwords',
        ])]);

        $grant = $this->inOp(fn () => app(HttpGoogleAdsAuthClient::class)->exchangeAuthorizationCode('the-code'));

        $this->assertSame('refresh-1', $grant->refreshToken);
        $this->assertSame('access-1', $grant->accessToken);
        $this->assertSame(3599, $grant->expiresInSeconds);

        $request = $this->sent()[0];
        $this->assertSame('https://oauth2.googleapis.com/token', $request->url());
        $this->assertSame('the-code', $request->data()['code']);
        $this->assertSame('authorization_code', $request->data()['grant_type']);
        $this->assertSame('test-ads-client-id', $request->data()['client_id']);
        $this->assertSame('test-ads-client-secret', $request->data()['client_secret']);
        $this->assertSame(config('services.google_ads.redirect'), $request->data()['redirect_uri']);
        $this->assertSame(1, (int) $this->latestOperation()->provider_call_count, 'the token exchange is budget-accounted');
    }

    public function test_a_revoked_refresh_token_is_invalid_grant_and_an_outage_is_not(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $e = $this->failure(fn () => app(HttpGoogleAdsAuthClient::class)->exchangeRefreshToken('old-refresh'));
        $this->assertTrue($e->isRevocation());

        $this->fakeHttp(['oauth2.googleapis.com/*' => Http::response([], 503)]);
        $e = $this->failure(fn () => app(HttpGoogleAdsAuthClient::class)->exchangeRefreshToken('old-refresh'));
        $this->assertFalse($e->isRevocation());
        $this->assertSame('provider_unavailable', $e->classification);

        $this->fakeHttp(['oauth2.googleapis.com/*' => Http::response([], 429)]);
        $this->assertTrue($this->failure(fn () => app(HttpGoogleAdsAuthClient::class)->exchangeRefreshToken('r'))->isDeferrable());

        $this->fakeHttp(['oauth2.googleapis.com/*' => Http::response(['no_access_token' => true])]);
        $this->assertSame('unexpected_response', $this->failure(fn () => app(HttpGoogleAdsAuthClient::class)->exchangeRefreshToken('r'))->classification);
    }
}
