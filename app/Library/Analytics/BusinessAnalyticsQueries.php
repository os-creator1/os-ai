<?php

namespace App\Library\Analytics;

use App\DTO\Analytics\AdvisorKpis;
use App\DTO\Analytics\AutomationKpis;
use App\DTO\Analytics\CampaignKpis;
use App\DTO\Analytics\CampaignPerformanceRow;
use App\DTO\Analytics\ContactKpis;
use App\DTO\Analytics\CoverageNotice;
use App\DTO\Analytics\DailySeries;
use App\DTO\Analytics\MessageKpis;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * B5 Business Analytics — the batched, Business-scoped KPI reads
 * (contract §5–§9, §11.2). Every query carries `business_id = :b`; there
 * is no `OR business_id IS NULL`, no user_id fallback, no resolver, no
 * cross-Business aggregation. Range filters are the explicit half-open
 * storage-timezone interval from AnalyticsDateRange — never whereDate(),
 * DATE(), DAY(), a date-only whereBetween or CONVERT_TZ().
 *
 * Authorized sources only: reports, tracking_logs, campaigns, contacts,
 * contact_groups, opportunities, opportunity_runs, automation_executions
 * (read-only, B4-owned). Nothing here touches chat_boxes, agency_prospect*,
 * business_usage_*, invoices, subscriptions or payment tables.
 *
 * Query budget (§11.2): the overview costs at most ONE query per method
 * below — M1–M6 (+ the message coverage count) 1, M7 1, C1+C2 1,
 * K1+K2+K4+K5 (+ the contact coverage count) 1, K3 1, O1–O4 1, A1–A4 1.
 * The campaign table costs exactly two grouped aggregates over the page's
 * campaign ids, never one per row.
 */
class BusinessAnalyticsQueries
{
    /**
     * Contract §5 M4 — provider acceptance. Exact match OR the
     * `Delivered|<message id>` shape the sender writes; NEVER
     * `LIKE '%Delivered%'`, which also matches `Undelivered`.
     */
    public const ACCEPTED_SQL = "(customer_status = 'Delivered' OR customer_status LIKE 'Delivered|%')";

    /** Contract §5 M5 — the terminal non-acceptance vocabulary, exactly. */
    public const CONFIRMED_FAILURE_STATUSES = ['Undelivered', 'Expired', 'Rejected', 'Failed', 'Skipped'];

    public const CONFIRMED_FAILED_SQL = "customer_status IN ('Undelivered', 'Expired', 'Rejected', 'Failed', 'Skipped')";

    public const CAMPAIGN_PAGE_SIZE = 25;

    /**
     * M1–M6 plus the message half of the coverage notice, one query.
     *
     * @return array{kpis: MessageKpis, unattributed: int}
     */
    public function messageKpis(Business $business, AnalyticsDateRange $range): array
    {
        $row = DB::table('reports')
            ->selectRaw(
                "SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) AS outbound,"
                . " SUM(CASE WHEN direction = 'api' THEN 1 ELSE 0 END) AS api,"
                . " SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) AS inbound,"
                . " SUM(CASE WHEN direction = 'outgoing' AND " . self::ACCEPTED_SQL . " THEN 1 ELSE 0 END) AS accepted,"
                . " SUM(CASE WHEN direction = 'outgoing' AND " . self::CONFIRMED_FAILED_SQL . " THEN 1 ELSE 0 END) AS confirmed_failed,"
                . ' (SELECT COUNT(*) FROM ' . $this->table('reports') . ' AS legacy WHERE legacy.user_id = ? AND legacy.business_id IS NULL) AS unattributed',
                [(int) $business->customer_id]
            )
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $this->ts($range->startUtc))
            ->where('created_at', '<', $this->ts($range->endUtc))
            ->first();

        return [
            'kpis' => new MessageKpis(
                (int) ($row->outbound ?? 0),
                (int) ($row->api ?? 0),
                (int) ($row->inbound ?? 0),
                (int) ($row->accepted ?? 0),
                (int) ($row->confirmed_failed ?? 0),
            ),
            'unattributed' => (int) ($row->unattributed ?? 0),
        ];
    }

    /**
     * M7 — daily volume split by direction, plus the M4 subset of each
     * day's outgoing messages, in ONE query.
     *
     * `accepted` is the same predicate messageKpis() uses for M4
     * (ACCEPTED_SQL, restricted to `direction = 'outgoing'`), bucketed by
     * the same local date, so the daily values sum to the overview's M4
     * figure exactly. It exists so a chart labelled "Sent" charts the very
     * number the page calls "Sent", rather than every outgoing attempt.
     * `outgoing`, `incoming` and `api` are computed exactly as before.
     */
    public function messageVolumeSeries(Business $business, AnalyticsDateRange $range): DailySeries
    {
        [$bucketSql, $bucketBindings] = $this->bucketExpression($range);

        $rows = DB::table('reports')
            ->selectRaw(
                $bucketSql . ' AS bucket, direction, COUNT(*) AS c,'
                . " SUM(CASE WHEN direction = 'outgoing' AND " . self::ACCEPTED_SQL . ' THEN 1 ELSE 0 END) AS accepted',
                $bucketBindings
            )
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $this->ts($range->startUtc))
            ->where('created_at', '<', $this->ts($range->endUtc))
            ->whereIn('direction', ['outgoing', 'incoming', 'api'])
            ->groupBy('bucket', 'direction')
            ->get();

        $dates = array_column($range->dailyBuckets(), 'date');
        $zeros = array_fill(0, count($dates), 0);
        $series = ['outgoing' => $zeros, 'incoming' => $zeros, 'api' => $zeros, 'accepted' => $zeros];
        $index = array_flip($dates);

        foreach ($rows as $row) {
            if (! isset($index[$row->bucket])) {
                continue;
            }

            if (isset($series[$row->direction])) {
                $series[$row->direction][$index[$row->bucket]] = (int) $row->c;
            }

            if ($row->direction === 'outgoing') {
                $series['accepted'][$index[$row->bucket]] = (int) $row->accepted;
            }
        }

        return new DailySeries($dates, $series);
    }

    /** C1 + C2, one query. */
    public function campaignKpis(Business $business, AnalyticsDateRange $range): CampaignKpis
    {
        $rows = DB::table('campaigns')
            ->selectRaw('status, COUNT(*) AS total, SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS in_range', [$this->ts($range->startUtc), $this->ts($range->endUtc)])
            ->where('business_id', $business->id)
            ->groupBy('status')
            ->get();

        $snapshot = [];
        $createdInRange = 0;

        foreach ($rows as $row) {
            $snapshot[(string) ($row->status ?? 'unknown')] = (int) $row->total;
            $createdInRange += (int) $row->in_range;
        }

        ksort($snapshot);

        return new CampaignKpis($createdInRange, $snapshot);
    }

    /**
     * K1 + K2 + K4 + K5 plus the contact half of the coverage notice, one
     * aggregate query that always yields exactly one row.
     *
     * @return array{kpis: ContactKpis, unattributed: int}
     */
    public function contactKpis(Business $business, AnalyticsDateRange $range): array
    {
        $row = DB::table('contacts')
            ->selectRaw(
                'COUNT(*) AS total_now,'
                . " SUM(CASE WHEN status = 'subscribe' THEN 1 ELSE 0 END) AS subscribed_now,"
                . " SUM(CASE WHEN status = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribed_now,"
                . ' SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS new_in_range,'
                . ' (SELECT COUNT(*) FROM ' . $this->table('contact_groups') . ' AS g WHERE g.business_id = ?) AS group_count,'
                . ' (SELECT COUNT(*) FROM ' . $this->table('contacts') . ' AS legacy WHERE legacy.customer_id = ? AND legacy.business_id IS NULL) AS unattributed',
                [$this->ts($range->startUtc), $this->ts($range->endUtc), (int) $business->id, (int) $business->customer_id]
            )
            ->where('business_id', $business->id)
            ->first();

        return [
            'kpis' => new ContactKpis(
                (int) ($row->total_now ?? 0),
                (int) ($row->new_in_range ?? 0),
                (int) ($row->subscribed_now ?? 0),
                (int) ($row->unsubscribed_now ?? 0),
                (int) ($row->group_count ?? 0),
            ),
            'unattributed' => (int) ($row->unattributed ?? 0),
        ];
    }

    /** K3 — new contacts per local date, one query. */
    public function contactGrowthSeries(Business $business, AnalyticsDateRange $range): DailySeries
    {
        [$bucketSql, $bucketBindings] = $this->bucketExpression($range);

        $rows = DB::table('contacts')
            ->selectRaw($bucketSql . ' AS bucket, COUNT(*) AS c', $bucketBindings)
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $this->ts($range->startUtc))
            ->where('created_at', '<', $this->ts($range->endUtc))
            ->groupBy('bucket')
            ->get();

        $dates = array_column($range->dailyBuckets(), 'date');
        $values = array_fill(0, count($dates), 0);
        $index = array_flip($dates);

        foreach ($rows as $row) {
            if (isset($index[$row->bucket])) {
                $values[$index[$row->bucket]] = (int) $row->c;
            }
        }

        return new DailySeries($dates, ['new_contacts' => $values]);
    }

    /** O1–O4, one query (O4 as a scalar subquery over opportunity_runs). */
    public function advisorKpis(Business $business, AnalyticsDateRange $range): AdvisorKpis
    {
        $start = $this->ts($range->startUtc);
        $end = $this->ts($range->endUtc);

        $row = DB::table('opportunities')
            ->selectRaw(
                "SUM(CASE WHEN status = 'open' AND freshness = 'current' THEN 1 ELSE 0 END) AS open_current,"
                . ' SUM(CASE WHEN completed_at >= ? AND completed_at < ? THEN 1 ELSE 0 END) AS completed_in_range,'
                . ' SUM(CASE WHEN dismissed_at >= ? AND dismissed_at < ? THEN 1 ELSE 0 END) AS dismissed_in_range,'
                . ' (SELECT MAX(r.completed_at) FROM ' . $this->table('opportunity_runs') . " AS r WHERE r.business_id = ? AND r.status = 'succeeded') AS last_successful_run_at",
                [$start, $end, $start, $end, (int) $business->id]
            )
            ->where('business_id', $business->id)
            ->first();

        $lastRun = $row->last_successful_run_at ?? null;

        return new AdvisorKpis(
            (int) ($row->open_current ?? 0),
            (int) ($row->completed_in_range ?? 0),
            (int) ($row->dismissed_in_range ?? 0),
            $lastRun !== null ? CarbonImmutable::parse((string) $lastRun, config('app.timezone', 'UTC'))->toIso8601String() : null,
        );
    }

    /**
     * A1–A4, one query. Returns null — panel ABSENT, never zeros — when
     * the B4-owned table does not exist (MySQL error 1146). Any other
     * database error propagates.
     */
    public function automationKpis(Business $business, AnalyticsDateRange $range): ?AutomationKpis
    {
        try {
            $rows = DB::table('automation_executions')
                ->selectRaw('status, trigger_type, COUNT(*) AS c')
                ->where('business_id', $business->id)
                ->where('created_at', '>=', $this->ts($range->startUtc))
                ->where('created_at', '<', $this->ts($range->endUtc))
                ->groupBy('status', 'trigger_type')
                ->get();
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1146) {
                return null;
            }

            throw $exception;
        }

        $byStatus = [];
        $byTrigger = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->c;
            $total += $count;
            $byStatus[(string) $row->status] = ($byStatus[(string) $row->status] ?? 0) + $count;
            $byTrigger[(string) $row->trigger_type] = ($byTrigger[(string) $row->trigger_type] ?? 0) + $count;
        }

        ksort($byStatus);
        ksort($byTrigger);

        return new AutomationKpis($total, $byStatus, $byTrigger);
    }

    /**
     * C3 — one page of campaigns created in range (most recent first),
     * then exactly two grouped aggregates over that page's ids, both
     * additionally constrained by business_id (§6, §11.2).
     *
     * @return array{paginator: LengthAwarePaginator, rows: array<int, CampaignPerformanceRow>}
     */
    public function campaignPerformancePage(Business $business, AnalyticsDateRange $range, int $page): array
    {
        $paginator = DB::table('campaigns')
            ->select(['id', 'uid', 'campaign_name', 'status', 'created_at'])
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $this->ts($range->startUtc))
            ->where('created_at', '<', $this->ts($range->endUtc))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::CAMPAIGN_PAGE_SIZE, ['*'], 'page', max(1, $page));

        $ids = array_map(fn ($campaign) => (int) $campaign->id, $paginator->items());
        $reportAggregates = [];
        $targetAggregates = [];

        if ($ids !== []) {
            $reportAggregates = DB::table('reports')
                ->selectRaw(
                    'campaign_id, COUNT(*) AS attempted,'
                    . " SUM(CASE WHEN direction = 'outgoing' AND " . self::ACCEPTED_SQL . ' THEN 1 ELSE 0 END) AS accepted,'
                    . " SUM(CASE WHEN direction = 'outgoing' AND " . self::CONFIRMED_FAILED_SQL . ' THEN 1 ELSE 0 END) AS confirmed_failed'
                )
                ->where('business_id', $business->id)
                ->whereIn('campaign_id', $ids)
                ->groupBy('campaign_id')
                ->get()
                ->keyBy('campaign_id');

            $targetAggregates = DB::table('tracking_logs')
                ->selectRaw('campaign_id, COUNT(DISTINCT contact_id) AS contacts_targeted')
                ->where('business_id', $business->id)
                ->whereIn('campaign_id', $ids)
                ->groupBy('campaign_id')
                ->get()
                ->keyBy('campaign_id');
        }

        $timezone = $business->timezone ?: config('app.timezone', 'UTC');
        $rows = [];

        foreach ($paginator->items() as $campaign) {
            $report = $reportAggregates[$campaign->id] ?? null;
            $target = $targetAggregates[$campaign->id] ?? null;

            $rows[] = new CampaignPerformanceRow(
                (int) $campaign->id,
                (string) $campaign->uid,
                (string) ($campaign->campaign_name ?? ''),
                $campaign->status !== null ? (string) $campaign->status : null,
                CarbonImmutable::parse((string) $campaign->created_at, config('app.timezone', 'UTC'))->setTimezone($timezone)->format('Y-m-d H:i'),
                (int) ($report->attempted ?? 0),
                (int) ($report->accepted ?? 0),
                (int) ($report->confirmed_failed ?? 0),
                (int) ($target->contacts_targeted ?? 0),
            );
        }

        return ['paginator' => $paginator, 'rows' => $rows];
    }

    /**
     * Contract §4.5 — the daily bucket expression. Each WHEN carries the
     * end boundary of one local date computed independently in PHP; rows
     * are already constrained to the range, so the first satisfied WHEN
     * is the row's local date. No DATE(), DAY() or CONVERT_TZ().
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function bucketExpression(AnalyticsDateRange $range): array
    {
        $sql = 'CASE';
        $bindings = [];

        foreach ($range->dailyBuckets() as $bucket) {
            $sql .= ' WHEN created_at < ? THEN ?';
            $bindings[] = $this->ts($bucket['end']);
            $bindings[] = $bucket['date'];
        }

        $sql .= ' END';

        return [$sql, $bindings];
    }

    private function ts(CarbonImmutable $instant): string
    {
        return $instant->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function table(string $name): string
    {
        return DB::getTablePrefix() . $name;
    }
}
