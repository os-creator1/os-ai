<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * forms.active_without_submissions:v1 — a form has been live for a while and has never received a
 * submission. Deliberately a modest, unscored nudge: it never judges a form's purpose, and a
 * healthy submission count is only a positive statement.
 */
final class FormsQuietRule extends AbstractGrowthRule
{
    public function definition(): GrowthRuleDefinition
    {
        return new GrowthRuleDefinition(
            key: 'forms.active_without_submissions:v1',
            worker: OpportunityWorkerKey::Sales,
            category: GrowthCategory::Forms,
            sourceModule: 'forms',
            domain: 'forms',
            scope: 'business',
            title: 'A live form has not received any submissions',
            summary: 'A form that is switched on has never been filled in.',
            factKey: 'forms_without_submissions',
            evidenceSummary: 'Live forms with no submission since they went live.',
            actionKey: 'growth_review_forms',
            actionLabel: 'View forms',
            target: 'forms',
            safetyClass: GrowthActionSafetyClass::ReadOnly,
            weight: 1,
            minSample: 1,
            why: 'A form nobody fills in is either hard to find or not working. Checking takes a minute.',
            expected: 'Making sure the form is placed where customers will see it can start leads coming in.',
            goalKeys: [BusinessGoal::LeadGeneration->value],
        );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $quiet = (int) $facts->set('forms')->get('quiet', 0);

        return $quiet === 0 ? [] : [0 => ['impact' => 2, 'urgency' => 1, 'effort' => 1, 'confidence' => 1.0, 'evidence' => ['count' => $quiet]]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('forms')->get('active', 0);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        $n = (int) $facts->set('forms')->get('submissions_30d', 0);

        return $n > 0 ? ['submissions' => $n] : null;
    }

    public function headline(array $evidence): string
    {
        $n = (int) ($evidence['count'] ?? 0);

        return sprintf('%d live form%s %s never received a submission.', $n, $n === 1 ? '' : 's', $n === 1 ? 'has' : 'have');
    }

    public function positiveStatement(array $positive): ?string
    {
        $n = (int) ($positive['submissions'] ?? 0);

        return $n > 0 ? sprintf('Your forms brought in %d submission%s in the last 30 days.', $n, $n === 1 ? '' : 's') : null;
    }
}
