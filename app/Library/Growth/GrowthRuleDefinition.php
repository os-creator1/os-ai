<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;

/**
 * The static, reviewable identity of one Growth rule (Growth Center §3).
 *
 * Everything the owner is ever shown that is NOT a number comes from here or
 * from the rule's own headline()/positive() functions — fixed strings
 * reviewed in code, never model-authored. This is also the source of the
 * OpportunityTypeRegistry entry, so the engine's "unsupported narrative
 * claims are rejected" guarantee covers Growth rules unchanged.
 *
 * KEY = `<domain>.<rule>:v<N>`. The version is part of the type, so a
 * material change to what a rule MEANS is a new key: the old Opportunities
 * simply stop being re-confirmed (and read as resolved) and keep their old
 * explanation, instead of silently changing meaning under their history.
 */
final class GrowthRuleDefinition
{
    /**
     * @param  string  $scope  'location' (one Opportunity per Location) | 'business'
     * @param  string  $domain  the fact domain the rule reads
     * @param  string  $target  symbolic navigation target, resolved by GrowthNavigation
     * @param  int  $weight  score weight 1..3 (how much this check counts inside its category)
     * @param  int  $minSample  records the rule's population needs before a clean result counts as "passing"
     * @param  array<int, string>  $goalKeys  BusinessGoal values this rule is relevant to
     */
    public function __construct(
        public readonly string $key,
        public readonly OpportunityWorkerKey $worker,
        public readonly GrowthCategory $category,
        public readonly string $sourceModule,
        public readonly string $domain,
        public readonly string $scope,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $factKey,
        public readonly string $evidenceSummary,
        public readonly string $actionKey,
        public readonly string $actionLabel,
        public readonly string $target,
        public readonly GrowthActionSafetyClass $safetyClass,
        public readonly int $weight,
        public readonly int $minSample,
        public readonly string $why,
        public readonly string $expected,
        public readonly array $goalKeys = [],
    ) {
    }

    /**
     * The OpportunityTypeRegistry entry this rule contributes. Growth-specific
     * metadata rides along under extra keys the engine ignores but the Growth
     * Center reads (category, source_module, safety class ...).
     *
     * @return array<string, mixed>
     */
    public function toTypeDefinition(): array
    {
        return [
            'title_template' => $this->title,
            'summary_template' => $this->summary,
            'allowed_evidence_source_types' => [$this->domain],
            'allowed_evidence_fact_keys' => [$this->factKey],
            'required_evidence_fact_keys' => [$this->factKey],
            'evidence_summary_templates' => [$this->factKey => $this->evidenceSummary],
            'action_key' => $this->actionKey,
            'context_validator' => $this->scope === 'location' ? 'location' : 'business',
            'allowed_relevant_goal_keys' => $this->goalKeys,
            'dismiss_cooldown_days' => (new GrowthThresholds())->get('dismiss_cooldown_days'),
            // Growth metadata (read by the Growth Center, ignored by the engine)
            'growth' => [
                'category' => $this->category->value,
                'source_module' => $this->sourceModule,
                'safety_class' => $this->safetyClass->value,
                'action_label' => $this->actionLabel,
                'target' => $this->target,
                'why' => $this->why,
                'expected' => $this->expected,
                'weight' => $this->weight,
            ],
        ];
    }

    /**
     * The action definition this rule contributes to OpportunityActionRegistry.
     * Growth V1 actions are navigation handoffs: they mutate nothing, cost
     * nothing, and need no approval — the owning module's own screen performs
     * (and confirms) any write. There is deliberately NO handler, so the
     * engine never treats one as an executable action.
     *
     * @return array<string, mixed>
     */
    public function toActionDefinition(): array
    {
        return [
            'schema_version' => 1,
            'mutates_business_data' => false,
            'paid_effect' => false,
            'meter_key' => null,
            'location_bound' => false,
            'approval_required' => false,
            'completion_policy' => \App\Enums\Opportunity\OpportunityCompletionPolicy::SystemVerified,
            'parameter_rules' => [],
        ];
    }
}
