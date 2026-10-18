<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * crm.stale_opportunities:v1 and crm.high_value_stale_opportunities:v1 — open
 * deals with no recorded activity for stale_deal_days. Two rules, ONE bucket
 * each (the fact reader never counts a deal in both): the ordinary ones, and
 * the ones whose canonical value reaches the high-value threshold, which
 * deserve to outrank everything else in the pipeline.
 */
final class CrmStaleOpportunitiesRule extends AbstractGrowthRule
{
    public function __construct(private readonly bool $highValue = false)
    {
    }

    public static function highValue(): self
    {
        return new self(true);
    }

    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: $this->highValue ? 'crm.high_value_stale_opportunities:v1' : 'crm.stale_opportunities:v1',
            worker: OpportunityWorkerKey::Sales,
            category: GrowthCategory::SalesPipeline,
            sourceModule: 'crm',
            domain: 'crm',
            scope: 'location',
            title: $this->highValue ? 'High-value deals have gone quiet' : 'Open deals have gone quiet',
            summary: $this->highValue
                ? 'These larger deals have had no activity for a while. They are the most valuable thing in your pipeline to revive.'
                : 'These open deals have had no stage change, note or contact for a while.',
            factKey: $this->highValue ? 'high_value_stale_opportunities' : 'stale_opportunities',
            evidenceSummary: 'Open deals with no recorded activity for longer than the stale window.',
            actionKey: $this->highValue ? 'growth_revive_high_value_deals' : 'growth_review_stale_deals',
            actionLabel: 'Review deals',
            target: 'crm.board',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: $this->highValue ? 3 : 2,
            minSample: 3,
            why: 'A deal that nobody touches rarely closes on its own. Moving it forward — or closing it out — keeps your pipeline honest.',
            expected: 'Reaching out gives each deal a chance to move forward or be closed out. Results depend on the customer.',
            goalKeys: [BusinessGoal::SalesFollowup->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $days = $facts->thresholds->get('stale_deal_days');
        $name = $this->highValue ? 'high_value_stale' : 'stale';
        $out = [];

        foreach ($facts->set('crm')->get('by_location', []) as $locationKey => $buckets) {
            $bucket = $buckets[$name];

            if ($bucket['count'] === 0) {
                continue;
            }

            $out[(int) $locationKey] = [
                'impact' => $this->highValue ? 5 : 3,
                'urgency' => $this->highValue ? 3 : 2,
                'effort' => 2,
                'confidence' => 1.0,
                'evidence' => $this->bucketEvidence($bucket, ['threshold_days' => $days]),
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('crm')->get('open_count', 0);
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);
        $value = GrowthMoney::format($evidence['value_minor'] ?? null, $evidence['currency'] ?? null);
        $days = (int) ($evidence['threshold_days'] ?? 7);

        return GrowthMoney::plural($count, $this->highValue ? 'high-value deal has' : 'open deal has', $this->highValue ? 'high-value deals have' : 'open deals have')
            . ' had no activity for ' . $days . '+ days'
            . ($value !== null ? ' — ' . $value . ' in pipeline value.' : '.');
    }
}
