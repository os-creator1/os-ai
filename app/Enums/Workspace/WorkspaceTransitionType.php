<?php

namespace App\Enums\Workspace;

/**
 * The two operations workspace_transitions durably persists (RFC-003
 * Milestone 2 domain contract, corrected report §5/§7/§11) — ownership
 * transfer and cross-Workspace Business reassignment. Deliberately not 1:1
 * with the 14 domain events: durable audit persistence and event dispatch
 * are decoupled concerns, and the other 12 event types never write a
 * workspace_transitions row.
 */
enum WorkspaceTransitionType: string
{
    case OwnershipTransferred = 'workspace_ownership_transferred';
    case BusinessReassigned = 'business_reassigned_to_workspace';
}
