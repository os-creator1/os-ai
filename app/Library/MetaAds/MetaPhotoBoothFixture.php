<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaAdsAccountCandidate;
use App\DTO\MetaAds\MetaAdsAdData;
use App\DTO\MetaAds\MetaAdsAdSetData;
use App\DTO\MetaAds\MetaAdsCampaignData;
use App\DTO\MetaAds\MetaFrequencyRow;
use App\DTO\MetaAds\MetaInsightRow;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract §14 — a DETERMINISTIC, fully synthetic Meta
 * ad account for tests and browser acceptance: "Photo Booth Co", a photo-booth
 * rental business spending roughly $15 a day in USD across three campaigns.
 * Served by FakeMetaClient (driver `fake`, refused in production); never real
 * customer data.
 *
 * DETERMINISM. Nothing here uses rand()/mt_rand() or the clock: every figure
 * is a pure function of (entity, day index) via crc32 of a fixed label, and
 * dates hang off the `$asOf` argument ("today" in the account time zone). The
 * same `$asOf` always yields byte-identical data on any machine. The figures
 * themselves do NOT depend on `$asOf` (only the date labels do), so the
 * EXPECTED_* totals below hold for any `$asOf`.
 *
 * SHAPE
 *   - Ad accounts the owner is offered: an ACTIVE USD account (the working
 *     one), a DISABLED account and an ACTIVE EUR account. FOREIGN_AD_ACCOUNT_ID
 *     belongs to someone else and is NEVER offered.
 *   - 3 campaigns: "Leads - Local Events" (leads regularly), "Wedding Promo"
 *     (spends, ZERO lead actions) and "Brand Awareness" (paused, NO insight
 *     rows at all -> missing metrics are null, not zero).
 *   - 5 ad sets, 8 ads. One ad carries a creative thumbnail on an allowed
 *     host; one ACTIVE ad is WITH_ISSUES; one ACTIVE ad is DISAPPROVED.
 *   - 62 days of daily insights ending the day before `$asOf`, at ad level
 *     (the base), summed EXACTLY into ad-set and campaign rows. Rows carry
 *     typed actions INCLUDING types outside config('meta_ads.result_types');
 *     the Fake filters them exactly like the real client does.
 *   - The first ad set's leads stop in the final 7 days while it still spends
 *     (creative fatigue: high frequency + collapsing results).
 *   - Trailing-7-day reach / frequency per ad set.
 */
final class MetaPhotoBoothFixture
{
    public const BUSINESS_NAME = 'Photo Booth Co';

    public const META_USER_ID = '10001000100010001';

    public const META_USER_NAME = 'Pat Booth';

    public const AD_ACCOUNT_ID = '1234567890123456';

    public const DISABLED_AD_ACCOUNT_ID = '2234567890123456';

    public const EUR_AD_ACCOUNT_ID = '3234567890123456';

    /** Never offered by listAdAccounts(); reads / writes against it are access_denied. */
    public const FOREIGN_AD_ACCOUNT_ID = '9999999999999999';

    public const CURRENCY = 'USD';

    public const TIME_ZONE = 'America/New_York';

    public const MONTHLY_BUDGET_TARGET_MICROS = 300_000_000;

    public const TARGET_COST_PER_RESULT_MICROS = 20_000_000;

    public const METRIC_DAYS = 62;

    public const FREQUENCY_DAYS = 7;

    public const CAMPAIGN_LEADS = '6000000000000001';

    public const CAMPAIGN_WEDDING = '6000000000000002';

    public const CAMPAIGN_AWARENESS = '6000000000000003';

    public const AD_SET_FATIGUED = '6100000000000001';

    public const AD_SET_LOOKALIKE = '6100000000000002';

    public const AD_SET_WEDDING_ENGAGED = '6100000000000003';

    public const AD_SET_WEDDING_RETARGET = '6100000000000004';

    public const AD_SET_AWARENESS = '6100000000000005';

    public const AD_WITH_THUMBNAIL = '6200000000000001';

    public const AD_WITH_ISSUES = '6200000000000005';

    public const AD_DISAPPROVED = '6200000000000006';

    public const THUMBNAIL_URL = 'https://scontent.xx.fbcdn.net/v/t45.1600-4/photo-booth-carousel.jpg';

    /** Allowed result types a typical Meta response contains. */
    public const ALLOW_LISTED_TYPES = ['lead', 'link_click', 'onsite_conversion.lead_grouped'];

    /** Typed actions the fixture emits that are NOT in the allow-list. */
    public const OUT_OF_ALLOW_LIST_TYPES = ['landing_page_view', 'page_engagement', 'post_engagement'];

    /** Meta-reported value of one `lead`, in micros. */
    public const LEAD_VALUE_MICROS = 40_000_000;

    /*
     * Pinned expected totals over all 62 days (campaign level). Verified by
     * MetaAds FakeMetaClientTest against the generator, so a change to the
     * generator that moves them fails loudly.
     */
    public const EXPECTED_LEADS_CAMPAIGN_SPEND_MICROS = 604_714_000;

    public const EXPECTED_LEADS_CAMPAIGN_IMPRESSIONS = 151_109;

    public const EXPECTED_LEADS_CAMPAIGN_CLICKS = 5_955;

    public const EXPECTED_LEADS_CAMPAIGN_LINK_CLICKS = 4_085;

    /** `lead` actions (and the same count of `onsite_conversion.lead_grouped`). */
    public const EXPECTED_LEADS_CAMPAIGN_LEADS = 145;

    public const EXPECTED_LEADS_CAMPAIGN_LEAD_VALUE_MICROS = 5_800_000_000;

    /** The Wedding campaign spends and has ZERO `lead` actions. */
    public const EXPECTED_WEDDING_CAMPAIGN_SPEND_MICROS = 316_520_000;

    public const EXPECTED_WEDDING_CAMPAIGN_LINK_CLICKS = 2_083;

    /** Insight row counts over the 62 days: campaign / ad set / ad level. */
    public const EXPECTED_ROW_COUNTS = ['campaign' => 124, 'ad_set' => 226, 'ad' => 350];

    /**
     * ad id => [ad set id, name, status, effective status, base spend micros/day,
     *           lead day modulus (0 = never), leads until day index (exclusive),
     *           insight days (rows exist for day index < this; 0 = none)].
     *
     * @var array<string, array{0:string, 1:string, 2:string, 3:string, 4:int, 5:int, 6:int, 7:int}>
     */
    private const ADS = [
        '6200000000000001' => [self::AD_SET_FATIGUED, 'Local Events - Carousel', 'ACTIVE', 'ACTIVE', 3_000_000, 2, 55, 62],
        '6200000000000002' => [self::AD_SET_FATIGUED, 'Local Events - Video', 'ACTIVE', 'ACTIVE', 2_500_000, 3, 55, 62],
        '6200000000000003' => [self::AD_SET_LOOKALIKE, 'Lookalike - Single Image', 'ACTIVE', 'ACTIVE', 3_500_000, 2, 62, 62],
        '6200000000000004' => [self::AD_SET_WEDDING_ENGAGED, 'Wedding - Engaged Couples', 'ACTIVE', 'ACTIVE', 2_000_000, 0, 0, 62],
        '6200000000000005' => [self::AD_SET_WEDDING_ENGAGED, 'Wedding - Testimonial', 'ACTIVE', 'WITH_ISSUES', 1_500_000, 0, 0, 62],
        '6200000000000006' => [self::AD_SET_WEDDING_RETARGET, 'Wedding - Retarget Offer', 'ACTIVE', 'DISAPPROVED', 1_500_000, 0, 0, 40],
        '6200000000000007' => [self::AD_SET_AWARENESS, 'Awareness - Reel', 'PAUSED', 'PAUSED', 0, 0, 0, 0],
        '6200000000000008' => [self::AD_SET_AWARENESS, 'Awareness - Static', 'ACTIVE', 'ADSET_PAUSED', 0, 0, 0, 0],
    ];

    /**
     * ad set id => [campaign id, name, status, effective status, daily budget minor|null,
     *               optimization goal, bid strategy, targeting summary, reach 7d, frequency 7d|null].
     *
     * @var array<string, array{0:string, 1:string, 2:string, 3:string, 4:?int, 5:string, 6:string, 7:string, 8:?int, 9:?float}>
     */
    private const AD_SETS = [
        self::AD_SET_FATIGUED => [self::CAMPAIGN_LEADS, 'Local - Women 25-54', 'ACTIVE', 'ACTIVE', 600, 'LEAD_GENERATION', 'LOWEST_COST_WITHOUT_CAP', 'Ages 25–54 · Women · US', 4200, 3.4],
        self::AD_SET_LOOKALIKE => [self::CAMPAIGN_LEADS, 'Local - Lookalike', 'ACTIVE', 'ACTIVE', 400, 'LEAD_GENERATION', 'LOWEST_COST_WITHOUT_CAP', 'Ages 21–65+ · US, Texas', 6100, 1.8],
        self::AD_SET_WEDDING_ENGAGED => [self::CAMPAIGN_WEDDING, 'Wedding - Engaged', 'ACTIVE', 'ACTIVE', 450, 'LINK_CLICKS', 'LOWEST_COST_WITHOUT_CAP', 'Ages 24–40 · US', 3900, 2.1],
        self::AD_SET_WEDDING_RETARGET => [self::CAMPAIGN_WEDDING, 'Wedding - Retarget', 'ACTIVE', 'ACTIVE', 250, 'LINK_CLICKS', 'LOWEST_COST_WITHOUT_CAP', 'Ages 18–65+ · Texas', 1200, 3.6],
        self::AD_SET_AWARENESS => [self::CAMPAIGN_AWARENESS, 'Awareness - Broad', 'PAUSED', 'CAMPAIGN_PAUSED', null, 'REACH', 'LOWEST_COST_WITHOUT_CAP', 'Ages 18–65+ · US', null, null],
    ];

    /**
     * campaign id => [name, status, effective status, objective, daily budget minor|null,
     *                 lifetime budget minor|null, budget remaining minor|null, started days before asOf].
     *
     * @var array<string, array{0:string, 1:string, 2:string, 3:string, 4:?int, 5:?int, 6:?int, 7:int}>
     */
    private const CAMPAIGNS = [
        self::CAMPAIGN_LEADS => ['Leads - Local Events', 'ACTIVE', 'ACTIVE', 'OUTCOME_LEADS', 1000, null, null, 120],
        self::CAMPAIGN_WEDDING => ['Wedding Promo', 'ACTIVE', 'ACTIVE', 'OUTCOME_TRAFFIC', 700, null, null, 90],
        self::CAMPAIGN_AWARENESS => ['Brand Awareness', 'PAUSED', 'PAUSED', 'OUTCOME_AWARENESS', null, 50000, 30000, 200],
    ];

    /** @return array<int, MetaAdsAccountCandidate> */
    public function adAccounts(): array
    {
        return [
            new MetaAdsAccountCandidate(self::AD_ACCOUNT_ID, 'Photo Booth Co - Ads', self::CURRENCY, self::TIME_ZONE, 1),
            new MetaAdsAccountCandidate(self::DISABLED_AD_ACCOUNT_ID, 'Photo Booth Co - Old Account', self::CURRENCY, self::TIME_ZONE, 2),
            new MetaAdsAccountCandidate(self::EUR_AD_ACCOUNT_ID, 'Photo Booth Co - Europe', 'EUR', 'Europe/Berlin', 1),
        ];
    }

    /** @return array<int, MetaAdsCampaignData> */
    public function campaigns(?CarbonImmutable $asOf = null): array
    {
        $asOf = $this->asOf($asOf);
        $rows = [];

        foreach (self::CAMPAIGNS as $id => [$name, $status, $effective, $objective, $daily, $lifetime, $remaining, $startedDaysAgo]) {
            $rows[] = new MetaAdsCampaignData($id, $name, $status, $effective, $objective, $daily, $lifetime, $remaining, $asOf->subDays($startedDaysAgo)->startOfDay(), null);
        }

        return $rows;
    }

    /** @return array<int, MetaAdsAdSetData> */
    public function adSets(): array
    {
        $rows = [];

        foreach (self::AD_SETS as $id => [$campaign, $name, $status, $effective, $daily, $goal, $bid, $targeting]) {
            $rows[] = new MetaAdsAdSetData($id, $campaign, $name, $status, $effective, $daily, null, $goal, $bid, $targeting);
        }

        return $rows;
    }

    /** @return array<int, MetaAdsAdData> */
    public function ads(): array
    {
        $rows = [];

        foreach (self::ADS as $id => [$adSet, $name, $status, $effective]) {
            $id = (string) $id; // numeric-string array keys are ints
            $withThumb = $id === self::AD_WITH_THUMBNAIL;

            $rows[] = new MetaAdsAdData(
                $id,
                self::AD_SETS[$adSet][0],
                $adSet,
                $name,
                $status,
                $effective,
                'Book your photo booth today',
                'Make your next event unforgettable with ' . self::BUSINESS_NAME . '.',
                $withThumb ? self::THUMBNAIL_URL : null,
                $withThumb ? 'SHARE' : 'VIDEO',
            );
        }

        return $rows;
    }

    /**
     * Daily insight rows at one level, `results` UNFILTERED (typed actions
     * outside the allow-list included).
     *
     * @param  'campaign'|'ad_set'|'ad'  $level
     * @return array<int, MetaInsightRow>
     */
    public function insights(string $level, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $this->asOf($asOf);

        /** @var array<string, array<int, array<string, mixed>>> $groups level id => day => accumulated */
        $groups = [];

        foreach (self::ADS as $adId => $ad) {
            $adSetId = $ad[0];
            $campaignId = self::AD_SETS[$adSetId][0];

            for ($day = 0; $day < $ad[7]; $day++) {
                $metrics = $this->adDay($adId, $day);
                $key = match ($level) {
                    'ad' => $adId,
                    'ad_set' => $adSetId,
                    default => $campaignId,
                };

                $groups[$key][$day] = isset($groups[$key][$day])
                    ? $this->add($groups[$key][$day], $metrics)
                    : $metrics;
            }
        }

        $rows = [];

        foreach ($groups as $id => $days) {
            foreach ($days as $day => $m) {
                $rows[] = new MetaInsightRow(
                    $level,
                    (string) $id,
                    $this->dayDate($asOf, $day),
                    $m['spend'],
                    $m['impressions'],
                    $m['clicks'],
                    $m['link_clicks'],
                    $m['results'],
                );
            }
        }

        usort($rows, static fn (MetaInsightRow $a, MetaInsightRow $b): int => [$a->date, $a->externalId] <=> [$b->date, $b->externalId]);

        return $rows;
    }

    /** @return array<int, MetaFrequencyRow> */
    public function frequencyRows(?CarbonImmutable $asOf = null): array
    {
        $asOf = $this->asOf($asOf);
        $rows = [];

        foreach (self::AD_SETS as $id => $adSet) {
            if ($adSet[8] === null) {
                continue;
            }

            $rows[] = new MetaFrequencyRow(
                $id,
                $adSet[8],
                $adSet[9],
                $asOf->subDays(self::FREQUENCY_DAYS)->format('Y-m-d'),
                $asOf->subDay()->format('Y-m-d'),
            );
        }

        return $rows;
    }

    /**
     * Sum of one campaign's rows over every day (spend micros, impressions,
     * clicks, link clicks, results by type: count + value micros).
     *
     * @return array{spend: int, impressions: int, clicks: int, link_clicks: int, results: array<string, array{count: int, value: ?int}>}
     */
    public function campaignTotals(string $campaignId): array
    {
        $total = ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'link_clicks' => 0, 'results' => []];

        foreach ($this->insights('campaign') as $row) {
            if ($row->externalId !== $campaignId) {
                continue;
            }

            $total = $this->add($total, [
                'spend' => (int) $row->spendMicros,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'link_clicks' => (int) $row->linkClicks,
                'results' => $row->results,
            ]);
        }

        return $total;
    }

    public function dayDate(CarbonImmutable $asOf, int $dayIndex): string
    {
        // Day index 0 is the OLDEST of the 62 days; the newest ends the day before $asOf.
        return $asOf->subDays(self::METRIC_DAYS - $dayIndex)->format('Y-m-d');
    }

    /**
     * One ad's metrics for one day index: a pure function of (ad, day).
     *
     * @return array{spend: int, impressions: int, clicks: int, link_clicks: int, results: array<string, array{count: int, value: ?int}>}
     */
    private function adDay(string $adId, int $day): array
    {
        [, , , , $base, $leadModulus, $leadsUntil] = self::ADS[$adId];

        $spend = $base + (crc32('spend:' . $adId . ':' . $day) % 500) * 1000;
        $impressions = intdiv($spend, 4000);
        $clicks = intdiv($impressions, 25);
        $linkClicks = intdiv($clicks * 7, 10);

        $results = [];

        if ($linkClicks > 0) {
            $results['link_click'] = ['count' => $linkClicks, 'value' => null];
            $results['landing_page_view'] = ['count' => intdiv($linkClicks * 6, 10), 'value' => null];
        }

        $results['page_engagement'] = ['count' => $clicks + 3, 'value' => null];
        $results['post_engagement'] = ['count' => $clicks + 5, 'value' => null];

        if ($leadModulus > 0 && $day < $leadsUntil && crc32('lead:' . $adId . ':' . $day) % $leadModulus === 0) {
            $leads = 1 + (crc32('n:' . $adId . ':' . $day) % 2);
            $results['lead'] = ['count' => $leads, 'value' => $leads * self::LEAD_VALUE_MICROS];
            $results['onsite_conversion.lead_grouped'] = ['count' => $leads, 'value' => null];
        }

        ksort($results);

        return [
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'link_clicks' => $linkClicks,
            'results' => $results,
        ];
    }

    /**
     * @param  array{spend: int, impressions: int, clicks: int, link_clicks: int, results: array<string, array{count: int, value: ?int}>}  $a
     * @param  array{spend: int, impressions: int, clicks: int, link_clicks: int, results: array<string, array{count: int, value: ?int}>}  $b
     * @return array{spend: int, impressions: int, clicks: int, link_clicks: int, results: array<string, array{count: int, value: ?int}>}
     */
    private function add(array $a, array $b): array
    {
        $results = $a['results'];

        foreach ($b['results'] as $type => $r) {
            if (! isset($results[$type])) {
                $results[$type] = $r;

                continue;
            }

            $results[$type] = [
                'count' => $results[$type]['count'] + $r['count'],
                'value' => $results[$type]['value'] === null && $r['value'] === null
                    ? null
                    : (int) $results[$type]['value'] + (int) $r['value'],
            ];
        }

        ksort($results);

        return [
            'spend' => $a['spend'] + $b['spend'],
            'impressions' => $a['impressions'] + $b['impressions'],
            'clicks' => $a['clicks'] + $b['clicks'],
            'link_clicks' => $a['link_clicks'] + $b['link_clicks'],
            'results' => $results,
        ];
    }

    private function asOf(?CarbonImmutable $asOf): CarbonImmutable
    {
        return ($asOf ?? CarbonImmutable::now(self::TIME_ZONE))->setTimezone(self::TIME_ZONE)->startOfDay();
    }
}
