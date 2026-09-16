<?php

namespace App\Enums\Workspace;

/**
 * Implementation Contract 02 (Location ACL Foundation) §5 — mirrors
 * WorkspaceBusinessAccessScope's exact two-case shape, as a new, sibling
 * axis on WorkspaceMembership. Orthogonal to WorkspaceMembershipRole and
 * to WorkspaceBusinessAccessScope, exactly as those two are already
 * orthogonal to each other (Addendum §4, RFC-003 §7.5).
 */
enum LocationAccessScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
