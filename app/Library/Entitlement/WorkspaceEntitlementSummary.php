<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;

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
    ) {
    }
}
