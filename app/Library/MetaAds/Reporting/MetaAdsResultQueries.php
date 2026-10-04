<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §5.2 — the single place that reads
 * meta_ads_daily_results, always for the account's CHOSEN result type only.
 *
 * Switching the type re-reads already-stored typed rows; another type is
 * never reinterpreted. No chosen type (or a stored type that is no longer in
 * config('meta_ads.result_types')) means results are UNAVAILABLE: totals()
 * returns null and callers show "Choose a result type", never 0.
 *
 * Every query is scoped by business_id AND meta_ads_account_id AND level AND
 * action_type. Cache-only: nothing here ever calls a provider.
 */
final class MetaAdsResultQueries
{
    public const AGGREGATE_COLUMNS = 'SUM(results) AS results, SUM(result_value) AS result_value, COUNT(*) AS result_row_count';

    public function __construct(private readonly MetaAdsConfig $config)
    {
    }

    /** The owner-chosen action type, or null when none / not an allowed type. */
    public function chosenType(MetaAdsAccount $account): ?string
    {
        $type = is_string($account->result_action_type) ? trim($account->result_action_type) : '';

        return $type !== '' && $this->config->isResultType($type) ? $type : null;
    }

    /** Owner-facing label of the chosen type, or null. */
    public function chosenLabel(MetaAdsAccount $account): ?string
    {
        $type = $this->chosenType($account);

        return $type === null ? null : ($this->config->resultTypes()[$type] ?? null);
    }

    public function daily(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to, string $type): Builder
    {
        return DB::table('meta_ads_daily_results')
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->where('level', $level->value)
            ->where('action_type', $type)
            ->whereBetween('metric_date', [$from, $to]);
    }

    /**
     * One summed block (optionally one entity): {results, result_value,
     * result_row_count}; null when no result type is chosen.
     */
    public function totals(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to, ?int $entityId = null): ?object
    {
        $type = $this->chosenType($account);

        if ($type === null) {
            return null;
        }

        $query = $this->daily($account, $level, $from, $to, $type)->selectRaw(self::AGGREGATE_COLUMNS);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query->first();
    }

    /** Grouped-by-entity sub-select to left-join as `r` (the caller guarantees a chosen type). */
    public function perEntity(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to, string $type): Builder
    {
        return $this->daily($account, $level, $from, $to, $type)
            ->selectRaw('entity_id, ' . self::AGGREGATE_COLUMNS)
            ->groupBy('entity_id');
    }

    /** Whether ANY entity at this level has chosen-type result rows in the window. */
    public function hasData(MetaAdsAccount $account, MetaAdsLevel $level, string $from, string $to): bool
    {
        $type = $this->chosenType($account);

        return $type !== null && $this->daily($account, $level, $from, $to, $type)->exists();
    }
}
