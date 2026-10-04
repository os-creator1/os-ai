<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAdGroupData;
use App\DTO\GoogleAds\GoogleAdsCampaignData;
use App\DTO\GoogleAds\GoogleAdsCustomerDetails;
use App\DTO\GoogleAds\GoogleAdsDailyMetricData;
use App\DTO\GoogleAds\GoogleAdsKeywordData;
use App\DTO\GoogleAds\GoogleAdsManagedClient;
use App\DTO\GoogleAds\GoogleAdsMetrics;
use App\DTO\GoogleAds\GoogleAdsSearchTermData;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 — a DETERMINISTIC, fully synthetic Google Ads account
 * for tests and browser acceptance: "Snap Booth Co", a photo-booth rental
 * business spending roughly $8 a day (about $250 a month) in USD across three
 * Search campaigns. It is served by FakeGoogleAdsClient (driver `fake`, refused
 * in production) and is never real customer data.
 *
 * DETERMINISM. Nothing here uses rand()/mt_rand() or the clock: every figure
 * is derived from crc32 of a fixed label and a day index, and the dates hang
 * off the `$asOf` argument ("today" in the account time zone). The same
 * `$asOf` always yields byte-identical data, on any machine.
 *
 * SHAPE
 *   - 3 campaigns: Photo Booth Rental, Wedding Photo Booth, 360 Booth.
 *   - 6 ad groups (2 each), 18 positive keywords across Exact / Phrase /
 *     Broad, plus campaign- and ad-group-level negatives.
 *   - 62 days of daily metrics ending the day before `$asOf`, at campaign and
 *     keyword level (keyword rows sum EXACTLY to their campaign's clicks,
 *     impressions, cost and conversions).
 *   - Photo Booth Rental and Wedding Photo Booth convert regularly; 360 Booth
 *     spends and never converts (so a zero-conversion campaign exists).
 *   - Search terms for the last 30 days, including the obvious waste term
 *     WASTE_TERM ($29 spend / 11 clicks / 0 conversions) and several
 *     converting terms.
 *   - A customer hierarchy: a manager (5550001111) with the real account
 *     under it, so login-customer-id derivation is exercised.
 */
final class PhotoBoothFixture
{
    public const CUSTOMER_ID = '1234567890';

    public const MANAGER_ID = '5550001111';

    public const DIRECT_CUSTOMER_ID = '1112223333';

    public const SECOND_MANAGED_CUSTOMER_ID = '4445556666';

    public const SUB_MANAGER_ID = '7778889999';

    public const HIDDEN_CUSTOMER_ID = '8889990000';

    public const CANCELED_CUSTOMER_ID = '9990001111';

    public const CURRENCY = 'USD';

    public const TIME_ZONE = 'America/New_York';

    public const MONTHLY_BUDGET_TARGET_MICROS = 250_000_000;

    public const TARGET_CPL_MICROS = 25_000_000;

    public const WASTE_TERM = '360 photo booth machine for sale';

    public const WASTE_TERM_COST_MICROS = 29_000_000;

    public const WASTE_TERM_CLICKS = 11;

    public const METRIC_DAYS = 62;

    public const SEARCH_TERM_DAYS = 30;

    public const CAMPAIGN_RENTAL = '1000000001';

    public const CAMPAIGN_WEDDING = '1000000002';

    public const CAMPAIGN_360 = '1000000003';

    /**
     * campaign id => [name, daily budget micros, bidding strategy,
     *                 clicks/day, impressions/day, cpc micros,
     *                 conversion day modulus, conversion day offset, conversions on a hit].
     * Modulus 0 = never converts.
     *
     * @var array<string, array{0:string, 1:int, 2:string, 3:int, 4:int, 5:int, 6:int, 7:int, 8:int}>
     */
    private const CAMPAIGNS = [
        self::CAMPAIGN_RENTAL => ['Photo Booth Rental', 4_000_000, 'MAXIMIZE_CONVERSIONS', 4, 90, 900_000, 5, 2, 1],
        self::CAMPAIGN_WEDDING => ['Wedding Photo Booth', 3_000_000, 'MANUAL_CPC', 3, 70, 970_000, 7, 3, 1],
        self::CAMPAIGN_360 => ['360 Booth', 2_000_000, 'MANUAL_CPC', 2, 60, 900_000, 0, 0, 0],
    ];

    /**
     * ad group id => [campaign id, name].
     *
     * @var array<string, array{0:string, 1:string}>
     */
    private const AD_GROUPS = [
        '3000000001' => [self::CAMPAIGN_RENTAL, 'Photo Booth Rental - General'],
        '3000000002' => [self::CAMPAIGN_RENTAL, 'Photo Booth Rental - Corporate'],
        '3000000003' => [self::CAMPAIGN_WEDDING, 'Wedding - Core'],
        '3000000004' => [self::CAMPAIGN_WEDDING, 'Wedding - Packages'],
        '3000000005' => [self::CAMPAIGN_360, '360 Booth - Events'],
        '3000000006' => [self::CAMPAIGN_360, '360 Booth - Local'],
    ];

    /**
     * Positive keywords: [ad group id, text, match type, weight, quality score|null].
     *
     * @var array<int, array{0:string, 1:string, 2:GoogleAdsMatchType, 3:int, 4:?int}>
     */
    private const KEYWORDS = [
        ['3000000001', 'photo booth rental', GoogleAdsMatchType::Phrase, 5, 8],
        ['3000000001', 'photo booth rental near me', GoogleAdsMatchType::Exact, 4, 9],
        ['3000000001', 'photo booth hire', GoogleAdsMatchType::Broad, 3, 6],
        ['3000000002', 'corporate photo booth', GoogleAdsMatchType::Phrase, 3, 7],
        ['3000000002', 'photo booth for events', GoogleAdsMatchType::Broad, 2, 6],
        ['3000000002', 'office party photo booth', GoogleAdsMatchType::Exact, 1, null],
        ['3000000003', 'wedding photo booth', GoogleAdsMatchType::Phrase, 5, 9],
        ['3000000003', 'wedding photo booth rental', GoogleAdsMatchType::Exact, 4, 8],
        ['3000000003', 'photo booth for wedding', GoogleAdsMatchType::Broad, 3, 7],
        ['3000000004', 'wedding photo booth package', GoogleAdsMatchType::Phrase, 2, 7],
        ['3000000004', 'unlimited prints photo booth wedding', GoogleAdsMatchType::Exact, 1, null],
        ['3000000004', 'photo booth wedding cost', GoogleAdsMatchType::Broad, 2, 5],
        ['3000000005', '360 photo booth', GoogleAdsMatchType::Phrase, 4, 7],
        ['3000000005', '360 photo booth rental', GoogleAdsMatchType::Exact, 3, 8],
        ['3000000005', 'spinning video booth', GoogleAdsMatchType::Broad, 2, 5],
        ['3000000006', '360 booth near me', GoogleAdsMatchType::Phrase, 2, 6],
        ['3000000006', 'slow motion video booth rental', GoogleAdsMatchType::Exact, 1, null],
        ['3000000006', '360 photo booth party', GoogleAdsMatchType::Broad, 2, 5],
    ];

    /**
     * Negatives: [level, parent id (campaign / ad group), text, match type].
     *
     * @var array<int, array{0:GoogleAdsKeywordLevel, 1:string, 2:string, 3:GoogleAdsMatchType}>
     */
    private const NEGATIVES = [
        [GoogleAdsKeywordLevel::Campaign, self::CAMPAIGN_RENTAL, 'free', GoogleAdsMatchType::Broad],
        [GoogleAdsKeywordLevel::Campaign, self::CAMPAIGN_RENTAL, 'diy photo booth', GoogleAdsMatchType::Phrase],
        [GoogleAdsKeywordLevel::Campaign, self::CAMPAIGN_WEDDING, 'jobs', GoogleAdsMatchType::Broad],
        [GoogleAdsKeywordLevel::AdGroup, '3000000005', 'for sale', GoogleAdsMatchType::Phrase],
    ];

    /**
     * Search terms: [campaign id, ad group id, term, impressions, clicks,
     * cost micros, conversions (whole), days with rows].
     *
     * @var array<int, array{0:string, 1:string, 2:string, 3:int, 4:int, 5:int, 6:int, 7:int}>
     */
    private const SEARCH_TERMS = [
        [self::CAMPAIGN_360, '3000000005', self::WASTE_TERM, 140, self::WASTE_TERM_CLICKS, self::WASTE_TERM_COST_MICROS, 0, 6],
        [self::CAMPAIGN_360, '3000000005', '360 photo booth rental', 260, 14, 15_000_000, 0, 7],
        [self::CAMPAIGN_RENTAL, '3000000001', 'photo booth rental near me', 310, 20, 21_000_000, 4, 10],
        [self::CAMPAIGN_RENTAL, '3000000001', 'photo booth rental cost', 120, 8, 7_400_000, 1, 5],
        [self::CAMPAIGN_RENTAL, '3000000001', 'cheap photo booth hire', 90, 6, 5_100_000, 0, 4],
        [self::CAMPAIGN_RENTAL, '3000000002', 'diy photo booth', 60, 4, 3_200_000, 0, 3],
        [self::CAMPAIGN_WEDDING, '3000000003', 'wedding photo booth rental', 280, 17, 17_500_000, 3, 9],
        [self::CAMPAIGN_WEDDING, '3000000003', 'photo booth for wedding', 190, 9, 8_800_000, 1, 6],
        [self::CAMPAIGN_WEDDING, '3000000004', 'wedding photo booth package prices', 70, 4, 4_000_000, 0, 3],
    ];

    private readonly CarbonImmutable $asOf;

    public function __construct(CarbonImmutable|string|null $asOf = null)
    {
        $this->asOf = ($asOf instanceof CarbonImmutable ? $asOf : CarbonImmutable::parse($asOf ?? 'now', self::TIME_ZONE))
            ->setTimezone(self::TIME_ZONE)
            ->startOfDay();
    }

    public function asOf(): CarbonImmutable
    {
        return $this->asOf;
    }

    /** First and last day with metrics (inclusive), Y-m-d. */
    public function metricsWindow(): array
    {
        return [
            $this->asOf->subDays(self::METRIC_DAYS)->toDateString(),
            $this->asOf->subDay()->toDateString(),
        ];
    }

    public function customerDetails(): GoogleAdsCustomerDetails
    {
        return new GoogleAdsCustomerDetails(self::CUSTOMER_ID, 'Snap Booth Co', self::CURRENCY, self::TIME_ZONE, false, true, 'ENABLED');
    }

    /** @return array<int, string> directly accessible customer ids */
    public function accessibleCustomerIds(): array
    {
        return [self::MANAGER_ID, self::DIRECT_CUSTOMER_ID];
    }

    /**
     * Every customer the fixture can describe (accessible + under the manager).
     *
     * @return array<string, GoogleAdsCustomerDetails>
     */
    public function customers(): array
    {
        return [
            self::MANAGER_ID => new GoogleAdsCustomerDetails(self::MANAGER_ID, 'Agency Manager', 'USD', self::TIME_ZONE, true, false, 'ENABLED'),
            self::DIRECT_CUSTOMER_ID => new GoogleAdsCustomerDetails(self::DIRECT_CUSTOMER_ID, 'Corner Cafe Ads', 'GBP', 'Europe/London', false, false, 'ENABLED'),
            self::CUSTOMER_ID => $this->customerDetails(),
        ];
    }

    /**
     * `customer_client` rows under the manager: itself (level 0), the real
     * account, a second client, a sub-manager, a hidden client and a canceled
     * one (the last two must never become candidates).
     *
     * @return array<int, GoogleAdsManagedClient>
     */
    public function managerClients(): array
    {
        return [
            new GoogleAdsManagedClient(self::MANAGER_ID, 0, true, 'Agency Manager', 'USD', self::TIME_ZONE, 'ENABLED', false, false),
            new GoogleAdsManagedClient(self::CUSTOMER_ID, 1, false, 'Snap Booth Co', self::CURRENCY, self::TIME_ZONE, 'ENABLED', true, false),
            new GoogleAdsManagedClient(self::SECOND_MANAGED_CUSTOMER_ID, 1, false, 'Bay Area Booths', 'CAD', 'America/Toronto', 'ENABLED', false, false),
            new GoogleAdsManagedClient(self::SUB_MANAGER_ID, 1, true, 'Regional Sub-Manager', 'USD', self::TIME_ZONE, 'ENABLED', false, false),
            new GoogleAdsManagedClient(self::HIDDEN_CUSTOMER_ID, 1, false, 'Hidden Client', 'USD', self::TIME_ZONE, 'ENABLED', false, true),
            new GoogleAdsManagedClient(self::CANCELED_CUSTOMER_ID, 1, false, 'Closed Client', 'USD', self::TIME_ZONE, 'CANCELED', false, false),
        ];
    }

    /** @return array<int, GoogleAdsCampaignData> */
    public function campaigns(): array
    {
        $campaigns = [];
        $budgetSeq = 2_000_000_000;

        foreach (self::CAMPAIGNS as $id => $c) {
            $campaigns[] = new GoogleAdsCampaignData(
                externalCampaignId: (string) $id,
                name: $c[0],
                status: GoogleAdsEntityStatus::Enabled,
                channelType: 'SEARCH',
                biddingStrategyType: $c[2],
                budgetExternalId: (string) ++$budgetSeq,
                budgetAmountMicros: $c[1],
                budgetShared: false,
            );
        }

        return $campaigns;
    }

    /** @return array<int, GoogleAdsAdGroupData> */
    public function adGroups(): array
    {
        $groups = [];

        foreach (self::AD_GROUPS as $id => [$campaignId, $name]) {
            $groups[] = new GoogleAdsAdGroupData((string) $id, $campaignId, $name, GoogleAdsEntityStatus::Enabled);
        }

        return $groups;
    }

    /**
     * 18 positive keywords and the negatives.
     *
     * @return array<int, GoogleAdsKeywordData>
     */
    public function keywords(): array
    {
        $keywords = [];
        $criterion = 4_000_000_000;

        foreach (self::KEYWORDS as [$adGroupId, $text, $match, , $quality]) {
            $keywords[] = new GoogleAdsKeywordData(
                externalCriterionId: $adGroupId . '~' . ++$criterion,
                level: GoogleAdsKeywordLevel::AdGroup,
                externalCampaignId: self::AD_GROUPS[$adGroupId][0],
                externalAdGroupId: $adGroupId,
                text: $text,
                matchType: $match,
                status: GoogleAdsEntityStatus::Enabled,
                isNegative: false,
                qualityScore: $quality,
            );
        }

        foreach (self::NEGATIVES as [$level, $parentId, $text, $match]) {
            $isAdGroup = $level === GoogleAdsKeywordLevel::AdGroup;

            $keywords[] = new GoogleAdsKeywordData(
                externalCriterionId: $parentId . '~' . ++$criterion,
                level: $level,
                externalCampaignId: $isAdGroup ? self::AD_GROUPS[$parentId][0] : $parentId,
                externalAdGroupId: $isAdGroup ? $parentId : null,
                text: $text,
                matchType: $match,
                status: GoogleAdsEntityStatus::Enabled,
                isNegative: true,
                qualityScore: null,
            );
        }

        return $keywords;
    }

    /**
     * 62 days x 3 campaigns of campaign-level metrics.
     *
     * @return array<int, GoogleAdsDailyMetricData>
     */
    public function dailyCampaignMetrics(): array
    {
        $rows = [];

        foreach (array_keys(self::CAMPAIGNS) as $campaignId) {
            for ($day = 0; $day < self::METRIC_DAYS; $day++) {
                $rows[] = new GoogleAdsDailyMetricData(
                    GoogleAdsMetricLevel::Campaign,
                    (string) $campaignId,
                    $this->dateForDay($day),
                    $this->campaignDay((string) $campaignId, $day),
                );
            }
        }

        return $rows;
    }

    /**
     * Keyword-level metrics; for every campaign and day the keyword rows sum
     * EXACTLY to the campaign row.
     *
     * @return array<int, GoogleAdsDailyMetricData>
     */
    public function dailyKeywordMetrics(): array
    {
        $positives = [];
        $criterion = 4_000_000_000;

        foreach (self::KEYWORDS as [$adGroupId, , , $weight]) {
            $positives[self::AD_GROUPS[$adGroupId][0]][] = [$adGroupId . '~' . ++$criterion, $weight];
        }

        $rows = [];

        foreach ($positives as $campaignId => $keywords) {
            $weights = array_column($keywords, 1);

            for ($day = 0; $day < self::METRIC_DAYS; $day++) {
                $m = $this->campaignDay((string) $campaignId, $day);
                $impressions = $this->distribute($m->impressions, $weights);
                $clicks = $this->distribute($m->clicks, $weights);
                $cost = $this->distribute($m->costMicros, $weights);

                foreach ($keywords as $i => [$key]) {
                    // Conversions (whole numbers) are credited to the campaign's first keyword.
                    $conversions = $i === 0 ? $m->conversions : '0.000000';
                    $value = $i === 0 ? $m->conversionsValue : '0.000000';

                    $rows[] = new GoogleAdsDailyMetricData(
                        GoogleAdsMetricLevel::Keyword,
                        $key,
                        $this->dateForDay($day),
                        new GoogleAdsMetrics($impressions[$i], $clicks[$i], $clicks[$i], $cost[$i], $conversions, $value),
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * Search terms for the last SEARCH_TERM_DAYS days, rows every other day
     * going back from yesterday.
     *
     * @return array<int, GoogleAdsSearchTermData>
     */
    public function searchTerms(): array
    {
        $rows = [];

        foreach (self::SEARCH_TERMS as [$campaignId, $adGroupId, $term, $impressions, $clicks, $cost, $conversions, $days]) {
            $even = array_fill(0, $days, 1);
            $impressionRows = $this->distribute($impressions, $even);
            $clickRows = $this->distribute($clicks, $even);
            $costRows = $this->distribute($cost, $even);
            $conversionRows = $this->distribute($conversions, $even);

            for ($i = 0; $i < $days; $i++) {
                // Day offsets 1, 3, 5, … days back, all inside the 30-day window.
                $daysBack = 1 + 2 * $i;
                $date = $this->asOf->subDays(min($daysBack, self::SEARCH_TERM_DAYS))->toDateString();
                $converted = $conversionRows[$i];

                $rows[] = new GoogleAdsSearchTermData(
                    externalCampaignId: $campaignId,
                    externalAdGroupId: $adGroupId,
                    searchTerm: $term,
                    date: $date,
                    metrics: new GoogleAdsMetrics(
                        $impressionRows[$i],
                        $clickRows[$i],
                        $clickRows[$i],
                        $costRows[$i],
                        number_format($converted, 6, '.', ''),
                        number_format($converted * 150, 6, '.', ''),
                    ),
                    targetingStatus: GoogleAdsSearchTermStatus::None,
                );
            }
        }

        return $rows;
    }

    /** Y-m-d for day index 0 (62 days back) … 61 (yesterday). */
    private function dateForDay(int $day): string
    {
        return $this->asOf->subDays(self::METRIC_DAYS - $day)->toDateString();
    }

    /**
     * One campaign's metrics for one day, a pure function of (campaign, day).
     */
    private function campaignDay(string $campaignId, int $day): GoogleAdsMetrics
    {
        [, , , $baseClicks, $baseImpressions, $cpc, $modulus, $offset, $perHit] = self::CAMPAIGNS[$campaignId];

        $factor = 60 + (crc32('pbf|clicks|' . $campaignId . '|' . $day) % 81); // 60..140 percent
        $clicks = max(1, intdiv($baseClicks * $factor + 50, 100));
        $impressions = max($clicks, intdiv($baseImpressions * $factor + 50, 100));
        $cpcFactor = 90 + (crc32('pbf|cpc|' . $campaignId . '|' . $day) % 21); // 90..110 percent
        $cost = intdiv($clicks * $cpc * $cpcFactor, 100 * 10_000) * 10_000;

        $conversions = $modulus > 0 && $day % $modulus === $offset ? $perHit : 0;

        return new GoogleAdsMetrics(
            $impressions,
            $clicks,
            $clicks,
            $cost,
            number_format($conversions, 6, '.', ''),
            number_format($conversions * 150, 6, '.', ''),
        );
    }

    /**
     * Splits $total across $weights proportionally with integer arithmetic;
     * the remainder goes to the first slot, so the parts always sum to $total.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function distribute(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $parts = [];
        $assigned = 0;

        foreach ($weights as $i => $weight) {
            $parts[$i] = intdiv($total * $weight, $sum);
            $assigned += $parts[$i];
        }

        $parts[array_key_first($weights)] += $total - $assigned;

        return $parts;
    }
}
