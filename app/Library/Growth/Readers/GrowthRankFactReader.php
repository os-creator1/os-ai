<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\SeoConfig;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rank facts (domain `rank`), built ONLY from rank observations the rank module
 * has already STORED (SeoRankHistoryReader). Growth never calls the rank
 * provider, never schedules or buys a check, and never reads a provider
 * payload: evaluating the Growth Center costs nothing in provider usage.
 *
 * A Business that is not entitled to rank tracking never reaches this reader
 * (GrowthFactSnapshotBuilder reports the domain NotEntitled), and a Business
 * with no completed organic check yields nothing to judge, so its rank rules
 * are Insufficient — never "all clear" and never zero.
 *
 * WHAT COUNTS. One row per TRACKING target whose keyword is active and either
 * Business-wide or on an operational Location. Only the ORGANIC position is
 * judged (local pack positions are a different surface). A result older than the
 * rank freshness window (config seo.rank_tracking.stale_after_days) is ignored:
 * a stale position is not evidence of what is happening now.
 *
 * Fact shape:
 *   tracked_count     int   tracking targets considered (capped)
 *   judged_count      int   of those, with a FRESH organic result
 *   comparable_count  int   of those, that also have an earlier organic result
 *   improved_count    int   moved up since the previous check, or newly appeared
 *   top10_count       int   currently at organic position 1-10
 *   drops             array<int, array{id, location, phrase, kind, amount}>   meaningfully worse (see below)
 *   near_top          array<int, array{id, location, phrase, position}>       organic position 11-20
 *
 *   id        the SEO keyword id; location the keyword's Location id (0 = Business-wide)
 *   drops     moved down by at least the `rank_drop_positions` threshold, or fell
 *             out of the results we check (kind `dropped`); never a guess from a
 *             missing or "not matched" result
 *
 * Constant queries: targets (1), summaries (3) — however many targets exist.
 */
final class GrowthRankFactReader implements GrowthFactReader
{
    private const TARGET_CAP = 500;

    private const LIST_CAP = 100;

    /** Organic positions 11-20 are "just outside the first page". */
    private const NEAR_TOP_FROM = 11;

    private const NEAR_TOP_TO = 20;

    public function __construct(
        private readonly SeoRankHistoryReader $history,
        private readonly SeoConfig $config,
    ) {
    }

    public function domain(): string
    {
        return 'rank';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::SeoRankTracking;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $rows = DB::table('seo_rank_targets as t')
            ->join('seo_keywords as k', 'k.id', '=', 't.seo_keyword_id')
            ->where('t.business_id', $business->id)
            ->where('t.tracking_state', SeoRankTrackingState::Tracking->value)
            ->where('k.business_id', $business->id)
            ->where('k.lifecycle_state', 'active')
            // A keyword on an archived Location is no longer worked on anywhere.
            ->where(fn ($q) => $q->whereNull('k.business_location_id')->orWhereIn(
                'k.business_location_id',
                DB::table('business_locations')->select('id')->where('business_id', $business->id)->where('lifecycle_state', 'active'),
            ))
            ->orderBy('t.id')
            ->limit(self::TARGET_CAP)
            ->get(['t.id as target_id', 'k.id as keyword_id', 'k.phrase', 'k.business_location_id']);

        $summaries = $this->history->summaries($rows->pluck('target_id')->map(fn ($id) => (int) $id)->all());

        $freshAfter = $now->subDays($this->config->rankStaleAfterDays());
        $minDrop = $thresholds->get('rank_drop_positions');

        $judged = 0;
        $comparable = 0;
        $improved = 0;
        $top10 = 0;
        $drops = [];
        $nearTop = [];

        foreach ($rows as $row) {
            $organic = $summaries[(int) $row->target_id][SeoRankCheckType::Organic->value] ?? null;
            $current = $organic['current'] ?? null;

            if ($current === null || $current->checked_at === null || $current->checked_at < $freshAfter) {
                continue;
            }

            $judged++;

            $previous = $organic['previous'] ?? null;
            $change = SeoRankHistoryReader::change($current, $previous);
            $location = $row->business_location_id === null ? 0 : (int) $row->business_location_id;
            $entry = ['id' => (int) $row->keyword_id, 'location' => $location, 'phrase' => (string) $row->phrase];

            if ($previous !== null) {
                $comparable++;
            }

            if ($change['kind'] === SeoRankHistoryReader::CHANGE_UP || $change['kind'] === SeoRankHistoryReader::CHANGE_ENTERED) {
                $improved++;
            }

            if (($change['kind'] === SeoRankHistoryReader::CHANGE_DOWN && (int) $change['amount'] >= $minDrop)
                || $change['kind'] === SeoRankHistoryReader::CHANGE_DROPPED) {
                if (count($drops) < self::LIST_CAP) {
                    $drops[] = $entry + ['kind' => $change['kind'], 'amount' => $change['amount']];
                }
            }

            if ($current->isFound()) {
                $position = (int) $current->position;

                if ($position <= 10) {
                    $top10++;
                } elseif ($position >= self::NEAR_TOP_FROM && $position <= self::NEAR_TOP_TO && count($nearTop) < self::LIST_CAP) {
                    $nearTop[] = $entry + ['position' => $position];
                }
            }
        }

        return GrowthFactSet::available($this->domain(), [
            'tracked_count' => $rows->count(),
            'judged_count' => $judged,
            'comparable_count' => $comparable,
            'improved_count' => $improved,
            'top10_count' => $top10,
            'drops' => $drops,
            'near_top' => $nearTop,
        ]);
    }
}
