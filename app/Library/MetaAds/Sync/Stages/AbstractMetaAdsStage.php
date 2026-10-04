<?php

namespace App\Library\MetaAds\Sync\Stages;

use App\DTO\MetaAds\MetaReportPage;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\Sync\Contracts\MetaAdsSyncStage;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shared mechanics for the stages.
 *
 * FETCH pages a Meta list with the `after` cursor (one outbound request per
 * page, each reserved on the call budget by the client), bounded by
 * max_pages_per_report / max_rows_per_report, and stops cleanly when Meta
 * reports API usage at or above sync.usage_stop_percent. Nothing is written
 * during fetch, and a failure on any page loses the whole stage's fetch
 * (complete BEFORE persist).
 *
 * PERSIST writes are idempotent UPSERTs on the natural unique key of the
 * table, in short transactions of at most CHUNK rows, with Meta already fully
 * read: a stage that fails halfway leaves a consistent prefix, and re-running
 * it converges. Every query is scoped by account AND business.
 */
abstract class AbstractMetaAdsStage implements MetaAdsSyncStage
{
    protected const CHUNK = 500;

    /** The status written to an entity a COMPLETE listing no longer returns. */
    protected const GONE_STATUS = 'DELETED';

    public function __construct(
        protected readonly MetaReadClient $client,
        protected readonly MetaAdsConfig $config,
    ) {
    }

    /**
     * @param  callable(?string): MetaReportPage  $fetchPage  receives the `after` cursor (null for the first page)
     */
    protected function paged(callable $fetchPage): MetaAdsStageReport
    {
        $maxPages = $this->config->maxPagesPerReport();
        $maxRows = $this->config->maxRowsPerReport();
        $stopAt = $this->config->usageStopPercent();

        $rows = [];
        $cursor = null;
        $pages = 0;
        $truncated = false;
        $usageHigh = false;

        do {
            $page = $fetchPage($cursor);
            $pages++;

            foreach ($page->rows as $row) {
                $rows[] = $row;
            }

            $more = $page->nextCursor !== null;

            if (count($rows) > $maxRows) {
                $rows = array_slice($rows, 0, $maxRows);
                $truncated = true;
                break;
            }

            if ($page->usage !== null && $page->usage->isAtOrAbove($stopAt)) {
                $usageHigh = true;
                $truncated = $more;
                break;
            }

            if ($more && (count($rows) >= $maxRows || $pages >= $maxPages)) {
                $truncated = true;
                break;
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return new MetaAdsStageReport($rows, $truncated, $usageHigh);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>  $updateColumns  never includes uid / created_at / the key columns
     */
    protected function upsertChunked(string $table, array $rows, array $uniqueBy, array $updateColumns): int
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::transaction(static function () use ($table, $chunk, $uniqueBy, $updateColumns): void {
                DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
            });
        }

        return count($rows);
    }

    /**
     * Marks DELETED every live row of this account that this run did not
     * touch. Callers invoke it ONLY for a complete (non-truncated,
     * nothing-skipped) listing of an entity that carries a `status`. Rows are
     * kept, never deleted, so facts and history survive.
     */
    protected function markUnseenGone(string $table, MetaAdsSyncContext $context): int
    {
        return DB::table($table)
            ->where('meta_ads_account_id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->whereNotIn('status', [self::GONE_STATUS, 'ARCHIVED'])
            ->where(static function ($query) use ($context): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<>', $context->stamp());
            })
            ->update(['status' => self::GONE_STATUS, 'updated_at' => $context->stamp()]);
    }

    /** @return array<string, int> external id => local id, for this account only */
    protected function idMap(string $table, string $externalColumn, MetaAdsSyncContext $context): array
    {
        return DB::table($table)
            ->where('meta_ads_account_id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->pluck('id', $externalColumn)
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** DATETIME columns are stored in the app time zone, like every other timestamp. */
    protected function dateTime(?CarbonImmutable $value): ?string
    {
        return $value?->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
