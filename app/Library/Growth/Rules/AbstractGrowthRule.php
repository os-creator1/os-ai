<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthFinding;
use App\Library\Growth\GrowthRule;
use App\Library\Growth\GrowthRuleOutcome;

/**
 * Shared shape of a Growth rule: `detect()` says WHERE the problem is (one
 * entry per Location, key 0 = no Location / Business-wide), `population()`
 * says how many records the rule could have judged, and this class turns the
 * pair into the one verdict the evaluator and the score both consume:
 *
 *   detected anything            -> Finding(s), one per Location
 *   nothing, population >= min   -> Passing   (counts toward the score)
 *   nothing, population <  min   -> Insufficient (EXCLUDED from the score)
 *
 * So a Business with no deals can never be "passing" lead response, and a
 * rule cannot be failed or passed on a sample too small to mean anything.
 */
abstract class AbstractGrowthRule implements GrowthRule
{
    /**
     * @return array<int, array{impact: int, urgency: int, effort: int, confidence: float, evidence: array<string, mixed>}>
     *         keyed by Location id; 0 means Business-wide
     */
    abstract protected function detect(GrowthFactSnapshot $facts): array;

    /** How many records this rule's clean result is based on. */
    abstract protected function population(GrowthFactSnapshot $facts): int;

    /** @return array<string, mixed>|null the positive-insight facts, or null for none */
    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return null;
    }

    public function evaluate(GrowthFactSnapshot $facts): GrowthRuleOutcome
    {
        $detected = $this->detect($facts);

        if ($detected !== []) {
            ksort($detected);

            return GrowthRuleOutcome::findings(array_map(
                fn (int $locationKey, array $d) => new GrowthFinding(
                    $locationKey === 0 ? null : $locationKey,
                    $d['impact'],
                    $d['urgency'],
                    $d['effort'],
                    $d['confidence'],
                    $d['evidence'],
                ),
                array_keys($detected),
                array_values($detected),
            ));
        }

        $population = $this->population($facts);

        if ($population < $this->definition()->minSample) {
            return GrowthRuleOutcome::insufficient();
        }

        return GrowthRuleOutcome::passing($this->positiveFacts($facts));
    }

    public function positiveStatement(array $positive): ?string
    {
        return null;
    }

    /**
     * The closed evidence shape for a "records with money" bucket:
     * count, optional canonical value, and bounded record uids. PII-free.
     *
     * @param  array{count: int, value_minor: int, currency: string|null, mixed_currency: bool, uids: array<int, string>}  $bucket
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function bucketEvidence(array $bucket, array $extra = []): array
    {
        $hasValue = ! $bucket['mixed_currency'] && $bucket['currency'] !== null && $bucket['value_minor'] > 0;

        return [
            'count' => $bucket['count'],
            'value_minor' => $hasValue ? $bucket['value_minor'] : null,
            'currency' => $hasValue ? $bucket['currency'] : null,
            'uids' => $bucket['uids'],
        ] + $extra;
    }

    /**
     * Impact 0-5 for a money-bearing finding: the rule's base impact, raised
     * to the maximum ONLY when the canonical value reaches the high-value
     * threshold. No value, no raise — Growth never estimates money.
     */
    protected function impactForValue(int $base, ?int $valueMinor, GrowthFactSnapshot $facts): int
    {
        if ($valueMinor !== null && $valueMinor >= $facts->thresholds->get('high_value_deal_minor')) {
            return 5;
        }

        return $base;
    }
}
