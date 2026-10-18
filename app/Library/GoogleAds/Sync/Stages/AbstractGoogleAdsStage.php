<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Library\GoogleAds\Sync\Contracts\GoogleAdsSyncStage;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared write mechanics for the stages. Every write is an idempotent UPSERT
 * on the natural unique key of the table, in short transactions of at most
 * CHUNK rows, with the provider already fully read: a stage that fails halfway
 * leaves a consistent prefix, and re-running it converges.
 */
abstract class AbstractGoogleAdsStage implements GoogleAdsSyncStage
{
    protected const CHUNK = 500;

    public function __construct(protected readonly GoogleAdsReadClient $client)
    {
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
     * Marks REMOVED every non-removed row of this account that this run did
     * not touch. Callers invoke it ONLY for a complete (non-truncated,
     * nothing-skipped) listing of an entity that carries a `status`. Rows are
     * kept, never deleted, so facts and history survive.
     *
     * @param  array<string, mixed>  $where  extra equality constraints
     */
    protected function markUnseenRemoved(string $table, GoogleAdsSyncContext $context, array $where = []): int
    {
        return DB::table($table)
            ->where('google_ads_account_id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->where($where)
            ->where('status', '<>', GoogleAdsEntityStatus::Removed->value)
            ->where(static function ($query) use ($context): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<>', $context->stamp());
            })
            ->update(['status' => GoogleAdsEntityStatus::Removed->value, 'updated_at' => $context->stamp()]);
    }

    /** @return array<string, int> external id => local id, for this account only */
    protected function idMap(string $table, string $externalColumn, GoogleAdsSyncContext $context): array
    {
        return DB::table($table)
            ->where('google_ads_account_id', $context->accountId())
            ->where('business_id', $context->businessId())
            ->pluck('id', $externalColumn)
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Sum of two nullable decimal strings; null only when both are null (an
     * absent value is never turned into 0).
     */
    protected function addDecimal(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : bcadd($a, $b, 6);
    }
}
