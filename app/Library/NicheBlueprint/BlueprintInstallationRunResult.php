<?php

namespace App\Library\NicheBlueprint;

/**
 * Contract 20 §7.2/§9.1 — what one installation run did, for the operator
 * surfaces (the `blueprint:install-missing` command) and for tests.
 *
 * Carries no authority and is never persisted. Every durable fact lives in
 * `business_blueprint_component_installations`; this is a report of the run
 * that produced them.
 *
 * `abortReason` is non-null exactly when the run stopped BEFORE its
 * per-component loop and therefore wrote nothing at all (§9.1's precondition,
 * §7.1's resolution outcomes). An aborted run is not an error: a Business in a
 * niche with no Blueprint is an ordinary, supported state, and a Business
 * whose plan has not landed yet is retried automatically by the
 * `WorkspacePlanAssigned` trigger.
 */
final readonly class BlueprintInstallationRunResult
{
    /** The Workspace that owns the Business could not be resolved. Fail closed. */
    public const ABORT_WORKSPACE_UNRESOLVED = 'workspace_unresolved';

    /** The Workspace has no owner user id to hand `decide()`. Fail closed. */
    public const ABORT_WORKSPACE_OWNER_UNRESOLVED = 'workspace_owner_unresolved';

    /** §9.1's precondition: no plan assignment at all. Zero records, retried on assignment. */
    public const ABORT_WORKSPACE_PLAN_UNASSIGNED = 'workspace_plan_unassigned';

    /** §7.1 case 3: nothing matched. Ordinary, supported, not an error. */
    public const ABORT_NO_BLUEPRINT = 'no_blueprint';

    /** §7.1 case 2: more than one broad-industry fallback matched. Fail closed, never guess. */
    public const ABORT_AMBIGUOUS_BROAD_INDUSTRY = 'ambiguous_broad_industry';

    /** The Blueprint exists but has never been published. Nothing to decide about. */
    public const ABORT_NO_PUBLISHED_VERSION = 'no_published_version';

    public function __construct(
        public ?string $abortReason = null,
        public ?int $blueprintId = null,
        public ?int $versionNumber = null,
        public int $installed = 0,
        public int $skippedUnentitled = 0,
        public int $skippedUnavailable = 0,
        public int $failed = 0,
        public int $alreadyDecided = 0,
    ) {
    }

    public static function aborted(string $reason, ?int $blueprintId = null): self
    {
        return new self(abortReason: $reason, blueprintId: $blueprintId);
    }

    public function wasAborted(): bool
    {
        return $this->abortReason !== null;
    }

    /** Components this run actually reached a decision for, excluding pre-existing ones. */
    public function decided(): int
    {
        return $this->installed + $this->skippedUnentitled + $this->skippedUnavailable + $this->failed;
    }
}
