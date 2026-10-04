<?php

namespace App\Library\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\MetaAdsAccount;
use Illuminate\Database\Query\Builder;

/**
 * Shared plumbing of the campaign / ad set / ad readers: status whitelist,
 * null-last sorting over the aggregate sub-selects, and result zero-fill
 * lookup. Internal to the Reporting namespace.
 */
trait MetaAdsEntityReaderSupport
{
    /** @return string|null ACTIVE|PAUSED|DELETED|ARCHIVED, or null (= all) */
    private function statusValue(?string $status): ?string
    {
        $value = $status === null ? null : strtoupper(trim($status));

        return in_array($value, ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'], true) ? $value : null;
    }

    /**
     * Null-last ORDER BY (nulls always last, whatever the direction), then the
     * entity id for a total order.
     *
     * @param  array<string, string>  $entitySorts  key => SQL expression over the entity table
     */
    private function applySort(Builder $query, string $sort, string $direction, bool $chosen, array $entitySorts, string $tieBreaker): void
    {
        $direction = MetaAdsMetricQueries::direction($direction);
        $expression = $entitySorts[$sort] ?? MetaAdsMetricQueries::metricSortExpression($sort, $chosen) ?? 'm.spend_micros';

        $query->orderByRaw("({$expression}) IS NULL ASC")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderBy($tieBreaker);
    }

    private function resultPresent(MetaAdsAccount $account, GoogleAdsPeriod $period, MetaAdsLevel $level): bool
    {
        return $this->metrics->resultDataPresent($account, $level, $period->fromDate(), $period->toDate());
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
