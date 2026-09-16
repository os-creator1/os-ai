<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Implementation Contract 02 (Location ACL Foundation) §6 — thrown by
 * LocationAccessGuard::assertUserCanAccessLocation() when
 * userCanAccessLocation() resolves to false, mirroring
 * WorkspaceAccessDeniedException's own shape exactly for the Location
 * case (necessary addition beyond §12's literal "New files" list — see
 * the implementation report — since reusing WorkspaceAccessDeniedException
 * itself would mislabel a denied Location as a denied Business).
 *
 * Carries only numeric identifiers — never Customer, User, Business or
 * Location names, address, or any other descriptive field.
 */
class LocationAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly int $locationId,
    ) {
        parent::__construct(
            "User [{$userId}] cannot access BusinessLocation [{$locationId}]."
        );
    }
}
