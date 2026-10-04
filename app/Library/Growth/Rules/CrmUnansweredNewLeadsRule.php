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

/** crm.unanswered_new_leads:v1 — a deal nobody has contacted yet, past the response threshold. */
final class CrmUnansweredNewLeadsRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'crm.unanswered_new_leads:v1',
            worker: OpportunityWorkerKey::Sales,
            category: GrowthCategory::LeadResponse,
            sourceModule: 'crm',
            domain: 'crm',
            scope: 'location',
            title: 'New leads are waiting for a first reply',
            summary: 'These leads were added to your pipeline and are still marked "No contact" after the response window.',
            factKey: 'unanswered_new_leads',
            evidenceSummary: 'Open deals marked "No contact" for longer than the response window.',
            actionKey: 'growth_follow_up_new_leads',
            actionLabel: 'View leads',
            target: 'crm.board',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 3,
            minSample: 3,
            why: 'The first business to reply usually wins the job. A lead that has gone a full day without contact is the one most likely to book elsewhere.',
            expected: 'Contacting these leads gives each of them a chance to move forward. Results depend on the lead.',
            goalKeys: [BusinessGoal::LeadGeneration->value, BusinessGoal::SalesFollowup->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $crm = $facts->set('crm');
        $hours = $facts->thresholds->get('unanswered_lead_hours');
        $out = [];

        foreach ($crm->get('by_location', []) as $locationKey => $buckets) {
            $bucket = $buckets['unanswered'];

            if ($bucket['count'] === 0) {
                continue;
            }

            $evidence = $this->bucketEvidence($bucket, ['threshold_hours' => $hours]);

            $out[(int) $locationKey] = [
                'impact' => $this->impactForValue($bucket['count'] >= 3 ? 5 : 4, $evidence['value_minor'], $facts),
                'urgency' => 4,
                'effort' => 1,
                'confidence' => 1.0,
                'evidence' => $evidence,
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('crm')->get('open_count', 0);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['open_count' => $this->population($facts), 'hours' => $facts->thresholds->get('unanswered_lead_hours')];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);
        $value = GrowthMoney::format($evidence['value_minor'] ?? null, $evidence['currency'] ?? null);

        return GrowthMoney::plural($count, 'new lead has', 'new leads have')
            . ' had no reply for ' . (int) ($evidence['threshold_hours'] ?? 24) . '+ hours'
            . ($value !== null ? ' — ' . $value . ' in pipeline value.' : '.');
    }

    public function positiveStatement(array $positive): ?string
    {
        return $positive['open_count'] >= 3
            ? 'No new lead has waited more than ' . $positive['hours'] . ' hours for a first reply.'
            : null;
    }
}
