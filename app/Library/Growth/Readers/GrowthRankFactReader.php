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
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;

/**
 * Keyword rank facts (domain `rank`) from the STORED rank observations only: Growth never asks a
 * rank provider anything and never re-derives a position. Movement is the SEO module's own
 * SeoRankHistoryReader judgement (current vs previous completed organic observation).
 *
 * A keyword the provider could not find is "not found", never a position (and a missing
 * observation is never zero). Feature: the paid `seo_rank_tracking` capability (the builder gates
 * on it, so a Business without it is NOT ENTITLED, not "missing rankings").
 *
 * Fact shape:
 *   keyword_count   active keywords
 *   tracked         targets currently tracking
 *   observed        tracked targets with a stored organic observation
 *   near_top10      observed targets whose current organic position is 11-20
 *   drops           observed targets that fell `rank_drop_positions`+ places or dropped out of the results
 *   gains           observed targets that rose `rank_gain_positions`+ places or entered the results
 *   stale           tracking targets not checked for `rank_stale_days`+ days
 *   untracked       active keywords with no tracking target
 */
final class GrowthRankFactReader implements GrowthFactReader
{
    private const KEYWORD_CAP = 500;

    public function __construct(private readonly SeoRankHistoryReader $history)
    {
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
        $keywordIds = SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('id')
            ->limit(self::KEYWORD_CAP)
            ->pluck('id')
            ->all();

        $targets = $keywordIds === [] ? collect() : SeoRankTarget::query()
            ->where('business_id', $business->id)
            ->whereIn('seo_keyword_id', $keywordIds)
            ->where('tracking_state', SeoRankTrackingState::Tracking->value)
            ->get(['id', 'seo_keyword_id', 'last_checked_at']);

        $summaries = $this->history->summaries($targets->pluck('id')->all());
        $dropAt = $thresholds->get('rank_drop_positions');
        $gainAt = $thresholds->get('rank_gain_positions');
        $staleBefore = $now->subDays($thresholds->get('rank_stale_days'));
        $observed = 0;
        $near = 0;
        $drops = 0;
        $gains = 0;
        $stale = 0;

        foreach ($targets as $target) {
            if ($target->last_checked_at !== null && CarbonImmutable::instance($target->last_checked_at)->lt($staleBefore)) {
                $stale++;
            }

            $organic = $summaries[$target->id][SeoRankCheckType::Organic->value] ?? null;

            if ($organic === null || $organic['current'] === null) {
                continue;
            }

            $observed++;
            $current = $organic['current'];

            if ($current->isFound() && (int) $current->position >= 11 && (int) $current->position <= 20) {
                $near++;
            }

            $change = SeoRankHistoryReader::change($current, $organic['previous']);

            if ($change['kind'] === SeoRankHistoryReader::CHANGE_DROPPED
                || ($change['kind'] === SeoRankHistoryReader::CHANGE_DOWN && (int) $change['amount'] >= $dropAt)) {
                $drops++;
            } elseif ($change['kind'] === SeoRankHistoryReader::CHANGE_ENTERED
                || ($change['kind'] === SeoRankHistoryReader::CHANGE_UP && (int) $change['amount'] >= $gainAt)) {
                $gains++;
            }
        }

        return GrowthFactSet::available($this->domain(), [
            'keyword_count' => count($keywordIds),
            'tracked' => $targets->count(),
            'observed' => $observed,
            'near_top10' => $near,
            'drops' => $drops,
            'gains' => $gains,
            'stale' => $stale,
            'untracked' => max(0, count($keywordIds) - $targets->pluck('seo_keyword_id')->unique()->count()),
        ]);
    }
}
