<?php

namespace Tests\Unit\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsCampaignData;
use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsKeywordData;
use App\DTO\GoogleAds\GoogleAdsSearchTermData;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsCustomerId;
use App\Library\GoogleAds\GoogleAdsJson;
use App\Library\GoogleAds\GoogleAdsKeywordText;
use App\Library\GoogleAds\GoogleAdsQueries;
use PHPUnit\Framework\TestCase;

/**
 * Google Ads Module V1 — the small value rules every provider path leans on:
 * customer-id normalisation, keyword text rules, untrusted-JSON parsing, the
 * DTO row mappers and the single GAQL builder.
 */
class GoogleAdsValueHelpersTest extends TestCase
{
    // ---- customer id ----

    public function test_customer_id_normalises_to_ten_digits_or_null(): void
    {
        $this->assertSame('1234567890', GoogleAdsCustomerId::normalize('1234567890'));
        $this->assertSame('1234567890', GoogleAdsCustomerId::normalize('123-456-7890'));
        $this->assertSame('1234567890', GoogleAdsCustomerId::normalize(' 123 456 7890 '));
        $this->assertSame('1234567890', GoogleAdsCustomerId::normalize(1234567890));

        foreach ([null, '', '123', '12345678901', 'abcdefghij', '1234567890; DROP', '../1234567890', 1.5, []] as $bad) {
            $this->assertNull(GoogleAdsCustomerId::normalize($bad), var_export($bad, true));
        }
    }

    public function test_customer_id_from_resource_name(): void
    {
        $this->assertSame('1234567890', GoogleAdsCustomerId::fromResourceName('customers/1234567890'));
        $this->assertNull(GoogleAdsCustomerId::fromResourceName('campaigns/1234567890'));
        $this->assertNull(GoogleAdsCustomerId::fromResourceName('customers/12'));
        $this->assertNull(GoogleAdsCustomerId::fromResourceName(null));
    }

    public function test_access_context_normalises_ids_and_rejects_malformed_ones_before_any_request(): void
    {
        $context = new GoogleAdsAccessContext('tok', '123-456-7890', '555-000-1111');
        $this->assertSame('1234567890', $context->customerId);
        $this->assertSame('5550001111', $context->loginCustomerId);

        foreach ([['tok', '123', null], ['tok', '1234567890', 'x'], ['', '1234567890', null]] as [$token, $customer, $login]) {
            try {
                new GoogleAdsAccessContext($token, $customer, $login);
                $this->fail('A malformed context must be refused.');
            } catch (GoogleAdsProviderException $e) {
                $this->assertSame(GoogleAdsProviderException::VALIDATION, $e->classification);
            }
        }
    }

    public function test_access_context_never_dumps_its_token(): void
    {
        $context = new GoogleAdsAccessContext('super-secret-token', '1234567890');

        ob_start();
        var_dump($context);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('super-secret-token', $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }

    // ---- keyword text ----

    public function test_keyword_text_rules(): void
    {
        $this->assertSame('free photo booth', GoogleAdsKeywordText::normalize('  free   photo booth '));
        $this->assertSame(str_repeat('a', 80), GoogleAdsKeywordText::normalize(str_repeat('a', 80)));
        $this->assertSame(implode(' ', range(1, 10)), GoogleAdsKeywordText::normalize(implode(' ', range(1, 10))));

        $this->assertNull(GoogleAdsKeywordText::normalize(''));
        $this->assertNull(GoogleAdsKeywordText::normalize('   '));
        $this->assertNull(GoogleAdsKeywordText::normalize(str_repeat('a', 81)));
        $this->assertNull(GoogleAdsKeywordText::normalize(implode(' ', range(1, 11))));
        $this->assertNull(GoogleAdsKeywordText::normalize("bad\x00text"));
    }

    // ---- untrusted JSON ----

    public function test_int64_accepts_json_strings_and_rejects_everything_else(): void
    {
        $this->assertSame(9_007_199_254_740_993, GoogleAdsJson::int64('9007199254740993'), 'beyond double precision');
        $this->assertSame(12, GoogleAdsJson::int64(12));
        $this->assertSame(0, GoogleAdsJson::int64('0'));

        foreach (['9223372036854775808', '12.5', ' 12', '012', '1e3', 12.5, null, true, []] as $bad) {
            $this->assertNull(GoogleAdsJson::int64($bad), var_export($bad, true));
        }

        $this->assertNull(GoogleAdsJson::unsignedInt64('-1'));
    }

    public function test_decimal_is_exact_and_absence_is_null_not_zero(): void
    {
        $this->assertSame('3.500000', GoogleAdsJson::decimal(3.5));
        $this->assertSame('2.000000', GoogleAdsJson::decimal(2));
        $this->assertSame('0.000000', GoogleAdsJson::decimal(0.0));
        $this->assertSame('12.340000', GoogleAdsJson::decimal('12.34'));
        $this->assertNull(GoogleAdsJson::decimal(null));
        $this->assertNull(GoogleAdsJson::decimal('abc'));
        $this->assertNull(GoogleAdsJson::decimal(NAN));
    }

    public function test_ids_stay_strings_and_dates_must_be_real(): void
    {
        $this->assertSame('1000000001', GoogleAdsJson::id('1000000001'));
        $this->assertNull(GoogleAdsJson::id('12a'));
        $this->assertNull(GoogleAdsJson::id('123456789012345678901'));
        $this->assertSame('2026-02-28', GoogleAdsJson::date('2026-02-28'));
        $this->assertNull(GoogleAdsJson::date('2026-02-30'));
        $this->assertNull(GoogleAdsJson::date('2026-2-3'));
        $this->assertSame('9', GoogleAdsJson::resourceTail('customers/1/campaignBudgets/9'));
    }

    // ---- DTO row mappers ----

    public function test_campaign_row_maps_with_budget_and_unknown_status_is_safe(): void
    {
        $dto = GoogleAdsCampaignData::fromSearchRow([
            'campaign' => ['id' => '77', 'name' => 'Photo Booth Rental', 'status' => 'WEIRD_NEW_STATUS', 'advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => 'MANUAL_CPC', 'campaignBudget' => 'customers/1/campaignBudgets/9'],
            'campaignBudget' => ['id' => '9', 'amountMicros' => '4000000', 'explicitlyShared' => false],
        ]);

        $this->assertSame('77', $dto->externalCampaignId);
        $this->assertSame(GoogleAdsEntityStatus::Unknown, $dto->status);
        $this->assertSame(4_000_000, $dto->budgetAmountMicros);
        $this->assertFalse($dto->budgetShared);
        $this->assertNull(GoogleAdsCampaignData::fromSearchRow(['campaign' => ['name' => 'no id']]));
    }

    public function test_keyword_rows_build_resource_tails_and_skip_non_keywords(): void
    {
        $adGroup = GoogleAdsKeywordData::fromAdGroupCriterionRow([
            'adGroupCriterion' => ['criterionId' => '55', 'status' => 'ENABLED', 'negative' => false, 'keyword' => ['text' => 'photo booth', 'matchType' => 'PHRASE'], 'qualityInfo' => ['qualityScore' => 7]],
            'adGroup' => ['id' => '33'],
            'campaign' => ['id' => '11'],
        ]);

        $this->assertSame('33~55', $adGroup->externalCriterionId);
        $this->assertSame(GoogleAdsKeywordLevel::AdGroup, $adGroup->level);
        $this->assertSame(GoogleAdsMatchType::Phrase, $adGroup->matchType);
        $this->assertSame(7, $adGroup->qualityScore);
        $this->assertFalse($adGroup->isNegative);

        $campaign = GoogleAdsKeywordData::fromCampaignCriterionRow([
            'campaignCriterion' => ['criterionId' => '66', 'status' => 'ENABLED', 'negative' => true, 'keyword' => ['text' => 'free', 'matchType' => 'BROAD']],
            'campaign' => ['id' => '11'],
        ]);

        $this->assertSame('11~66', $campaign->externalCriterionId);
        $this->assertSame(GoogleAdsKeywordLevel::Campaign, $campaign->level);
        $this->assertNull($campaign->externalAdGroupId);
        $this->assertTrue($campaign->isNegative);
        $this->assertNull($campaign->qualityScore, 'a campaign negative has no quality score');

        // A location criterion carries no keyword and is skipped, never guessed.
        $this->assertNull(GoogleAdsKeywordData::fromCampaignCriterionRow([
            'campaignCriterion' => ['criterionId' => '67', 'negative' => true, 'location' => ['geoTargetConstant' => 'x']],
            'campaign' => ['id' => '11'],
        ]));
        // An out-of-range quality score is dropped, not stored.
        $bad = GoogleAdsKeywordData::fromAdGroupCriterionRow([
            'adGroupCriterion' => ['criterionId' => '55', 'keyword' => ['text' => 'a', 'matchType' => 'EXACT'], 'qualityInfo' => ['qualityScore' => 99]],
            'adGroup' => ['id' => '33'], 'campaign' => ['id' => '11'],
        ]);
        $this->assertNull($bad->qualityScore);
    }

    public function test_metric_rows_read_absent_conversions_as_null_but_absent_clicks_as_zero(): void
    {
        $row = GoogleAdsDailyMetricData::fromCampaignRow([
            'campaign' => ['id' => '11'],
            'segments' => ['date' => '2026-10-01'],
            'metrics' => ['impressions' => '120', 'costMicros' => '3600000'],
        ]);

        $this->assertSame(120, $row->metrics->impressions);
        $this->assertSame(0, $row->metrics->clicks);
        $this->assertSame(3_600_000, $row->metrics->costMicros);
        $this->assertNull($row->metrics->conversions, 'no conversion data is NOT zero conversions');
        $this->assertNull($row->metrics->conversionsValue);

        $zero = GoogleAdsDailyMetricData::fromCampaignRow([
            'campaign' => ['id' => '11'], 'segments' => ['date' => '2026-10-01'],
            'metrics' => ['conversions' => 0.0, 'conversionsValue' => 0.0],
        ]);
        $this->assertSame('0.000000', $zero->metrics->conversions, 'a returned zero stays a zero');

        $this->assertNull(GoogleAdsDailyMetricData::fromCampaignRow(['campaign' => ['id' => '11'], 'segments' => ['date' => 'nope']]));
    }

    public function test_keyword_metric_and_search_term_rows(): void
    {
        $kw = GoogleAdsDailyMetricData::fromKeywordRow([
            'adGroupCriterion' => ['criterionId' => '55'], 'adGroup' => ['id' => '33'],
            'segments' => ['date' => '2026-10-01'], 'metrics' => ['clicks' => '4'],
        ]);
        $this->assertSame('33~55', $kw->entityKey);

        $term = GoogleAdsSearchTermData::fromSearchRow([
            'campaign' => ['id' => '11'], 'adGroup' => ['id' => '33'],
            'searchTermView' => ['searchTerm' => '360 photo booth machine for sale', 'status' => 'NONE'],
            'segments' => ['date' => '2026-10-01'],
            'metrics' => ['clicks' => '2', 'costMicros' => '5000000', 'conversions' => 0],
        ]);
        $this->assertSame('360 photo booth machine for sale', $term->searchTerm);
        $this->assertSame(5_000_000, $term->metrics->costMicros);
        $this->assertNull($term->matchedKeywordText, 'the keyword association is optional and tolerated as absent');
    }

    // ---- GAQL ----

    public function test_queries_use_only_the_verified_sources_and_fields(): void
    {
        $this->assertStringContainsString('FROM campaign', GoogleAdsQueries::campaigns());
        $this->assertStringContainsString('campaign_budget.amount_micros', GoogleAdsQueries::campaigns());
        $this->assertStringContainsString('FROM keyword_view', GoogleAdsQueries::dailyKeywordMetrics('2026-09-01', '2026-10-01'));
        $this->assertStringContainsString('FROM search_term_view', GoogleAdsQueries::searchTerms('2026-09-01', '2026-10-01'));
        $this->assertStringContainsString('segments.date', GoogleAdsQueries::searchTerms('2026-09-01', '2026-10-01'));
        $this->assertStringContainsString('FROM campaign_criterion WHERE campaign_criterion.negative = TRUE', GoogleAdsQueries::campaignNegativeKeywords());
        $this->assertStringContainsString('FROM ad_group_criterion WHERE ad_group_criterion.negative = TRUE', GoogleAdsQueries::adGroupKeywords(true));
        $this->assertStringContainsString('ad_group_criterion.negative = FALSE', GoogleAdsQueries::adGroupKeywords(false));
        $this->assertStringContainsString('FROM customer_client', GoogleAdsQueries::managerClients());

        // ad_group_criterion has no metrics; keyword metrics must come from keyword_view.
        $this->assertStringNotContainsString('metrics.', GoogleAdsQueries::adGroupKeywords(false));
        // Only the primary-action `conversions`, never all_conversions (§2).
        $this->assertStringNotContainsString('all_conversions', GoogleAdsQueries::dailyCampaignMetrics('2026-09-01', '2026-10-01'));
        // The unverified keyword-association segments are not selected.
        $this->assertStringNotContainsString('segments.keyword', GoogleAdsQueries::searchTerms('2026-09-01', '2026-10-01'));
    }

    public function test_a_bad_date_is_a_local_validation_failure(): void
    {
        foreach ([["2026-10-01'; DROP", '2026-10-02'], ['2026-10-02', '2026-10-01'], ['nope', '2026-10-01']] as [$start, $end]) {
            try {
                GoogleAdsQueries::dailyCampaignMetrics($start, $end);
                $this->fail('A bad date range must be refused.');
            } catch (GoogleAdsProviderException $e) {
                $this->assertSame(GoogleAdsProviderException::VALIDATION, $e->classification);
            }
        }
    }
}
