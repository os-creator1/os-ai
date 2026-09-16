<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use Carbon\CarbonInterface;

final readonly class WorkspaceEntitlementSummary
{
    /**
     * @param array<int, string> $planFeatureKeys structural packaging for the assigned tier; empty when unassigned.
     * @param array<string, WorkspaceEntitlementOverrideState> $overrides feature_key => state, only for features with an actual override row on this Workspace.
     */
    public function __construct(
        public bool $isAssigned,
        public ?WorkspacePlanTier $tier,
        public ?string $tierDisplayName,
        public ?WorkspacePlanAssignmentStatus $status,
        public ?bool $isComplimentary,
        public array $planFeatureKeys,
        public array $overrides,
        public BusinessSlotCapacityDecision $capacity,
        /**
         * Contract 03 §5 (Slice 4) — the assignment's three lifecycle
         * timestamps, carried here because this summary is the ONLY read seam
         * CustomerAccountAccessResolver is allowed to use: it must never query
         * workspace_plan_assignments itself (§3). Trailing and defaulted, so
         * both existing construction sites and every existing reader are
         * untouched.
         */
        public ?CarbonInterface $trialEndsAt = null,
        public ?CarbonInterface $graceStartedAt = null,
        public ?CarbonInterface $lockedAt = null,
    ) {
    }
}
