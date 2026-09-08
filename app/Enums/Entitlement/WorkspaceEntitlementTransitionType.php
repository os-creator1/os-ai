<?php

namespace App\Enums\Entitlement;

/**
 * Transition types durably audited in workspace_entitlement_transitions
 * (RFC-004 §10.4/§21). M1's own code only ever writes PlanAssigned (via the
 * Milestone-1 backfill, WorkspaceEntitlementBackfillV1) — the rest are
 * written by M2's actor-driven EntitlementManager mutations.
 *
 * Customer Experience Slice 1A adds the final two, for PHYSICAL-LOCATION
 * capacity (contract §23.2 step 6). They are added to this existing
 * vocabulary rather than to a new audit table, exactly as the contract
 * requires. Physical-location capacity is a distinct concern from
 * Business/client-account capacity and never reuses the
 * additional_business_slots columns.
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

    /**
     * Slice 1A — a paid additional physical-location allocation was added
     * or cancelled for one Business. The payload names the Business and
     * both counts.
     */
    case AdditionalLocationSlotsChanged = 'additional_location_slots_changed';

    /**
     * Slice 1A — the one-time capacity correction recorded which existing
     * active locations were above the newly included allowance and are
     * therefore complimentary. Its immutable payload names every affected
     * Business and that Business's exact grandfathered count (§7.5.2).
     */
    case LocationCapacityGrandfathered = 'location_capacity_grandfathered';
}
