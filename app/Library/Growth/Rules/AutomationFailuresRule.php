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
 * automations.repeated_failures:v1 — only from durable failure state (failed
 * workflow step runs / failed automation executions). The ABSENCE of a
 * workflow is never reported here (Growth Center §9, §24).
 */
final class AutomationFailuresRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'automations.repeated_failures:v1',
            worker: OpportunityWorkerKey::Sales,
            category: GrowthCategory::Automations,
            sourceModule: 'automations',
            domain: 'automations',
            scope: 'business',
            title: 'Automations are failing repeatedly',
            summary: 'One or more of your automations has failed several times in the last week, so customers may not be getting the messages you expect.',
            factKey: 'automation_failures',
            evidenceSummary: 'Failed automation steps and runs in the last 7 days.',
            actionKey: 'growth_review_automation_failures',
            actionLabel: 'Review automations',
            target: 'automations',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 2,
            minSample: 5,
            why: 'A workflow that fails quietly means follow-ups you planned are not reaching customers.',
            expected: 'Fixing the failing step lets the automation run as you set it up.',
            goalKeys: [BusinessGoal::Automation->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $a = $facts->set('automations');
        $failed = (int) $a->get('failed_steps', 0) + (int) $a->get('failed_runs', 0);

        if ($failed < $facts->thresholds->get('automation_failure_min')) {
            return [];
        }

        return [0 => [
            'impact' => 3, 'urgency' => 4, 'effort' => 2, 'confidence' => 1.0,
            'evidence' => ['count' => $failed, 'workflows' => (int) $a->get('workflows', 0), 'window_days' => (int) $a->get('window_days', 7)],
        ]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('automations')->get('attempted', 0);
    }

    public function headline(array $evidence): string
    {
        return GrowthMoney::plural((int) ($evidence['count'] ?? 0), 'automation step failed', 'automation steps failed')
            . ' in the last ' . (int) ($evidence['window_days'] ?? 7) . ' days.';
    }
}
