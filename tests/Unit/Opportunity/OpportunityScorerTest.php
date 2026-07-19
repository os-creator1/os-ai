<?php

namespace Tests\Unit\Opportunity;

use App\Library\Opportunity\Exceptions\InvalidScoreComponentException;
use App\Library\Opportunity\OpportunityScorer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpportunityScorerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function evidenceItem(array $overrides = []): array
    {
        return array_merge([
            'retrieved_at' => CarbonImmutable::parse('2026-01-01T00:00:00+00:00')->toIso8601String(),
            'observed_at' => null,
            'expires_at' => null,
        ], $overrides);
    }

    #[DataProvider('freshnessBoundaryProvider')]
    public function test_evidence_freshness_boundaries(int $daysAgo, int $secondsPastBoundary, int $expectedRank): void
    {
        $scorer = new OpportunityScorer();
        $scoredAt = CarbonImmutable::parse('2026-06-01T00:00:00+00:00');
        $retrievedAt = $scoredAt->subDays($daysAgo)->subSeconds($secondsPastBoundary);

        $rank = $scorer->scoreEvidenceFreshness(
            [$this->evidenceItem(['retrieved_at' => $retrievedAt->toIso8601String()])],
            $scoredAt
        );

        $this->assertSame($expectedRank, $rank);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function freshnessBoundaryProvider(): array
    {
        return [
            'exactly 1 day -> 5' => [1, 0, 5],
            '1 second past 1 day -> 4' => [1, 1, 4],
            'exactly 7 days -> 4' => [7, 0, 4],
            '1 second past 7 days -> 3' => [7, 1, 3],
            'exactly 30 days -> 3' => [30, 0, 3],
            '1 second past 30 days -> 2' => [30, 1, 2],
            'exactly 90 days -> 2' => [90, 0, 2],
            '1 second past 90 days -> 1' => [90, 1, 1],
            'exactly 180 days -> 1' => [180, 0, 1],
            '1 second past 180 days -> 0' => [180, 1, 0],
        ];
    }

    public function test_expired_at_or_before_scored_at_ranks_zero_even_if_otherwise_fresh(): void
    {
        $scorer = new OpportunityScorer();
        $scoredAt = CarbonImmutable::parse('2026-06-01T00:00:00+00:00');

        $rank = $scorer->scoreEvidenceFreshness([
            $this->evidenceItem([
                'retrieved_at' => $scoredAt->subHour()->toIso8601String(),
                'expires_at' => $scoredAt->toIso8601String(),
            ]),
        ], $scoredAt);

        $this->assertSame(0, $rank);
    }

    public function test_final_rank_is_the_minimum_across_all_items(): void
    {
        $scorer = new OpportunityScorer();
        $scoredAt = CarbonImmutable::parse('2026-06-01T00:00:00+00:00');

        $rank = $scorer->scoreEvidenceFreshness([
            $this->evidenceItem(['retrieved_at' => $scoredAt->subHours(1)->toIso8601String()]),
            $this->evidenceItem(['retrieved_at' => $scoredAt->subDays(200)->toIso8601String()]),
        ], $scoredAt);

        $this->assertSame(0, $rank);
    }

    public function test_observed_at_is_used_over_retrieved_at_when_present(): void
    {
        $scorer = new OpportunityScorer();
        $scoredAt = CarbonImmutable::parse('2026-06-01T00:00:00+00:00');

        $rank = $scorer->scoreEvidenceFreshness([
            $this->evidenceItem([
                'retrieved_at' => $scoredAt->subDays(200)->toIso8601String(),
                'observed_at' => $scoredAt->subHour()->toIso8601String(),
            ]),
        ], $scoredAt);

        $this->assertSame(5, $rank);
    }

    public function test_scoring_empty_evidence_throws(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->scoreEvidenceFreshness([], CarbonImmutable::now());
    }

    #[DataProvider('goalRelevanceProvider')]
    public function test_goal_relevance_matches(array $relevant, array $stored, int $expectedRank): void
    {
        $scorer = new OpportunityScorer();

        $this->assertSame($expectedRank, $scorer->scoreGoalRelevance($relevant, $stored));
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: int}>
     */
    public static function goalRelevanceProvider(): array
    {
        return [
            'zero matches' => [['local_seo'], ['reputation'], 0],
            'one match' => [['local_seo'], ['local_seo'], 3],
            'two matches' => [['local_seo', 'reputation'], ['local_seo', 'reputation'], 4],
            'three or more matches' => [['local_seo', 'reputation', 'automation'], ['local_seo', 'reputation', 'automation'], 5],
        ];
    }

    public function test_duplicate_goal_keys_do_not_inflate_the_rank(): void
    {
        $scorer = new OpportunityScorer();

        $rank = $scorer->scoreGoalRelevance(['local_seo', 'local_seo', 'local_seo'], ['local_seo']);

        $this->assertSame(3, $rank);
    }

    public function test_priority_score_formula_at_the_minimum(): void
    {
        $scorer = new OpportunityScorer();

        $this->assertSame(0, $scorer->computePriorityScore(0, 0, 0, 0.0, 0, 5));
    }

    public function test_priority_score_formula_at_the_maximum(): void
    {
        $scorer = new OpportunityScorer();

        $this->assertSame(100, $scorer->computePriorityScore(5, 5, 5, 1.0, 5, 0));
    }

    public function test_priority_score_formula_spot_check(): void
    {
        $scorer = new OpportunityScorer();

        // 35*(3/5) + 20*(3/5) + 15*(0/5) + 15*0.90 + 10*(3/5) + 5*((5-1)/5)
        // = 21 + 12 + 0 + 13.5 + 6 + 4 = 56.5 -> round -> 57
        $score = $scorer->computePriorityScore(3, 3, 0, 0.90, 3, 1);

        $this->assertSame(57, $score);
    }

    public function test_priority_score_rejects_out_of_range_impact(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(6, 0, 0, 0.5, 0, 0);
    }

    public function test_priority_score_rejects_out_of_range_urgency(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, -1, 0, 0.5, 0, 0);
    }

    public function test_priority_score_rejects_out_of_range_effort(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, 0, 0, 0.5, 0, 6);
    }

    public function test_priority_score_rejects_out_of_range_goal_relevance_rank(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, 0, 6, 0.5, 0, 0);
    }

    public function test_priority_score_rejects_out_of_range_evidence_freshness_rank(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, 0, 0, 0.5, 6, 0);
    }

    public function test_priority_score_rejects_confidence_outside_zero_to_one(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, 0, 0, 1.5, 0, 0);
    }

    public function test_priority_score_rejects_non_finite_confidence(): void
    {
        $scorer = new OpportunityScorer();

        $this->expectException(InvalidScoreComponentException::class);

        $scorer->computePriorityScore(0, 0, 0, NAN, 0, 0);
    }
}
