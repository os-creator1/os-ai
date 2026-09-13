<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\ConditionMatch;
use App\Enums\Automation\Workflow\ConditionOperator;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Conditions\ConditionEvaluator;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;

/**
 * Automations V2 §11 — the If / Else step.
 *
 * Reads, decides, and returns which of exactly two edges the journey takes. It
 * writes nothing, calls nothing and enqueues nothing: side-effect class None, so
 * an interrupted evaluation is safe for recovery to re-derive.
 *
 * WHY TENANCY IS RE-DERIVED HERE. WorkflowCompiler already proved, at publish
 * time, that every referenced group and field belongs to this Business. A pinned
 * version then outlives that proof — a group can be moved between Businesses, a
 * field deleted and its id reused, a contact re-pointed at another group — so
 * every reference is checked again now, against real rows, before it is allowed
 * to influence a branch. A reference that no longer resolves inside this
 * Business does not throw and does not guess: it reads as unset, exactly as §11
 * specifies for a contact outside a field's group.
 *
 * A FAILED CONDITION IS `no`, NOT A FAILED JOURNEY. Every path through this
 * executor produces a branch, because the alternative — ending somebody's
 * customer journey because a field was renamed — is worse than taking the
 * else branch.
 */
class IfElseNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly ConditionSubjectRegistry $subjects,
        private readonly ConditionEvaluator $evaluator,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::IfElse;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];

        $match = ConditionMatch::tryFrom((string) ($config['match'] ?? '')) ?? ConditionMatch::All;
        $conditions = is_array($config['conditions'] ?? null) ? array_values($config['conditions']) : [];

        if ($conditions === []) {
            // A branch with nothing to ask cannot be true of anybody.
            return NodeExecutionOutcome::branched(WorkflowEdgeKind::No, 'No conditions');
        }

        // §8.4 — at most five, enforced again at execution so a tampered
        // definition cannot turn one step into an unbounded read loop.
        $conditions = array_slice($conditions, 0, WorkflowLimits::MAX_CONDITIONS_PER_BRANCH);

        // Memoized reads are per-registry-instance; clear them so a long-lived
        // worker never answers this contact with the previous one's values.
        $this->subjects->flush();

        $results = [];

        foreach ($conditions as $condition) {
            $results[] = $this->evaluateOne(
                is_array($condition) ? $condition : [],
                $enrollment,
                $business,
                $contact,
            );
        }

        $matched = $match === ConditionMatch::All
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);

        return NodeExecutionOutcome::branched(
            $matched ? WorkflowEdgeKind::Yes : WorkflowEdgeKind::No,
            $matched ? 'Matched' : 'Did not match',
        );
    }

    private function evaluateOne(
        array $condition,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): bool {
        $key = is_string($condition['subject'] ?? null) ? $condition['subject'] : '';
        $subject = $this->subjects->find($key);

        if ($subject === null) {
            // An unregistered subject can never be true. Refusing at execution as
            // well as at validation means a definition written around the
            // validator still cannot invent a thing to read.
            return false;
        }

        $operator = ConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        if ($operator === null || ! in_array($operator, $subject->allowedOperators(), true)) {
            return false;
        }

        $operand = $condition['operand'] ?? null;

        if ($operator->requiresOperand() && ($operand === null || $operand === '')) {
            return false;
        }

        if (is_string($operand) && mb_strlen($operand) > ConditionSubjectRegistry::MAX_OPERAND_LENGTH) {
            return false;
        }

        if (! $this->referenceIsInBusiness($key, $operand, $business)) {
            return false;
        }

        return $this->evaluator->matches($operator, $subject->valueFor($contact, $enrollment), $operand);
    }

    /**
     * Re-derive, now, that whatever this condition points at is this Business's.
     *
     * `contact.in_group` points at a group through its operand; a custom-field
     * subject points at a field through its own key. Both chains end at
     * `contact_groups.business_id`, and a chain that does not end at THIS
     * Business makes the condition false rather than letting it read.
     */
    private function referenceIsInBusiness(string $key, mixed $operand, Business $business): bool
    {
        if ($key === ConditionSubjectRegistry::IN_GROUP) {
            $groupId = is_int($operand) || (is_string($operand) && ctype_digit($operand)) ? (int) $operand : 0;

            return $groupId > 0 && ContactGroups::query()
                ->whereKey($groupId)
                ->where('business_id', (int) $business->id)
                ->exists();
        }

        $fieldId = ConditionSubjectRegistry::customFieldId($key);

        if ($fieldId === null) {
            // Identity and boolean subjects reference nothing outside the
            // contact the checkpoint already proved is in this Business.
            return true;
        }

        return ContactGroupFields::query()
            ->whereKey($fieldId)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('contact_groups')
                ->whereColumn('contact_groups.id', 'contact_group_fields.contact_group_id')
                ->where('contact_groups.business_id', (int) $business->id))
            ->exists();
    }
}
