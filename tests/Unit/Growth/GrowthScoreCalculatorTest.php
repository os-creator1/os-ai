<?php

namespace Tests\Unit\Growth;

use App\Library\Growth\GrowthFinding;
use App\Library\Growth\GrowthRuleOutcome;
use App\Library\Growth\GrowthScoreCalculator;
use Tests\TestCase;

/**
 * The Growth Score is a pure function of rule outcomes: explainable,
 * versioned, and honest about what it could not measure.
 */
class GrowthScoreCalculatorTest extends TestCase
{
    private function finding(int $impact, float $confidence = 1.0): GrowthFinding
    {
        return new GrowthFinding(null, $impact, 3, 1, $confidence, ['count' => 1]);
    }

    public function test_no_outcomes_means_no_score_not_zero_and_not_one_hundred(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([]);

        $this->assertNull($r['overall']);
        $this->assertSame(0, $r['scored_categories']);
        $this->assertSame(9, $r['total_categories']);

        foreach ($r['categories'] as $category) {
            $this->assertNull($category['score']);
            $this->assertSame('not_enough_data', $category['status']);
        }
    }

    public function test_a_clean_category_scores_one_hundred_and_a_zeroed_one_scores_zero(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([
            'crm.unanswered_new_leads:v1' => GrowthRuleOutcome::passing(),
            'booking.type_not_ready:v1' => GrowthRuleOutcome::findings([$this->finding(5)]),
        ]);

        $this->assertSame(100, $r['categories']['lead_response']['score']);
        $this->assertSame(0, $r['categories']['booking']['score'], 'impact 5 at confidence 1.0 zeroes that check');
        $this->assertSame(50, $r['overall']);
        $this->assertSame(2, $r['scored_categories']);
    }

    public function test_impact_and_confidence_scale_the_penalty(): void
    {
        $impact3 = (new GrowthScoreCalculator())->calculate(['booking.type_not_ready:v1' => GrowthRuleOutcome::findings([$this->finding(3)])]);
        $limited = (new GrowthScoreCalculator())->calculate(['booking.type_not_ready:v1' => GrowthRuleOutcome::findings([$this->finding(3, 0.5)])]);

        $this->assertSame(40, $impact3['categories']['booking']['score']);   // 1 - 3/5
        $this->assertSame(70, $limited['categories']['booking']['score']);   // 1 - 3/5*0.5
    }

    public function test_excluded_rules_are_neither_penalised_nor_credited(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([
            'crm.unanswered_new_leads:v1' => GrowthRuleOutcome::passing(),
            'conversations.inbound_awaiting_reply:v1' => GrowthRuleOutcome::notApplicable(),
            'crm.stale_opportunities:v1' => GrowthRuleOutcome::insufficient(),
        ]);

        $this->assertSame(100, $r['categories']['lead_response']['score'], 'the excluded sibling does not drag the category');
        $this->assertNull($r['categories']['sales_conversion']['score'], 'only an insufficient rule: not enough data');
        $this->assertSame(1, $r['scored_categories']);
        $this->assertSame(1, $r['applicable_rules']);

        $statuses = array_column($r['categories']['lead_response']['rules'], 'status', 'key');
        $this->assertSame('not_applicable', $statuses['conversations.inbound_awaiting_reply:v1']);
    }

    public function test_weights_make_important_checks_count_for_more(): void
    {
        // lead_response: unanswered leads (weight 3) clean, conversations (weight 3) zeroed
        $r = (new GrowthScoreCalculator())->calculate([
            'crm.unanswered_new_leads:v1' => GrowthRuleOutcome::passing(),
            'conversations.inbound_awaiting_reply:v1' => GrowthRuleOutcome::findings([$this->finding(5)]),
        ]);
        $this->assertSame(50, $r['categories']['lead_response']['score']);

        // sales_conversion: stale (weight 2) zeroed beside a clean weight-3 proposal rule
        $r = (new GrowthScoreCalculator())->calculate([
            'crm.stale_opportunities:v1' => GrowthRuleOutcome::findings([$this->finding(5)]),
            'documents.proposal_unsigned:v1' => GrowthRuleOutcome::passing(),
        ]);
        $this->assertSame(60, $r['categories']['sales_conversion']['score']);   // 3/5
    }

    public function test_a_rule_with_findings_in_many_locations_counts_once_at_its_worst(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([
            'booking.type_not_ready:v1' => GrowthRuleOutcome::findings([$this->finding(2), $this->finding(5), $this->finding(2)]),
        ]);

        $this->assertSame(0, $r['categories']['booking']['score']);
    }

    public function test_overall_states_how_many_categories_it_is_based_on(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([
            'crm.unanswered_new_leads:v1' => GrowthRuleOutcome::passing(),
            'booking.type_not_ready:v1' => GrowthRuleOutcome::passing(),
            'website.not_published:v1' => GrowthRuleOutcome::findings([$this->finding(5)]),
        ]);

        $this->assertSame(3, $r['scored_categories']);
        $this->assertSame(9, $r['total_categories']);
        $this->assertSame(67, $r['overall']);   // (100 + 100 + 0) / 3
    }

    public function test_forms_and_automations_never_feed_the_score(): void
    {
        $r = (new GrowthScoreCalculator())->calculate([
            'automations.repeated_failures:v1' => GrowthRuleOutcome::findings([$this->finding(5)]),
        ]);

        $this->assertNull($r['overall']);
        $this->assertSame(0, $r['applicable_rules']);
    }

    public function test_the_algorithm_version_is_explicit(): void
    {
        $this->assertSame(1, GrowthScoreCalculator::ALGORITHM_VERSION);
    }

    public function test_the_same_outcomes_always_give_the_same_score(): void
    {
        $outcomes = [
            'crm.unanswered_new_leads:v1' => GrowthRuleOutcome::findings([$this->finding(4)]),
            'reviews.no_review_link:v1' => GrowthRuleOutcome::passing(),
        ];

        $this->assertSame((new GrowthScoreCalculator())->calculate($outcomes), (new GrowthScoreCalculator())->calculate($outcomes));
    }
}
