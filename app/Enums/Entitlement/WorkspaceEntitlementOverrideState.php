<?php

namespace App\Enums\Entitlement;

/**
 * No "inherit" case exists — the absence of a workspace_entitlement_overrides
 * row already means inherit (RFC-004 §10.5).
 */
enum WorkspaceEntitlementOverrideState: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
