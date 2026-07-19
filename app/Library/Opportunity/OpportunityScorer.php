<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Opportunity\Exceptions\InvalidScoreComponentException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Deterministic scoring (RFC-002 §34). Rejects invalid component ranges
 * itself rather than relying solely on its future caller having already
 * validated them — never clamps.
 */
final class OpportunityScorer
{
    private const MIN_RANK = 0;
    private const MAX_RANK = 5;

    /**
     * Minimum evidence-freshness rank across all items — a compound claim is
     * only as current as its stalest required supporting fact.
     *
     * @param  array<int, array<string, mixed>>  $evidenceItems  Persisted-shape evidence (OpportunityEvidenceValidator output).
     */
    public function scoreEvidenceFreshness(array $evidenceItems, CarbonInterface $scoredAt): int
    {
        if ($evidenceItems === []) {
            throw new InvalidScoreComponentException('At least one evidence item is required to score freshness.');
        }

        $ranks = array_map(
            fn (array $item): int => $this->scoreEvidenceItemFreshness($item, $scoredAt),
            $evidenceItems
        );

        return min($ranks);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function scoreEvidenceItemFreshness(array $item, CarbonInterface $scoredAt): int
    {
        $expiresAt = $item['expires_at'] !== null ? Carbon::parse($item['expires_at']) : null;

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($scoredAt)) {
            return 0;
        }

        $effectiveObservedAt = Carbon::parse($item['observed_at'] ?? $item['retrieved_at']);

        // Exact-second age, not integer diffInDays(), so one second past a
        // boundary correctly enters the next rank down.
        $ageDays = $effectiveObservedAt->diffInSeconds($scoredAt) / 86400;

        return match (true) {
            $ageDays <= 1 => 5,
            $ageDays <= 7 => 4,
            $ageDays <= 30 => 3,
            $ageDays <= 90 => 2,
            $ageDays <= 180 => 1,
            default => 0,
        };
    }

    /**
     * Duplicate goal keys are removed on both sides before counting matches,
     * so a repeated value can never inflate the rank.
     *
     * @param  array<int, string>  $relevantGoalKeys
     * @param  array<int, string>  $businessGoalKeys
     */
    public function scoreGoalRelevance(array $relevantGoalKeys, array $businessGoalKeys): int
    {
        $matches = count(array_intersect(array_unique($relevantGoalKeys), array_unique($businessGoalKeys)));

        return match (true) {
            $matches >= 3 => 5,
            $matches === 2 => 4,
            $matches === 1 => 3,
            default => 0,
        };
    }

    /**
     * scoring_version = 1 formula (RFC-002 §34). Range 0–100.
     */
    public function computePriorityScore(
        int $impact,
        int $urgency,
        int $goalRelevanceRank,
        float $confidence,
        int $evidenceFreshnessRank,
        int $effort,
    ): int {
        $this->assertRank('impact', $impact);
        $this->assertRank('urgency', $urgency);
        $this->assertRank('effort', $effort);
        $this->assertRank('goalRelevanceRank', $goalRelevanceRank);
        $this->assertRank('evidenceFreshnessRank', $evidenceFreshnessRank);
        $this->assertConfidence($confidence);

        $score = 35 * ($impact / 5)
            + 20 * ($urgency / 5)
            + 15 * ($goalRelevanceRank / 5)
            + 15 * $confidence
            + 10 * ($evidenceFreshnessRank / 5)
            + 5 * ((5 - $effort) / 5);

        return (int) round($score);
    }

    private function assertRank(string $field, int $value): void
    {
        if ($value < self::MIN_RANK || $value > self::MAX_RANK) {
            throw new InvalidScoreComponentException(
                "[{$field}] must be an integer between " . self::MIN_RANK . ' and ' . self::MAX_RANK . '.'
            );
        }
    }

    private function assertConfidence(float $confidence): void
    {
        if (! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidScoreComponentException('confidence must be a finite decimal between 0 and 1.');
        }
    }
}
