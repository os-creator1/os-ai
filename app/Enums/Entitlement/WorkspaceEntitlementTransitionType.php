<?php

namespace App\Enums\Entitlement;

/**
 * Transition types durably audited in workspace_entitlement_transitions
 * (RFC-004 §10.4/§21). M1's own code only ever writes PlanAssigned (via the
 * Milestone-1 backfill, WorkspaceEntitlementBackfillV1); the next eight are
 * written by M2's actor-driven EntitlementManager mutations.
 *
 * Customer Experience Slice 1A adds the last two, for PHYSICAL-LOCATION
 * capacity (RFC-004 §33.4, contract §23.2 step 6) — to this existing
 * vocabulary, not a new audit table. Both rows stay Workspace-scoped; the
 * nullable JSON `payload` names the affected Business(es) and their counts.
 */
enum WorkspaceEntitlementTransitionType: string
{
    case PlanAssigned = 'plan_assigned';
    case PlanChanged = 'plan_changed';
    case PlanStatusChanged = 'plan_status_changed';
    case ComplimentaryGranted = 'complimentary_granted';
    case ComplimentaryRevoked = 'complimentary_revoked';
    case AdditionalBusinessSlotsChanged = 'additional_business_slots_changed';
    case EntitlementOverrideAllowed = 'entitlement_override_allowed';
    case EntitlementOverrideDenied = 'entitlement_override_denied';
    case EntitlementOverrideReverted = 'entitlement_override_reverted';

    /** Slice 1A — one Business's additional-location allocation changed. */
    case AdditionalLocationSlotsChanged = 'additional_location_slots_changed';

    /**
     * Slice 1A — complimentary grandfathered capacity was recorded or
     * changed: by the capacity-correction backfill, a plan change that
     * lowers location capacity, or archiving a grandfathered excess
     * location (which consumes that allowance rather than freeing it).
     */
    case CapacityGrandfathered = 'capacity_grandfathered';
}
