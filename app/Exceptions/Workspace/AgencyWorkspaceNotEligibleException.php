<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when the Workspace asked to manage a Client Workspace is not on the
 * Agency plan tier at the moment the relationship is established
 * (Implementation Contract 01 §6).
 *
 * This is a one-time gate on ESTABLISHING the link, never a standing
 * guarantee: an Active relationship whose Agency Workspace is later
 * downgraded stays Active, and every Agency-only capability that consumes it
 * re-checks current entitlement for itself.
 *
 * Carries only a numeric Workspace identifier and the plan tier that was
 * found, which is structural product data, not customer data.
 */
class AgencyWorkspaceNotEligibleException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?string $tier,
    ) {
        parent::__construct(sprintf(
            'Workspace [%d] is not on the Agency plan tier (found [%s]) and cannot manage Client Workspaces.',
            $workspaceId,
            $tier ?? 'unassigned',
        ));
    }
}
