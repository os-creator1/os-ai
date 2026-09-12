<?php

namespace App\Library\Analytics;

use App\DTO\Analytics\BusinessAnalyticsViewModel;
use App\DTO\Analytics\CoverageNotice;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * B5 Business Analytics — read-assembly only (contract §11, §13), modelled
 * on UsageBillingPresenter: no write authority, no transaction, no lock.
 *
 * Caching (§11.3): the overview payload is cached as a plain array under
 * the Business-scoped key `b5_analytics_{business_id}_{rangeKey}` for
 * exactly five minutes. There is no global analytics key, and two
 * Businesses can never share a payload because the Business id is part
 * of the key. The paginated campaign table is not cached (bounded to two
 * grouped aggregates per page, §11.2).
 */
class BusinessAnalyticsPresenter
{
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Shape of the cached payload. Bumped whenever the payload gains a
     * field the page depends on, so an entry written by the previous release
     * is recomputed instead of rendering a missing figure as zero. Version 2
     * added the daily `accepted` series to `message_volume`.
     */
    public const PAYLOAD_VERSION = 2;

    public function __construct(private readonly BusinessAnalyticsQueries $queries)
    {
    }

    public static function cacheKey(Business $business, AnalyticsDateRange $range): string
    {
        return 'b5_analytics_' . (int) $business->id . '_' . $range->cacheKey();
    }

    public function buildOverview(Business $business, AnalyticsDateRange $range): BusinessAnalyticsViewModel
    {
        $key = self::cacheKey($business, $range);
        $build = fn (): array => ['payload_version' => self::PAYLOAD_VERSION] + $this->assemble($business, $range)->toArray();

        $payload = Cache::remember($key, self::CACHE_TTL_SECONDS, $build);

        if (($payload['payload_version'] ?? null) !== self::PAYLOAD_VERSION) {
            $payload = $build();
            Cache::put($key, $payload, self::CACHE_TTL_SECONDS);
        }

        return BusinessAnalyticsViewModel::fromArray($payload);
    }

    /**
     * Contract §12.1 — the bounded chart payload for `/series`: the range
     * and the two daily series, served from the same cached payload.
     *
     * `charts` is the readable form of those same series (adaptive
     * grouping, short axis labels, exact dates for the tooltip). It is
     * derived from the cached daily series in PHP, so it costs no query.
     * The raw daily arrays are kept unchanged beside it.
     *
     * @return array<string, mixed>
     */
    public function buildSeries(Business $business, AnalyticsDateRange $range): array
    {
        $overview = $this->buildOverview($business, $range);

        return [
            'range' => $overview->range,
            'contact_growth' => $overview->contactGrowth->toArray(),
            'message_volume' => $overview->messageVolume->toArray(),
            'charts' => [
                'new_contacts' => AnalyticsChartBuckets::fromDailySeries($overview->contactGrowth, ['new_contacts']),
                'messages' => AnalyticsChartBuckets::fromDailySeries($overview->messageVolume, ['incoming', 'accepted']),
            ],
        ];
    }

    /**
     * @return array{paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator, rows: array<int, \App\DTO\Analytics\CampaignPerformanceRow>}
     */
    public function buildCampaignsPage(Business $business, AnalyticsDateRange $range, int $page): array
    {
        return $this->queries->campaignPerformancePage($business, $range, $page);
    }

    private function assemble(Business $business, AnalyticsDateRange $range): BusinessAnalyticsViewModel
    {
        $messages = $this->queries->messageKpis($business, $range);
        $messageVolume = $this->queries->messageVolumeSeries($business, $range);
        $campaigns = $this->queries->campaignKpis($business, $range);
        $contacts = $this->queries->contactKpis($business, $range);
        $contactGrowth = $this->queries->contactGrowthSeries($business, $range);

        // §2.6 — never queried when the engine is disabled.
        $advisor = config('opportunity.enabled', false) ? $this->queries->advisorKpis($business, $range) : null;

        // §9 — null when the B4-owned table is absent (panel absent).
        $automations = $this->queries->automationKpis($business, $range);

        return new BusinessAnalyticsViewModel(
            [
                'uid' => (string) $business->uid,
                'name' => (string) $business->name,
                'timezone' => (string) ($business->timezone ?: config('app.timezone', 'UTC')),
            ],
            $range->toArray(),
            new CoverageNotice($messages['unattributed'], $contacts['unattributed']),
            $messages['kpis'],
            $messageVolume,
            $campaigns,
            $contacts['kpis'],
            $contactGrowth,
            $advisor,
            $automations,
            CarbonImmutable::now()->toIso8601String(),
        );
    }
}
