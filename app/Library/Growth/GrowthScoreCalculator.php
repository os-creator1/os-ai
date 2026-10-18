<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthRuleOutcomeStatus;
use App\Enums\Growth\GrowthScoreCategory;

/**
 * The Growth Score, algorithm version 1. A PURE function of rule outcomes —
 * no AI, no database, no clock — so the same facts always give the same
 * score and every point can be traced to a rule.
 *
 * FORMULA (also printed to the owner under "Why this score?"):
 *
 *   rule health   Passing                    1.00
 *                 Finding (worst, per rule)  max(0, 1 - impact/5 x confidence)
 *                 Not applicable / insufficient   EXCLUDED (not 0, not 100)
 *
 *   category      round(100 x sum(weight x health) / sum(weight))
 *                 over the category's scored rules. A category with no scored
 *                 rule has NO score ("Not enough data") — never 0, never 100.
 *
 *   overall       round(mean of the scored categories' scores).
 *                 It states how many categories it is based on ("6 of 9").
 *                 No scored category -> no overall score.
 *
 * Why impact/5 x confidence: an impact-5 finding on a direct canonical fact
 * zeroes that check; a moderate-impact or lower-confidence finding costs
 * proportionally less. Each rule's weight (1-3) is fixed in its definition.
 *
 * Scores are integers; there is no fake precision. A rule with findings in
 * several Locations counts once, at its WORST finding, so a Business with
 * five Locations is not penalised five times for one kind of problem.
 */
final class GrowthScoreCalculator
{
    public const ALGORITHM_VERSION = 1;

    /**
     * @param  array<string, GrowthRuleOutcome>  $outcomes  rule key => outcome
     * @return array{
     *     overall: int|null,
     *     scored_categories: int,
     *     total_categories: int,
     *     applicable_rules: int,
     *     categories: array<string, array{score: int|null, status: string, rules: array<int, array<string, mixed>>}>
     * }
     */
    public function calculate(array $outcomes): array
    {
        $byCategory = [];

        foreach (GrowthScoreCategory::cases() as $category) {
            $byCategory[$category->value] = ['weight' => 0.0, 'earned' => 0.0, 'rules' => []];
        }

        $applicable = 0;

        foreach ($outcomes as $key => $outcome) {
            $rule = GrowthRuleRegistry::find($key);

            if ($rule === null) {
                continue;
            }

            $definition = $rule->definition();
            $scoreCategory = $definition->category->scoreCategory();

            if ($scoreCategory === null) {
                continue;
            }

            if (! $outcome->status->isScored()) {
                $byCategory[$scoreCategory->value]['rules'][] = [
                    'key' => $key,
                    'status' => $outcome->status->value,
                    'weight' => $definition->weight,
                    'health' => null,
                ];

                continue;
            }

            $health = $this->health($outcome);
            $applicable++;
            $byCategory[$scoreCategory->value]['weight'] += $definition->weight;
            $byCategory[$scoreCategory->value]['earned'] += $definition->weight * $health;
            $byCategory[$scoreCategory->value]['rules'][] = [
                'key' => $key,
                'status' => $outcome->status->value,
                'weight' => $definition->weight,
                'health' => (int) round($health * 100),
            ];
        }

        $minRules = max(1, (int) config('growth.score.min_applicable_rules', 1));
        $categories = [];
        $scores = [];

        foreach ($byCategory as $category => $row) {
            $scoredRules = count(array_filter($row['rules'], fn (array $r) => $r['health'] !== null));
            // A category may demand more evidence than one rule before it is scored (Ads: connecting an
            // account must never swing the score on a single data point).
            $needed = max($minRules, (int) config('growth.score.category_min_rules.' . $category, 0));
            $score = ($scoredRules >= $needed && $row['weight'] > 0)
                ? (int) round(100 * $row['earned'] / $row['weight'])
                : null;

            if ($score !== null) {
                $scores[] = $score;
            }

            $categories[$category] = [
                'score' => $score,
                'status' => $score === null ? 'not_enough_data' : 'scored',
                'rules' => $row['rules'],
            ];
        }

        return [
            'overall' => $scores === [] ? null : (int) round(array_sum($scores) / count($scores)),
            'scored_categories' => count($scores),
            'total_categories' => count(GrowthScoreCategory::cases()),
            'applicable_rules' => $applicable,
            'categories' => $categories,
        ];
    }

    private function health(GrowthRuleOutcome $outcome): float
    {
        if ($outcome->status === GrowthRuleOutcomeStatus::Passing) {
            return 1.0;
        }

        $worst = 1.0;

        foreach ($outcome->findings as $finding) {
            $worst = min($worst, max(0.0, 1 - ($finding->impact / 5) * $finding->confidence));
        }

        return $worst;
    }
}
