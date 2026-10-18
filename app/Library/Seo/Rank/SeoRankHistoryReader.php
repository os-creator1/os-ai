<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankObservationStatus;
use App\Models\SeoRankObservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side facts derived ONLY from stored observations: current, previous,
 * best and change. Absence is never converted to a number: a not-found or
 * not-matched observation has a NULL position and is handled as its own case,
 * never as 0 or depth+1.
 */
final class SeoRankHistoryReader
{
    public const CHANGE_UP = 'up';
    public const CHANGE_DOWN = 'down';
    public const CHANGE_SAME = 'same';
    public const CHANGE_ENTERED = 'entered';
    public const CHANGE_DROPPED = 'dropped';
    public const CHANGE_NONE = 'none';

    /**
     * Current vs previous COMPLETED observation of one check type.
     * Rank 1 is best, so previous 12 -> current 7 is "up 5".
     *
     * @return array{kind: string, amount: int|null}
     */
    public static function change(?SeoRankObservation $current, ?SeoRankObservation $previous): array
    {
        if ($current === null || $previous === null) {
            return ['kind' => self::CHANGE_NONE, 'amount' => null];
        }

        $currentFound = $current->isFound();
        $previousFound = $previous->isFound();

        if ($currentFound && $previousFound) {
            $delta = (int) $previous->position - (int) $current->position;

            return match (true) {
                $delta > 0 => ['kind' => self::CHANGE_UP, 'amount' => $delta],
                $delta < 0 => ['kind' => self::CHANGE_DOWN, 'amount' => -$delta],
                default => ['kind' => self::CHANGE_SAME, 'amount' => 0],
            };
        }

        if ($currentFound && ! $previousFound && $previous->status === SeoRankObservationStatus::NotFound) {
            return ['kind' => self::CHANGE_ENTERED, 'amount' => null];
        }

        if (! $currentFound && $previousFound && $current->status === SeoRankObservationStatus::NotFound) {
            return ['kind' => self::CHANGE_DROPPED, 'amount' => null];
        }

        return ['kind' => self::CHANGE_NONE, 'amount' => null];
    }

    /**
     * Best (lowest) position among FOUND observations, or null when never found.
     *
     * @param iterable<SeoRankObservation> $observations
     */
    public static function best(iterable $observations): ?int
    {
        $best = null;

        foreach ($observations as $o) {
            if ($o->isFound() && ($best === null || $o->position < $best)) {
                $best = (int) $o->position;
            }
        }

        return $best;
    }

    /**
     * Latest, previous and best observation per [targetId][checkType], in three
     * grouped queries regardless of how many targets there are.
     *
     * @param list<int> $targetIds
     * @return array<int, array<string, array{current: SeoRankObservation|null, previous: SeoRankObservation|null, best: int|null}>>
     */
    public function summaries(array $targetIds): array
    {
        $out = [];

        if ($targetIds === []) {
            return $out;
        }

        $latestIds = SeoRankObservation::query()
            ->whereIn('seo_rank_target_id', $targetIds)
            ->groupBy('seo_rank_target_id', 'check_type')
            ->select(DB::raw('MAX(id) as id'))
            ->pluck('id')
            ->all();

        $previousIds = $latestIds === [] ? [] : SeoRankObservation::query()
            ->whereIn('seo_rank_target_id', $targetIds)
            ->whereNotIn('id', $latestIds)
            ->groupBy('seo_rank_target_id', 'check_type')
            ->select(DB::raw('MAX(id) as id'))
            ->pluck('id')
            ->all();

        $rows = SeoRankObservation::query()->whereIn('id', array_merge($latestIds, $previousIds))->get();

        $bests = SeoRankObservation::query()
            ->whereIn('seo_rank_target_id', $targetIds)
            ->where('status', SeoRankObservationStatus::Found->value)
            ->groupBy('seo_rank_target_id', 'check_type')
            ->select('seo_rank_target_id', 'check_type', DB::raw('MIN(position) as best'))
            ->get();

        foreach ($targetIds as $id) {
            foreach (SeoRankCheckType::cases() as $type) {
                $out[$id][$type->value] = ['current' => null, 'previous' => null, 'best' => null];
            }
        }

        $latestSet = array_flip($latestIds);

        foreach ($rows as $o) {
            $slot = isset($latestSet[$o->id]) ? 'current' : 'previous';
            $out[$o->seo_rank_target_id][$o->check_type->value][$slot] = $o;
        }

        foreach ($bests as $b) {
            $out[$b->seo_rank_target_id][$b->check_type instanceof SeoRankCheckType ? $b->check_type->value : (string) $b->check_type]['best'] = (int) $b->best;
        }

        return $out;
    }

    /**
     * The full ordered series of one target for the chart. Missing checks stay
     * absent: there is no interpolation.
     *
     * @return Collection<int, SeoRankObservation>
     */
    public function series(int $targetId): Collection
    {
        return SeoRankObservation::query()
            ->where('seo_rank_target_id', $targetId)
            ->orderBy('checked_at')
            ->orderBy('id')
            ->get();
    }
}
