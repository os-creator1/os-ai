<?php

namespace App\Enums\Entitlement;

/**
 * Nine transition types durably audited in workspace_entitlement_transitions
 * (RFC-004 §10.4/§21). M1's own code only ever writes PlanAssigned (via the
 * Milestone-1 backfill, WorkspaceEntitlementBackfillV1) — the other eight
 * are structurally supported here but unexercised until M2's actor-driven
 * EntitlementManager mutations exist.
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
}
