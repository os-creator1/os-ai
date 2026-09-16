<?php

namespace App\Enums\Workspace;

/**
 * The lifecycle of one Agency -> Client Workspace management relationship
 * (V1 Architecture Decision Addendum §2, Implementation Contract 01 §5).
 *
 * Two states only, matching WorkspaceTransitionType's own minimal-enum
 * precedent: establishing the relationship is a single atomic action, not a
 * multi-step negotiation, so there is no Pending or Suspended state to
 * represent. Terminated is never a delete — the row survives as the audit
 * record of a management relationship that once existed (Addendum §2,
 * "history MUST be preserved").
 */
enum AgencyClientRelationshipStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';
}
