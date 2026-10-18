<?php

namespace Tests\Feature\GoogleAds\Reporting\Concerns;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use App\Models\Business;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsDailyMetric;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsSearchTerm;
use Carbon\CarbonImmutable;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;

/**
 * Seeds NORMALISED Google Ads rows directly (mirroring PhotoBoothFixture's
 * shape) so the readers are tested without any provider or sync code. The
 * clock is pinned by the tests to FIXTURE_NOW (2026-10-04 12:00 UTC =
 * 08:00 on the 4th in the default New York account zone).
 */
trait SeedsGoogleAdsReportingData
{
    use CreatesGoogleAdsFixtures;

    protected const FIXTURE_NOW = '2026-10-04 12:00:00';

    protected function pinAdsClock(string $now = self::FIXTURE_NOW): CarbonImmutable
    {
        $at = CarbonImmutable::parse($now, 'UTC');
        CarbonImmutable::setTestNow($at);
        \Illuminate\Support\Carbon::setTestNow($at);

        return $at;
    }

    protected function unpinAdsClock(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
    }

    protected function adsAccountFor(Business $business, array $overrides = []): GoogleAdsAccount
    {
        $connection = $this->activeAdsConnection($business);

        return GoogleAdsAccount::create(array_merge([
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => '1234567890',
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
        ], $overrides));
    }

    protected function seedCampaign(GoogleAdsAccount $account, string $externalId, string $name, array $overrides = []): GoogleAdsCampaign
    {
        return GoogleAdsCampaign::create(array_merge([
            'business_id' => $account->business_id,
            'google_ads_account_id' => $account->id,
            'external_campaign_id' => $externalId,
            'name' => $name,
            'status' => GoogleAdsEntityStatus::Enabled,
            'channel_type' => 'SEARCH',
            'budget_amount_micros' => 4_000_000,
            'budget_shared' => false,
        ], $overrides));
    }

    protected function seedAdGroup(GoogleAdsCampaign $campaign, string $externalId, string $name): GoogleAdsAdGroup
    {
        return GoogleAdsAdGroup::create([
            'business_id' => $campaign->business_id,
            'google_ads_account_id' => $campaign->google_ads_account_id,
            'google_ads_campaign_id' => $campaign->id,
            'external_ad_group_id' => $externalId,
            'name' => $name,
            'status' => GoogleAdsEntityStatus::Enabled,
        ]);
    }

    protected function seedKeyword(GoogleAdsCampaign $campaign, ?GoogleAdsAdGroup $adGroup, string $criterionId, string $text, array $overrides = []): GoogleAdsKeyword
    {
        $negative = (bool) ($overrides['is_negative'] ?? false);

        return GoogleAdsKeyword::create(array_merge([
            'business_id' => $campaign->business_id,
            'google_ads_account_id' => $campaign->google_ads_account_id,
            'google_ads_campaign_id' => $campaign->id,
            'google_ads_ad_group_id' => $adGroup?->id,
            'external_criterion_id' => $criterionId,
            'text' => $text,
            'match_type' => GoogleAdsMatchType::Phrase,
            'status' => GoogleAdsEntityStatus::Enabled,
            'is_negative' => $negative,
            'level' => $adGroup === null ? GoogleAdsKeywordLevel::Campaign : GoogleAdsKeywordLevel::AdGroup,
            'quality_score' => null,
        ], $overrides));
    }

    /** One per-day metric row (conversions null = no conversion data). */
    protected function seedMetric(
        GoogleAdsAccount $account,
        GoogleAdsMetricLevel $level,
        string $entityKey,
        string $date,
        int $costMicros,
        int $clicks = 10,
        ?string $conversions = '1',
        ?string $value = null,
        int $impressions = 100,
    ): GoogleAdsDailyMetric {
        return GoogleAdsDailyMetric::create([
            'business_id' => $account->business_id,
            'google_ads_account_id' => $account->id,
            'level' => $level,
            'entity_key' => $entityKey,
            'metric_date' => $date,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'interactions' => $clicks,
            'cost_micros' => $costMicros,
            'conversions' => $conversions,
            'conversions_value' => $value,
        ]);
    }

    /** Campaign-level rows for every day from $from to $to inclusive. */
    protected function seedCampaignDays(GoogleAdsAccount $account, string $externalId, string $from, string $to, int $costMicros, int $clicks = 10, ?string $conversions = '1', ?string $value = null): void
    {
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $this->seedMetric($account, GoogleAdsMetricLevel::Campaign, $externalId, $day->format('Y-m-d'), $costMicros, $clicks, $conversions, $value);
        }
    }

    protected function seedSearchTerm(
        GoogleAdsCampaign $campaign,
        GoogleAdsAdGroup $adGroup,
        string $term,
        string $date,
        int $costMicros,
        int $clicks,
        ?string $conversions,
        array $overrides = [],
    ): GoogleAdsSearchTerm {
        return GoogleAdsSearchTerm::create(array_merge([
            'business_id' => $campaign->business_id,
            'google_ads_account_id' => $campaign->google_ads_account_id,
            'google_ads_campaign_id' => $campaign->id,
            'google_ads_ad_group_id' => $adGroup->id,
            'search_term' => $term,
            'term_hash' => GoogleAdsSearchTerm::hashTerm($term),
            'metric_date' => $date,
            'impressions' => $clicks * 10,
            'clicks' => $clicks,
            'cost_micros' => $costMicros,
            'conversions' => $conversions,
            'targeting_status' => GoogleAdsSearchTermStatus::None,
        ], $overrides));
    }
}
