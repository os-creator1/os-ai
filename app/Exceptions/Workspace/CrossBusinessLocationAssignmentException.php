<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Implementation Contract 02 (Location ACL Foundation) §5's transitional
 * cross-check invariant, mirroring CrossWorkspaceAssignmentException's own
 * shape. Thrown when a Location-level grant is attempted for a Business
 * the membership cannot already reach via the still-live
 * business_access_scope/workspace_membership_businesses mechanism — a
 * Location-level grant must never be wider than the Business-level grant
 * already in force during the transition (removed only once Contract 13
 * enforces one Business per Workspace and Contract 14 retires the
 * Business-scope pivot).
 *
 * Distinct from CrossWorkspaceAssignmentException, which this repository
 * still throws first, unchanged, for the ordinary Workspace-boundary case
 * (the Location's Business belongs to a different Workspace entirely).
 * This exception covers the narrower, same-Workspace-but-unreachable-
 * Business case.
 */
class CrossBusinessLocationAssignmentException extends RuntimeException
{
}
