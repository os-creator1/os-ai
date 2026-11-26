<?php

namespace App\Library\WebsiteAnalytics;

use App\Models\WebsiteAnalyticsCampaign;
use App\Models\WebsiteAnalyticsEvent;
use App\Models\WebsiteAnalyticsProperty;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE reporting implementation, shared by Agency → White Label → Analytics and Growth → Website → Analytics.
 *
 * One property (resolved by the caller from the signed-in tenant, never from the request), one date range, one landing
 * filter, one campaign filter — and every number on the page (funnel, chart, top pages, sources, recent activity) is computed
 * from the SAME filtered event set, so the totals always agree.
 *
 * Definitions (also shown on the page):
 *  - Estimated visitors: distinct anonymous browser ids (a person on two devices, or after clearing storage, counts twice).
 *  - Visits: distinct visit ids that viewed a page (a visit ends after 30 minutes idle or when the tab's session ends).
 *  - Calendar opens / form opens: distinct visits that had the calendar / form displayed.
 *  - Confirmed bookings / confirmed inquiries: stored records, one per appointment / Forms submission.
 *  - Visit-to-booking rate: confirmed bookings ÷ visits in the same filtered range (the inquiry rate likewise).
 *
 * Dates are the account's timezone. Days before measurement began are reported as "not measured", never as zero traffic.
 */
final class AnalyticsReport
{
    public const RANGES = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    public const DEFAULT_RANGE = '7';

    public const DIRECT = 'direct';

    public function __construct(private readonly AnalyticsCampaignLinks $campaigns) {}

    public static function normalizeRange(?string $range): string
    {
        return array_key_exists((string) $range, self::RANGES) ? (string) $range : self::DEFAULT_RANGE;
    }

    /**
     * @param  ?string  $landing   a landing address of this property, or null for all
     * @param  ?string  $campaign  a WebsiteAnalyticsCampaign token of this property's workspace, 'direct', or null for all
     * @return array<string, mixed>
     */
    public function build(WebsiteAnalyticsProperty $property, string $range, string $timezone, ?string $landing = null, ?string $campaign = null, ?CarbonInterface $now = null): array
    {
        $range = self::normalizeRange($range);
        $tz = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('app.timezone', 'UTC');
        $now = ($now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now))->setTimezone($tz);

        $storage = (string) config('app.timezone', 'UTC');
        $hourly = $range === 'today';
        $days = $hourly ? 1 : (int) $range;
        $start = $now->startOfDay()->subDays($days - 1);
        $end = $now->endOfDay();

        $campaignRow = null;
        $campaignFilter = null;
        if ($campaign === self::DIRECT) {
            $campaignFilter = self::DIRECT;
        } elseif ($campaign !== null && $campaign !== '') {
            $campaignRow = $this->campaigns->resolve($campaign, (int) $property->workspace_id);
            $campaignFilter = $campaignRow?->id;
        }

        $landings = WebsiteAnalyticsEvent::query()->where('property_id', $property->id)->whereNotNull('landing')->distinct()->orderBy('landing')->pluck('landing')->all();
        $landing = $landing !== null && in_array($landing, $landings, true) ? $landing : null;

        $query = WebsiteAnalyticsEvent::query()
            ->where('property_id', $property->id)
            ->whereBetween('occurred_at', [$start->setTimezone($storage)->toDateTimeString(), $end->setTimezone($storage)->toDateTimeString()])
            ->when($landing !== null, fn ($q) => $q->where('landing', $landing))
            ->when($campaignFilter === self::DIRECT, fn ($q) => $q->whereNull('campaign_id')->where(fn ($d) => $d->whereNull('session_id')->orWhereNotIn('session_id', WebsiteAnalyticsEvent::query()
                // "Direct / unknown" is the RESOLVED visit source: a visit that arrived from a campaign or a referring site is not direct,
                // and neither are its calendar opens and confirmed outcomes.
                ->select('session_id')->where('property_id', $property->id)->whereNotNull('session_id')
                ->where(fn ($s) => $s->whereNotNull('campaign_id')->orWhereNotNull('referrer_host')))))
            ->when(is_int($campaignFilter), fn ($q) => $q->where('campaign_id', $campaignFilter))
            ->orderBy('id');

        $campaignNames = WebsiteAnalyticsCampaign::query()->where('workspace_id', $property->workspace_id)->pluck('label', 'id')->all();

        $started = $property->collection_started_at === null ? null : CarbonImmutable::instance($property->collection_started_at)->setTimezone($tz);

        $buckets = [];
        for ($i = 0; $i < ($hourly ? 24 : $days); $i++) {
            $at = $hourly ? $start->addHours($i) : $start->addDays($i);
            $until = $hourly ? $at->addHour() : $at->addDay();
            // A bucket that ended before measurement began was NOT measured: it is not a zero.
            $buckets[$at->format($hourly ? 'Y-m-d H' : 'Y-m-d')] = ['measured' => $started !== null && $until->gt($started), 'label' => $at->format($hourly ? 'ga' : 'M j'), 'start' => $at->toIso8601String(), 'page_views' => 0, 'visits' => [], 'bookings' => 0, 'inquiries' => 0];
        }

        $visitors = $visits = $calendar = $forms = [];
        $pageViews = $bookings = $inquiries = 0;
        $pages = [];
        $sources = [];
        $visitSource = [];

        foreach ($query->cursor() as $event) {
            /** @var WebsiteAnalyticsEvent $event */
            $local = CarbonImmutable::instance($event->occurred_at)->setTimezone($tz);
            $bucketKey = $local->format($hourly ? 'Y-m-d H' : 'Y-m-d');
            $sourceKey = $event->campaign_id !== null ? 'c:' . $event->campaign_id : ($event->referrer_host !== null ? 'r:' . $event->referrer_host : self::DIRECT);
            $session = $event->session_id ?? 'e' . $event->id;

            // A visit's source is the first source it was seen with; outcomes are credited to that visit's source.
            if ($event->kind === 'pageview') {
                $visitSource[$session] ??= $sourceKey;
            }
            $credit = $visitSource[$session] ?? $sourceKey;
            $sources[$credit] ??= ['visits' => [], 'calendar' => [], 'forms' => [], 'bookings' => 0, 'inquiries' => 0];

            switch ($event->kind) {
                case 'pageview':
                    $pageViews++;
                    $visitors[$event->visitor_id ?? $session] = true;
                    $visits[$session] = true;
                    $sources[$credit]['visits'][$session] = true;
                    if (isset($buckets[$bucketKey])) {
                        $buckets[$bucketKey]['page_views']++;
                        $buckets[$bucketKey]['visits'][$session] = true;
                    }
                    $path = (string) ($event->path ?? '/');
                    $pages[$path] ??= ['path' => $path, 'views' => 0, 'visitors' => []];
                    $pages[$path]['views']++;
                    $pages[$path]['visitors'][$event->visitor_id ?? $session] = true;
                    break;
                case 'calendar_open':
                    $calendar[$session] = true;
                    $sources[$credit]['calendar'][$session] = true;
                    break;
                case 'form_open':
                    $forms[$session] = true;
                    $sources[$credit]['forms'][$session] = true;
                    break;
                case 'booking_confirmed':
                    $bookings++;
                    $sources[$credit]['bookings']++;
                    if (isset($buckets[$bucketKey])) {
                        $buckets[$bucketKey]['bookings']++;
                    }
                    break;
                case 'form_inquiry':
                    $inquiries++;
                    $sources[$credit]['inquiries']++;
                    if (isset($buckets[$bucketKey])) {
                        $buckets[$bucketKey]['inquiries']++;
                    }
                    break;
            }
        }

        // Newest first, from the SAME filtered set (never "the last rows written": order is by when it happened).
        $recent = [];
        foreach ((clone $query)->reorder()->orderByDesc('occurred_at')->orderByDesc('id')->limit(25)->get() as $event) {
            $local = CarbonImmutable::instance($event->occurred_at)->setTimezone($tz);
            $recent[] = [
                'at' => $local->toIso8601String(),
                'at_label' => $local->format('M j, g:i a'),
                'kind' => $event->kind,
                'what' => self::KIND_LABELS[$event->kind] ?? $event->kind,
                'path' => $event->path,
                'source' => $this->sourceLabel($event->campaign_id !== null ? 'c:' . $event->campaign_id : ($event->referrer_host !== null ? 'r:' . $event->referrer_host : self::DIRECT), $campaignNames),
                // A short, random, non-identifying code so one anonymous visit can be followed down the list.
                'visitor' => $event->visitor_id === null ? null : substr($event->visitor_id, -4),
            ];
        }

        $visitCount = count($visits);

        $sourceRows = [];
        foreach ($sources as $key => $row) {
            $sourceRows[] = [
                'label' => $this->sourceLabel($key, $campaignNames),
                'kind' => $key === self::DIRECT ? 'direct' : ($key[0] === 'c' ? 'campaign' : 'referral'),
                'visits' => count($row['visits']),
                'calendar_opens' => count($row['calendar']),
                'form_opens' => count($row['forms']),
                'bookings' => $row['bookings'],
                'inquiries' => $row['inquiries'],
            ];
        }
        usort($sourceRows, fn ($a, $b) => [$b['visits'], $b['bookings'], $a['label']] <=> [$a['visits'], $a['bookings'], $b['label']]);

        $topPages = array_map(fn ($p) => ['path' => $p['path'], 'views' => $p['views'], 'visitors' => count($p['visitors'])], array_values($pages));
        usort($topPages, fn ($a, $b) => [$b['views'], $a['path']] <=> [$a['views'], $b['path']]);

        $series = array_values(array_map(fn ($b) => [
            'measured' => $b['measured'], 'label' => $b['label'], 'start' => $b['start'], 'page_views' => $b['page_views'], 'visits' => count($b['visits']),
            'bookings' => $b['bookings'], 'inquiries' => $b['inquiries'],
        ], $buckets));

        return [
            'range' => $range,
            'range_label' => self::RANGES[$range],
            'granularity' => $hourly ? 'hour' : 'day',
            'timezone' => $tz,
            'from' => $start->toIso8601String(),
            'to' => $end->toIso8601String(),
            'from_label' => $start->format('M j, Y'),
            'to_label' => $now->format('M j, Y'),
            'collection_started_at' => $started?->toIso8601String(),
            'collection_started_label' => $started?->format('M j, Y, g:i a'),
            // The range reaches back before measurement began: those days are NOT zero traffic, they were not measured.
            'partially_measured' => $started !== null && $started->gt($start),
            'landings' => $landings,
            'landing' => $landing,
            'campaign' => $campaignFilter === self::DIRECT ? self::DIRECT : $campaignRow?->token,
            'totals' => [
                'visitors' => count($visitors),
                'visits' => $visitCount,
                'page_views' => $pageViews,
                'calendar_opens' => count($calendar),
                'form_opens' => count($forms),
                'bookings' => $bookings,
                'inquiries' => $inquiries,
                'booking_rate' => $visitCount > 0 ? round($bookings / $visitCount * 100, 1) : null,
                'inquiry_rate' => $visitCount > 0 ? round($inquiries / $visitCount * 100, 1) : null,
            ],
            'series' => $series,
            'top_pages' => array_slice($topPages, 0, 10),
            'sources' => array_slice($sourceRows, 0, 12),
            'recent' => $recent,
        ];
    }

    /** @return list<array{token: string, label: string}> campaigns of this property's workspace that have an attribution value */
    public function campaignOptions(WebsiteAnalyticsProperty $property): array
    {
        return WebsiteAnalyticsCampaign::query()->where('workspace_id', $property->workspace_id)->orderBy('label')->get(['token', 'label'])
            ->map(fn ($c) => ['token' => $c->token, 'label' => $c->label])->all();
    }

    public const KIND_LABELS = [
        'pageview' => 'Viewed the page',
        'calendar_open' => 'Opened the calendar',
        'form_open' => 'Opened the form',
        'booking_confirmed' => 'Booked a call',
        'form_inquiry' => 'Sent an inquiry',
    ];

    /** @param array<int|string, string> $campaignNames */
    private function sourceLabel(string $key, array $campaignNames): string
    {
        if ($key === self::DIRECT) {
            return 'Direct / unknown';
        }

        if ($key[0] === 'c') {
            return 'SMS: ' . ($campaignNames[(int) substr($key, 2)] ?? 'Campaign');
        }

        return substr($key, 2);
    }
}
