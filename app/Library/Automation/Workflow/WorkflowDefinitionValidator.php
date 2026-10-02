<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowLocationScope;
use App\Enums\Automation\Workflow\WorkflowNodeType;

/**
 * Automations V2 §5.3/§5.4 — validates the editor's document.
 *
 * Pure and database-free, so it can run on every autosave without cost and be
 * unit-tested without a schema. It answers one question: is this document a
 * legal workflow shape? Whether the things it references belong to the right
 * Business is WorkflowCompiler's job, against real rows.
 *
 * THE DOCUMENT IS A NESTED LIST, AND THAT IS THE WHOLE TREE GUARANTEE:
 *
 *   { "schema_version": 1,
 *     "root": { "key": "...", "type": "trigger", "config": {...},
 *               "next": [ step, step, { "type": "if_else", ...,
 *                                       "yes": [ step... ], "no": [ step... ] } ] } }
 *
 * A list cannot express a cycle and cannot express a merge — there is nowhere to
 * write "go back to" or "both lanes continue here". So the builder physically
 * cannot draw an illegal graph, and the compiler's reachability check plus
 * `UNIQUE(to_node_id)` confirm it at the database.
 *
 * Errors are returned keyed by the node key they belong to, because that is what
 * the canvas needs to mark the offending step. Document-level problems use the
 * DOCUMENT_KEY bucket.
 *
 * A draft with errors still SAVES (§14.4) — work is never lost — it simply
 * cannot publish.
 */
class WorkflowDefinitionValidator
{
    public const SCHEMA_VERSION = 1;

    /** Bucket for problems that belong to the document rather than a step. */
    public const DOCUMENT_KEY = '_document';

    public function __construct(private readonly NodeTypeRegistry $registry)
    {
    }

    /**
     * The one place a document's Location scope is read back out, for every
     * caller that needs it once `validate()` has already proven the shape is
     * sound (WorkflowCompiler's reference checks, WorkflowPublisher's
     * denormalisation onto the version). Absent means All (§5A) — this is
     * the single source of that default, so a caller can never reimplement
     * it slightly differently.
     *
     * @return array{scope: WorkflowLocationScope, ids: list<int>}
     */
    public static function locationScopeFrom(array $definition): array
    {
        $scope = WorkflowLocationScope::tryFrom((string) ($definition['location_scope'] ?? '')) ?? WorkflowLocationScope::All;

        $rawIds = is_array($definition['location_ids'] ?? null) ? $definition['location_ids'] : [];
        $ids = [];

        foreach (array_values($rawIds) as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return ['scope' => $scope, 'ids' => array_values(array_unique($ids))];
    }

    /**
     * @return array<string, list<string>> empty when the document is publishable
     */
    public function validate(array $definition): array
    {
        $errors = [];

        $encoded = json_encode($definition);

        if ($encoded === false) {
            return [self::DOCUMENT_KEY => ['This workflow could not be read.']];
        }

        if (strlen($encoded) > WorkflowLimits::MAX_DEFINITION_BYTES) {
            $errors[self::DOCUMENT_KEY][] = sprintf(
                'This workflow is too large to save. Keep it under %d KB.',
                (int) (WorkflowLimits::MAX_DEFINITION_BYTES / 1024),
            );
        }

        if (($definition['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[self::DOCUMENT_KEY][] = 'This workflow was built by a different version of the editor.';
        }

        $this->validateLocationScopeShape($definition, $errors);

        $root = $definition['root'] ?? null;

        if (! is_array($root)) {
            $errors[self::DOCUMENT_KEY][] = 'This workflow has no trigger.';

            return $errors;
        }

        if ((string) ($root['type'] ?? '') !== WorkflowNodeType::Trigger->value) {
            $errors[self::DOCUMENT_KEY][] = 'A workflow must start with a trigger.';
        }

        // A trigger may only ever be the root. Anywhere else it would mean a
        // second entry point into one journey.
        $seenKeys = [];
        $nodeCount = 0;

        $this->walk($root, 0, true, $errors, $seenKeys, $nodeCount);

        if ($nodeCount > WorkflowLimits::MAX_NODES_PER_VERSION) {
            $errors[self::DOCUMENT_KEY][] = sprintf(
                'This workflow has %d steps. The limit is %d — split it into more than one workflow.',
                $nodeCount,
                WorkflowLimits::MAX_NODES_PER_VERSION,
            );
        }

        return $errors;
    }

    /**
     * Location run-scope foundation (Blueprint §13, lane contract §5A) —
     * shape only, no database. Document-level, not trigger-specific:
     * "location_scope" / "location_ids" sit beside "schema_version" and
     * "root", exactly like the definition's own policy fields, because
     * execution scope belongs to the definition, never to one trigger type.
     *
     * ABSENT MEANS "all" (backward compatibility, §5A/§11): an existing
     * document saved before this feature, or a recipe that has not been
     * touched, carries neither key and must load and save without error,
     * reading as `WorkflowLocationScope::All`. A PRESENT but malformed value
     * is refused — that can only be corruption or a client bug, never a
     * legitimate absence.
     *
     * @param array<string, list<string>> $errors
     */
    private function validateLocationScopeShape(array $definition, array &$errors): void
    {
        if (! array_key_exists('location_scope', $definition)) {
            // Nothing to validate: starterDefinition() and every recipe may
            // simply omit it, and it reads as All.
            return;
        }

        $rawScope = $definition['location_scope'];
        $scope = is_string($rawScope) ? WorkflowLocationScope::tryFrom($rawScope) : null;

        if ($scope === null) {
            $errors[self::DOCUMENT_KEY][] = 'This workflow\'s Location setting is not one this product recognizes.';

            return;
        }

        $rawIds = $definition['location_ids'] ?? [];

        if (! is_array($rawIds)) {
            $errors[self::DOCUMENT_KEY][] = 'This workflow\'s selected Locations could not be read.';

            return;
        }

        $ids = [];

        foreach (array_values($rawIds) as $value) {
            $isPositiveIntLike = is_int($value) || (is_string($value) && ctype_digit($value));

            if (! $isPositiveIntLike || (int) $value <= 0) {
                $errors[self::DOCUMENT_KEY][] = 'One of this workflow\'s selected Locations is not valid.';

                return;
            }

            $ids[] = (int) $value;
        }

        $uniqueCount = count(array_unique($ids));

        if ($scope === WorkflowLocationScope::One && $uniqueCount !== 1) {
            $errors[self::DOCUMENT_KEY][] = 'Choose exactly one Location for "One location".';
        }

        if ($scope === WorkflowLocationScope::Selected && $uniqueCount < 1) {
            $errors[self::DOCUMENT_KEY][] = 'Choose at least one Location for "Selected locations".';
        }
    }

    /**
     * Walk one node and the sequence hanging off it.
     *
     * @param array<string, list<string>> $errors
     * @param array<string, true> $seenKeys
     */
    private function walk(
        array $node,
        int $depth,
        bool $isRoot,
        array &$errors,
        array &$seenKeys,
        int &$nodeCount,
    ): void {
        $nodeCount++;

        $key = $node['key'] ?? null;

        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 36) {
            $errors[self::DOCUMENT_KEY][] = 'A step is missing its identifier.';

            return;
        }

        if (isset($seenKeys[$key])) {
            // Two steps claiming one key would compile into one row and silently
            // lose a branch.
            $errors[$key][] = 'This step appears twice.';

            return;
        }

        $seenKeys[$key] = true;

        $type = WorkflowNodeType::tryFrom((string) ($node['type'] ?? ''));

        if ($type === null) {
            $errors[$key][] = 'This step type is not one this product can run.';

            return;
        }

        if ($isRoot && $type !== WorkflowNodeType::Trigger) {
            $errors[$key][] = 'A workflow must start with a trigger.';
        }

        if (! $isRoot && $type === WorkflowNodeType::Trigger) {
            $errors[$key][] = 'A workflow can only have one trigger, at the top.';
        }

        if ($depth > WorkflowLimits::MAX_BRANCH_DEPTH) {
            $errors[$key][] = sprintf(
                'This step is nested %d branches deep. The limit is %d.',
                $depth,
                WorkflowLimits::MAX_BRANCH_DEPTH,
            );

            return;
        }

        $config = $node['config'] ?? [];

        if (! is_array($config)) {
            $errors[$key][] = 'This step\'s settings could not be read.';
        } else {
            foreach ($this->registry->validateConfig($type, $config) as $message) {
                $errors[$key][] = $message;
            }
        }

        $this->walkBranches($node, $type, $key, $depth, $errors, $seenKeys, $nodeCount);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, true> $seenKeys
     */
    private function walkBranches(
        array $node,
        WorkflowNodeType $type,
        string $key,
        int $depth,
        array &$errors,
        array &$seenKeys,
        int &$nodeCount,
    ): void {
        $hasLanes = array_key_exists('yes', $node) || array_key_exists('no', $node);

        if ($type->isBranching()) {
            // An If/Else's continuation IS its two lanes, so a `next` after it
            // would be a merge — the one shape the tree rule forbids.
            if (! empty($node['next'])) {
                $errors[$key][] = 'Steps cannot continue after an If / Else. Put them inside the Yes or No path.';
            }

            foreach (['yes', 'no'] as $lane) {
                $steps = $node[$lane] ?? [];

                if (! is_array($steps)) {
                    $errors[$key][] = sprintf('The %s path could not be read.', strtoupper($lane));

                    continue;
                }

                $this->walkSequence($steps, $depth + 1, $errors, $seenKeys, $nodeCount);
            }

            return;
        }

        if ($hasLanes) {
            $errors[$key][] = 'Only an If / Else step can have Yes and No paths.';
        }

        if ($type->isTerminal() && ! empty($node['next'])) {
            $errors[$key][] = 'Nothing can follow an End step.';

            return;
        }

        $next = $node['next'] ?? [];

        if (! is_array($next)) {
            $errors[$key][] = 'The steps after this one could not be read.';

            return;
        }

        $this->walkSequence($next, $depth, $errors, $seenKeys, $nodeCount);
    }

    /**
     * A sequence is an ordered list of steps, each following the previous one.
     *
     * @param array<string, list<string>> $errors
     * @param array<string, true> $seenKeys
     */
    private function walkSequence(
        array $steps,
        int $depth,
        array &$errors,
        array &$seenKeys,
        int &$nodeCount,
    ): void {
        $steps = array_values($steps);
        $last = count($steps) - 1;

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                $errors[self::DOCUMENT_KEY][] = 'A step could not be read.';

                continue;
            }

            $type = WorkflowNodeType::tryFrom((string) ($step['type'] ?? ''));
            $stepKey = is_string($step['key'] ?? null) ? $step['key'] : self::DOCUMENT_KEY;

            // A branch or an End closes its sequence: whatever the editor put
            // after it is unreachable, and unreachable steps are refused rather
            // than silently dropped at compile time.
            if ($index !== $last && $type !== null
                && ($type->isBranching() || $type->isTerminal())) {
                $errors[$stepKey][] = $type->isTerminal()
                    ? 'Nothing can follow an End step.'
                    : 'An If / Else must be the last step in its path — its Yes and No paths carry on from there.';
            }

            $this->walk($step, $depth, false, $errors, $seenKeys, $nodeCount);
        }
    }
}
