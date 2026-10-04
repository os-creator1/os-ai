<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthRuleOutcomeStatus;
use App\Models\Business;
use App\Models\GrowthScoreSnapshot;
use Carbon\CarbonImmutable;

/**
 * Persists one evaluation's score as the Business's snapshot for the day
 * (growth_score_snapshots). One row per Business, day and ALGORITHM VERSION:
 * a second evaluation the same day replaces that day's row (the latest facts
 * of the day win), and a new algorithm version writes a row BESIDE the old
 * ones — old versions are never recomputed or rewritten.
 *
 * Also prunes snapshots older than the retention window (>= 13 months).
 */
final class GrowthScoreRecorder
{
    private const POSITIVE_CAP = 8;

    public function __construct(private readonly GrowthScoreCalculator $calculator)
    {
    }

    /**
     * @param  array<string, GrowthRuleOutcome>  $outcomes
     */
    public function record(Business $business, GrowthFactSnapshot $facts, array $outcomes): GrowthScoreSnapshot
    {
        $result = $this->calculator->calculate($outcomes);
        $now = $facts->now;

        $positives = [];

        foreach ($outcomes as $key => $outcome) {
            if ($outcome->status === GrowthRuleOutcomeStatus::Passing && $outcome->positive !== null && count($positives) < self::POSITIVE_CAP) {
                $positives[] = ['rule' => $key, 'facts' => $outcome->positive];
            }
        }

        $crm = $facts->set('crm');
        $metrics = $crm->isAvailable() ? ['new_leads' => $crm->get('new_leads')] : [];
        // Which domains could not be judged, so the Advisor and the Score page can
        // say "not available" instead of implying a clean bill of health.
        $metrics['domain_status'] = array_map(fn (GrowthFactSet $set) => $set->status->value, $facts->sets());

        $categoryScores = array_map(fn (array $c) => $c['score'], $result['categories']);

        $snapshot = GrowthScoreSnapshot::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'snapshot_date' => $now->toDateString(),
                'algorithm_version' => GrowthScoreCalculator::ALGORITHM_VERSION,
            ],
            [
                'overall_score' => $result['overall'],
                'scored_category_count' => $result['scored_categories'],
                'total_category_count' => $result['total_categories'],
                'applicable_rule_count' => $result['applicable_rules'],
                'category_scores' => $categoryScores,
                'breakdown' => $result['categories'],
                'positives' => $positives,
                'metrics' => $metrics,
                'computed_at' => $now,
            ],
        );

        $this->prune($business, $now);

        return $snapshot;
    }

    private function prune(Business $business, CarbonImmutable $now): void
    {
        $days = max(400, (int) config('growth.score.retention_days', 430));

        GrowthScoreSnapshot::query()
            ->where('business_id', $business->id)
            ->where('snapshot_date', '<', $now->subDays($days)->toDateString())
            ->delete();
    }
}
