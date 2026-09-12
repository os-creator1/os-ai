<?php

namespace App\Library\Analytics;

use App\DTO\Analytics\AutomationKpis;
use App\DTO\Analytics\ContactKpis;
use App\DTO\Analytics\MessageKpis;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Customer Experience Slice 4 §4.3 — the Business Home's current-versus-
 * previous headline dataset, and nothing else.
 *
 * Analytics-owned and composing only: it calls three existing B5 query
 * methods — messageKpis(), contactKpis() and automationKpis() — once per
 * range, and writes no KPI formula of its own. It never loads a series, a
 * campaign table or the Advisor figures, and it changes nothing about B5's
 * Results pages (buildOverview(), buildSeries() and buildCampaignsPage()
 * are untouched).
 *
 * THE TWO PERIODS (§4.2, generalized by H-3). Current is the window the
 * customer selected — any Results preset or a custom range of up to 92 local
 * dates — and defaults to This month. Previous is the SAME NUMBER of
 * Business-local calendar dates immediately before it, built through
 * AnalyticsDateRange::fromInput() from local DATES, so its storage-timezone
 * bounds come from the same localDayStartInStorageTz() B5 uses. No fixed
 * 86 400-second offset, and no timezone arithmetic of its own: a 23-hour
 * spring-forward day and a 25-hour fall-back day are each exactly one date,
 * exactly as in Results.
 *
 * CACHE (§4.6). Per Business and per range, B5's own strategy and TTL: the key
 * carries the Business id, the range key and the window's bounds (see
 * cacheKey()), the two periods have distinct keys, and there is no global
 * key. The payload is plain arrays, as in B5, so a cached value is always
 * rebuilt into the B5 DTOs.
 */
final class BusinessDashboardAnalyticsPresenter
{
    public const CACHE_PREFIX = 'b5_dashboard_headlines_';

    /**
     * Home opens on the month the customer is living in (H-3 §2.5). Results
     * keeps its own default; both read the same presets, so a period chosen
     * on either page means the same window on the other.
     */
    public const DEFAULT_PRESET = AnalyticsDateRange::PRESET_THIS_MONTH;

    public function __construct(private readonly BusinessAnalyticsQueries $queries)
    {
    }

    /**
     * The selected window and the equal-length window immediately before it.
     *
     * `$current` is whatever the customer selected — any Results preset or a
     * custom range — and defaults to Home's own default, This month (H-3).
     * The previous window is built from LOCAL CALENDAR DATES: it ends the day
     * before the selected window starts and covers the same number of local
     * dates, so a 23-hour spring-forward day and a 25-hour fall-back day are
     * each still exactly one date, and a month, a year or a custom boundary
     * is crossed by Carbon's own calendar arithmetic rather than by seconds.
     *
     * @return array{current: AnalyticsDateRange, previous: AnalyticsDateRange}
     */
    public static function ranges(string $timezone, ?CarbonImmutable $today = null, ?AnalyticsDateRange $current = null): array
    {
        $current ??= AnalyticsDateRange::preset(self::DEFAULT_PRESET, $timezone, $today);

        $previousEndLocal = $current->startLocal->subDay();
        $previousStartLocal = $previousEndLocal->subDays($current->days() - 1);

        $previous = AnalyticsDateRange::fromInput([
            'range' => AnalyticsDateRange::PRESET_CUSTOM,
            'start' => $previousStartLocal->format('Y-m-d'),
            'end' => $previousEndLocal->format('Y-m-d'),
        ], $timezone, $today);

        return ['current' => $current, 'previous' => $previous];
    }

    /**
     * B5's key shape — Business id, then the range key — followed by the
     * window's exact storage-timezone bounds. The preset's own key
     * (`last_30_days`) names no date, so without the bounds a current window
     * cached just before a Business-local midnight could be paired, for up to
     * the TTL, with the previous window computed just after it.
     */
    public static function cacheKey(Business $business, AnalyticsDateRange $range): string
    {
        return self::CACHE_PREFIX . (int) $business->id
            . '_' . $range->cacheKey()
            . '_' . $range->startUtc->getTimestamp()
            . '_' . $range->endUtc->getTimestamp();
    }

    /**
     * One period's three B5 figures. Automations is null — never zeros — when
     * B5 reports its source absent.
     *
     * @return array{messages: MessageKpis, contacts: ContactKpis, automations: ?AutomationKpis}
     */
    public function period(Business $business, AnalyticsDateRange $range): array
    {
        $payload = Cache::remember(
            self::cacheKey($business, $range),
            BusinessAnalyticsPresenter::CACHE_TTL_SECONDS,
            function () use ($business, $range): array {
                $automations = $this->queries->automationKpis($business, $range);

                return [
                    'messages' => $this->queries->messageKpis($business, $range)['kpis']->toArray(),
                    'contacts' => $this->queries->contactKpis($business, $range)['kpis']->toArray(),
                    'automations' => $automations?->toArray(),
                ];
            },
        );

        return [
            'messages' => MessageKpis::fromArray($payload['messages']),
            'contacts' => ContactKpis::fromArray($payload['contacts']),
            'automations' => $payload['automations'] !== null ? AutomationKpis::fromArray($payload['automations']) : null,
        ];
    }

    /**
     * Both periods, for the Business's own timezone.
     *
     * @return array{
     *     current: array{range: AnalyticsDateRange, messages: MessageKpis, contacts: ContactKpis, automations: ?AutomationKpis},
     *     previous: array{range: AnalyticsDateRange, messages: MessageKpis, contacts: ContactKpis, automations: ?AutomationKpis},
     * }
     */
    public function comparison(Business $business, ?AnalyticsDateRange $current = null, ?CarbonImmutable $today = null): array
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $ranges = self::ranges($timezone, $today, $current);

        return [
            'current' => ['range' => $ranges['current']] + $this->period($business, $ranges['current']),
            'previous' => ['range' => $ranges['previous']] + $this->period($business, $ranges['previous']),
        ];
    }
}
