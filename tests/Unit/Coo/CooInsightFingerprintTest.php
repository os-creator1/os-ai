<?php

namespace Tests\Unit\Coo;

use App\Library\Coo\Insight\CooInsightFacts;
use App\Library\Coo\Insight\CountBucket;
use Tests\TestCase;

/**
 * AI-3 — contract §9.1 bucketing and fingerprint (T-INS-1).
 */
class CooInsightFingerprintTest extends TestCase
{
    public function test_counts_fall_into_the_contracts_fixed_bands_at_every_edge(): void
    {
        $expected = [
            0 => '0',
            1 => '1-4', 4 => '1-4',
            5 => '5-9', 9 => '5-9',
            10 => '10-24', 24 => '10-24',
            25 => '25-49', 49 => '25-49',
            50 => '50-99', 99 => '50-99',
            100 => '100+', 101 => '100+', 5000 => '100+',
        ];

        foreach ($expected as $count => $band) {
            $this->assertSame($band, CountBucket::label($count), "{$count} belongs in {$band}.");
        }

        $this->assertSame('0', CountBucket::label(-3), 'A count is never below zero.');
    }

    public function test_a_change_inside_a_band_keeps_the_fingerprint(): void
    {
        $a = $this->facts(['new_contacts' => [12, 11, 'stable']]);
        $b = $this->facts(['new_contacts' => [13, 12, 'stable']]);

        $this->assertSame($a->fingerprint(1, 1), $b->fingerprint(1, 1), 'One more contact inside the same bands buys no new insight.');
    }

    public function test_a_change_of_band_or_of_materiality_changes_the_fingerprint(): void
    {
        $base = $this->facts(['new_contacts' => [24, 20, 'stable']]);

        $this->assertNotSame($base->fingerprint(1, 1), $this->facts(['new_contacts' => [25, 20, 'stable']])->fingerprint(1, 1), 'Crossing a band is a different fact.');
        $this->assertNotSame($base->fingerprint(1, 1), $this->facts(['new_contacts' => [24, 20, 'material_increase']])->fingerprint(1, 1), 'A materiality classification is part of the facts (§9.1).');
    }

    public function test_prompt_and_policy_versions_are_part_of_the_fingerprint(): void
    {
        $facts = $this->facts(['new_contacts' => [12, 11, 'stable']]);

        $this->assertNotSame($facts->fingerprint(1, 1), $facts->fingerprint(2, 1));
        $this->assertNotSame($facts->fingerprint(1, 1), $facts->fingerprint(1, 2));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $facts->fingerprint(1, 1));
    }

    public function test_attention_opportunities_and_visibility_are_facts_and_move_the_fingerprint(): void
    {
        $base = $this->facts(['new_contacts' => [12, 11, 'stable']]);

        $this->assertNotSame($base->fingerprint(1, 1), $this->facts(['new_contacts' => [12, 11, 'stable']], attention: ['google_connection_lost'])->fingerprint(1, 1));
        $this->assertNotSame($base->fingerprint(1, 1), $this->facts(['new_contacts' => [12, 11, 'stable']], website: 'published')->fingerprint(1, 1));
    }

    public function test_the_same_facts_always_serialise_to_the_same_bytes(): void
    {
        $this->assertSame(
            CooInsightFacts::canonicalJson(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]),
            CooInsightFacts::canonicalJson(['a' => ['x' => [3, 1], 'y' => 2], 'b' => 1]),
            'Keys are sorted; list order is kept.',
        );
    }

    public function test_material_metrics_and_the_conservative_explanation_rule(): void
    {
        $facts = $this->facts([
            'new_contacts' => [20, 5, 'material_increase'],
            'messages_received' => [2, 12, 'material_decrease'],
            'conversations_started' => [3, 3, 'insufficient_data'],
        ]);

        $this->assertSame(['new_contacts', 'messages_received'], $facts->materialMetricKeys());
        $this->assertFalse($facts->hasDeterministicExplanation());

        $withAttention = $this->facts(['new_contacts' => [20, 5, 'material_increase']], attention: ['automation_failing']);
        $this->assertTrue($withAttention->hasDeterministicExplanation(), 'Any raised Attention type counts as a rule that may explain the change.');

        $withNewOpportunity = $this->facts(['new_contacts' => [20, 5, 'material_increase']], opportunities: [
            ['type' => 'missing_phone', 'evidence_keys' => ['phone_blank'], 'evidence' => ['The business phone number is not set.'], 'first_detected_in_period' => true],
        ]);
        $this->assertTrue($withNewOpportunity->hasDeterministicExplanation(), 'An Opportunity first detected in the period counts too.');

        $withOldOpportunity = $this->facts(['new_contacts' => [20, 5, 'material_increase']], opportunities: [
            ['type' => 'missing_phone', 'evidence_keys' => ['phone_blank'], 'evidence' => ['The business phone number is not set.'], 'first_detected_in_period' => false],
        ]);
        $this->assertFalse($withOldOpportunity->hasDeterministicExplanation(), 'An Opportunity from before the period does not.');
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: string}>  $metrics
     * @param  array<int, string>  $attention
     * @param  array<int, array<string, mixed>>  $opportunities
     */
    private function facts(array $metrics, array $attention = [], array $opportunities = [], ?string $website = null): CooInsightFacts
    {
        return new CooInsightFacts(
            businessId: 1,
            periodKey: 'this_month_2026-09',
            periodLabel: 'This month',
            currentStart: '2026-09-01',
            currentEnd: '2026-09-10',
            previousStart: '2026-08-22',
            previousEnd: '2026-08-31',
            metrics: array_map(fn (array $m): array => ['current' => $m[0], 'previous' => $m[1], 'direction' => $m[2]], $metrics),
            attention: $attention,
            opportunities: $opportunities,
            visibility: ['website' => $website, 'google' => null],
        );
    }
}
