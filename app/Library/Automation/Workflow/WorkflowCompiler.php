<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Models\AutomationWorkflowVersion;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 §5.4 — turns a validated document into the immutable graph the
 * runtime executes.
 *
 * Two jobs, deliberately separate from the validator's:
 *
 *   `validate()`  adds every check that needs the database — above all, that each
 *                 referenced contact group and custom field belongs to THIS
 *                 workflow's Business. A shape check cannot answer that, and
 *                 conflating the two is how an authorization check ends up
 *                 looking like a formatting rule.
 *
 *   `compile()`   emits the node and edge rows, in one transaction, as two bulk
 *                 inserts. These rows are written once and never updated; from
 *                 here on the runtime reads only them, never the document.
 *
 * The emitted shape is a tree by construction: a sequence chains with `next`
 * edges, an If/Else emits one `yes` and one `no` edge, nothing is ever emitted
 * twice, and no edge ever points at an already-targeted node. `compile()` then
 * asserts `nodes = edges + 1` as a safety net — with `UNIQUE(to_node_id)` in the
 * schema, that equality is what proves the result is a single connected tree
 * rather than a forest or a cycle.
 */
class WorkflowCompiler
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
        private readonly NodeTypeRegistry $registry,
    ) {
    }

    /**
     * Every reason this version cannot be published, keyed by node.
     *
     * @return array<string, list<string>>
     */
    public function validate(AutomationWorkflowVersion $version): array
    {
        $definition = $version->definition ?? [];

        $errors = $this->validator->validate($definition);

        // Tenancy is only checkable once the shape is sound; running it over a
        // malformed document would produce confusing duplicate complaints.
        if ($errors !== []) {
            return $errors;
        }

        return $this->validateReferences($version, $definition);
    }

    /**
     * Emit the graph. The caller MUST already have validated and MUST already be
     * inside a transaction (WorkflowPublisher is).
     *
     * @return int the number of nodes written
     */
    public function compile(AutomationWorkflowVersion $version): int
    {
        $flattened = $this->plan($version);

        $now = Carbon::now();
        $businessId = (int) $version->business_id;
        $versionId = (int) $version->id;

        $nodeRows = [];

        foreach ($flattened as $entry) {
            $nodeRows[] = [
                'version_id' => $versionId,
                'business_id' => $businessId,
                'node_key' => $entry['key'],
                'node_type' => $entry['type']->value,
                'config' => json_encode($entry['config']),
                'depth' => $entry['depth'],
                'created_at' => $now,
            ];
        }

        DB::table('automation_workflow_nodes')->insert($nodeRows);

        // Re-read to map the editor's stable keys onto the new primary keys.
        $idByKey = DB::table('automation_workflow_nodes')
            ->where('version_id', $versionId)
            ->pluck('id', 'node_key')
            ->all();

        $edgeRows = [];

        foreach ($flattened as $entry) {
            foreach ($entry['edges'] as $kind => $targetKey) {
                $edgeRows[] = [
                    'version_id' => $versionId,
                    'business_id' => $businessId,
                    'from_node_id' => $idByKey[$entry['key']],
                    'to_node_id' => $idByKey[$targetKey],
                    'edge_kind' => $kind,
                    'created_at' => $now,
                ];
            }
        }

        if ($edgeRows !== []) {
            DB::table('automation_workflow_edges')->insert($edgeRows);
        }

        $nodeCount = count($nodeRows);

        // A tree has exactly one fewer edge than it has nodes. If this ever
        // fails, the walk produced a forest or revisited a node, and publishing
        // a graph the runtime cannot traverse safely is worse than failing here.
        if (count($edgeRows) !== $nodeCount - 1) {
            throw new \RuntimeException(sprintf(
                'Compiled graph is not a tree: %d nodes, %d edges.',
                $nodeCount,
                count($edgeRows),
            ));
        }

        return $nodeCount;
    }

    /**
     * The graph this version WOULD compile to, built in memory and written
     * nowhere.
     *
     * `compile()` is this plus the two inserts, so there is exactly one
     * traversal of a workflow document in this codebase and nothing can drift
     * from it. That matters for WorkflowSimulator (§16, "Test workflow"), which
     * must walk precisely the graph a real enrollment would walk — including for
     * a DRAFT, which by definition has no compiled rows to read — while writing
     * nothing at all.
     *
     * Requires a document that has already passed validation: an unregistered
     * node type or a missing root is a malformed document, and this throws
     * rather than inventing a shape. Callers that accept unvalidated input
     * (the simulator does) handle that.
     *
     * @return array<int, array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>}>
     */
    public function plan(AutomationWorkflowVersion $version): array
    {
        $definition = $version->definition ?? [];

        if (! is_array($definition['root'] ?? null)) {
            throw new \RuntimeException('This workflow has no trigger to start from.');
        }

        $flattened = [];
        $this->flatten($definition['root'], 0, $flattened);

        return $flattened;
    }

    /**
     * Walk the document into a flat list of nodes, each carrying the edges it
     * owns. One pass, so a node is emitted exactly once.
     *
     * @param array<int, array{key: string, type: WorkflowNodeType, config: array, depth: int, edges: array<string, string>}> $out
     */
    private function flatten(array $node, int $depth, array &$out): string
    {
        $key = (string) $node['key'];
        $type = WorkflowNodeType::from((string) $node['type']);

        $entry = [
            'key' => $key,
            'type' => $type,
            'config' => is_array($node['config'] ?? null) ? $node['config'] : [],
            'depth' => $depth,
            'edges' => [],
        ];

        // Reserve this node's slot before descending, so ordering is stable and
        // readable: parents appear before their children.
        $position = count($out);
        $out[] = $entry;

        if ($type->isBranching()) {
            foreach ([WorkflowEdgeKind::Yes, WorkflowEdgeKind::No] as $lane) {
                $steps = array_values($node[$lane->value] ?? []);

                if ($steps === []) {
                    // An empty lane simply ends — no edge, no node.
                    continue;
                }

                $out[$position]['edges'][$lane->value] = $this->flattenSequence($steps, $depth + 1, $out);
            }

            return $key;
        }

        $steps = array_values($node['next'] ?? []);

        if ($steps !== []) {
            $out[$position]['edges'][WorkflowEdgeKind::Next->value] = $this->flattenSequence($steps, $depth, $out);
        }

        return $key;
    }

    /**
     * Flatten an ordered sequence, chaining each step to the next.
     *
     * @return string the key of the first step, which the parent edge targets
     */
    private function flattenSequence(array $steps, int $depth, array &$out): string
    {
        $firstKey = null;
        $previousPosition = null;

        foreach ($steps as $step) {
            $position = count($out);
            $key = $this->flatten($step, $depth, $out);

            if ($firstKey === null) {
                $firstKey = $key;
            }

            if ($previousPosition !== null) {
                $out[$previousPosition]['edges'][WorkflowEdgeKind::Next->value] = $key;
            }

            $previousPosition = $position;
        }

        return (string) $firstKey;
    }

    /**
     * Business-scoped reference checks (§14.2). Every id a node points at must
     * belong to this workflow's Business — re-verified at execution too, but
     * refused here so a cross-Business reference can never be published at all.
     *
     * @return array<string, list<string>>
     */
    private function validateReferences(AutomationWorkflowVersion $version, array $definition): array
    {
        $errors = [];
        $businessId = (int) $version->business_id;

        $flattened = [];
        $this->flatten($definition['root'], 0, $flattened);

        $triggerGroupId = null;
        $triggerType = null;

        foreach ($flattened as $entry) {
            if ($entry['type'] === WorkflowNodeType::Trigger) {
                $triggerType = WorkflowTriggerType::tryFrom((string) ($entry['config']['trigger_type'] ?? ''));
                $groupId = $entry['config']['contact_group_id'] ?? null;
                $triggerGroupId = $groupId === null ? null : (int) $groupId;

                if ($triggerGroupId !== null && ! $this->groupBelongsToBusiness($triggerGroupId, $businessId)) {
                    $errors[$entry['key']][] = 'That contact group does not belong to this business.';
                }

                if ($triggerType === WorkflowTriggerType::ContactDateReached) {
                    $fieldId = (int) ($entry['config']['date_field_id'] ?? 0);

                    if ($triggerGroupId === null || ! $this->fieldBelongsToGroup($fieldId, $triggerGroupId, $businessId)) {
                        $errors[$entry['key']][] = 'That date field does not belong to the contact group this workflow watches.';
                    }
                }
            }
        }

        foreach ($flattened as $entry) {
            if ($entry['type'] !== WorkflowNodeType::UpdateContactField) {
                continue;
            }

            // B4 §7.B, carried forward: a custom field belongs to exactly one
            // contact group, so a workflow whose field cannot match its audience
            // is structurally impossible and must never be accepted. That means
            // an explicit trigger group is required for this action.
            if ($triggerGroupId === null) {
                $errors[$entry['key']][] = 'To update a contact field, the trigger must watch one specific contact group.';

                continue;
            }

            $fieldId = (int) ($entry['config']['field_id'] ?? 0);

            if (! $this->fieldBelongsToGroup($fieldId, $triggerGroupId, $businessId)) {
                $errors[$entry['key']][] = 'That field does not belong to the contact group this workflow watches.';
            }
        }

        foreach ($flattened as $entry) {
            if ($entry['type'] !== WorkflowNodeType::IfElse) {
                continue;
            }

            foreach ($this->conditionReferenceErrors($entry['config'] ?? [], $businessId) as $error) {
                $errors[$entry['key']][] = $error;
            }
        }

        return $errors;
    }

    /**
     * §11 — every group and field an If/Else points at must belong to THIS
     * Business, checked here against real rows.
     *
     * NodeTypeRegistry has already refused unknown subjects and illegal
     * operators; it is pure and cannot ask whether a referenced row exists. This
     * is the other half, and it is not the last one either — IfElseNodeExecutor
     * re-derives the same chains at evaluation, because a pinned version outlives
     * whatever was true when it was published.
     *
     * A custom field also fixes its own operator family, so a date field asked
     * `contains` is refused here rather than quietly reading as text.
     *
     * @return list<string>
     */
    private function conditionReferenceErrors(array $config, int $businessId): array
    {
        $errors = [];
        $conditions = is_array($config['conditions'] ?? null) ? array_values($config['conditions']) : [];

        foreach ($conditions as $index => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $position = $index + 1;
            $subject = is_string($condition['subject'] ?? null) ? $condition['subject'] : '';

            if ($subject === ConditionSubjectRegistry::IN_GROUP) {
                $operand = $condition['operand'] ?? null;
                $groupId = is_int($operand) || (is_string($operand) && ctype_digit($operand)) ? (int) $operand : 0;

                if ($groupId <= 0 || ! $this->groupBelongsToBusiness($groupId, $businessId)) {
                    $errors[] = sprintf('Condition %d checks a contact group that does not belong to this business.', $position);
                }

                continue;
            }

            $fieldId = ConditionSubjectRegistry::customFieldId($subject);

            if ($fieldId === null) {
                continue;
            }

            $field = ContactGroupFields::query()
                ->whereKey($fieldId)
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('contact_groups')
                    ->whereColumn('contact_groups.id', 'contact_group_fields.contact_group_id')
                    ->where('contact_groups.business_id', $businessId))
                ->first();

            if ($field === null) {
                $errors[] = sprintf('Condition %d checks a contact field that does not belong to this business.', $position);

                continue;
            }

            $operator = ConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

            if ($operator === null) {
                continue;
            }

            $allowed = ContactGroupFields::getControlNameByType((string) $field->type) === 'date'
                ? ConditionOperator::forDate()
                : ConditionOperator::forText();

            if (! in_array($operator, $allowed, true)) {
                $errors[] = sprintf('Condition %d uses a comparison that does not apply to that field.', $position);
            }
        }

        return $errors;
    }

    private function groupBelongsToBusiness(int $groupId, int $businessId): bool
    {
        return ContactGroups::query()
            ->whereKey($groupId)
            ->where('business_id', $businessId)
            ->exists();
    }

    private function fieldBelongsToGroup(int $fieldId, int $groupId, int $businessId): bool
    {
        if ($fieldId <= 0) {
            return false;
        }

        return ContactGroupFields::query()
            ->whereKey($fieldId)
            ->where('contact_group_id', $groupId)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('contact_groups')
                ->whereColumn('contact_groups.id', 'contact_group_fields.contact_group_id')
                ->where('contact_groups.business_id', $businessId))
            ->exists();
    }
}
