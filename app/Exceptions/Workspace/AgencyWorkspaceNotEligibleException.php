<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when the Workspace asked to manage a Client Workspace lacks Agency
 * management eligibility at the moment the relationship is established
 * (Implementation Contract 01 §6, as corrected by Contract 04): it is not on
 * the Agency plan tier, or it is on the Agency tier but its effective account
 * access is not usable (Locked, Inactive or Suspended, as decided by
 * CustomerAccountAccessResolver).
 *
 * This is a one-time gate on ESTABLISHING the link, never a standing
 * guarantee: an Active relationship whose Agency Workspace later loses
 * eligibility stays Active, and every Agency-only capability that consumes it
 * re-checks current eligibility for itself.
 *
 * Carries only a numeric Workspace identifier, the plan tier found and — when
 * the tier was right — the account access state found: structural product
 * data, not customer data.
 */
class AgencyWorkspaceNotEligibleException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?string $tier,
        public readonly ?string $accessState = null,
    ) {
        parent::__construct($accessState === null
            ? sprintf(
                'Workspace [%d] is not on the Agency plan tier (found [%s]) and cannot manage Client Workspaces.',
                $workspaceId,
                $tier ?? 'unassigned',
            )
            : sprintf(
                'Workspace [%d] is on the Agency plan tier but its account is not usable (found [%s]) and cannot manage Client Workspaces.',
                $workspaceId,
                $accessState,
            ));
    }
}
