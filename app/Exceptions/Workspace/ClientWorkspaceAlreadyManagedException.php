<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Thrown when a Client Workspace that already has an active managing Agency
 * would be linked to a second one (Addendum §2 — no multi-agency
 * co-management in V1; Implementation Contract 01 §7).
 *
 * The check that raises this runs inside the creating transaction, under the
 * Client Workspace's own row lock, so two concurrent attempts fail here
 * cleanly rather than racing into the database's unique-index backstop.
 *
 * Carries only numeric identifiers.
 */
class ClientWorkspaceAlreadyManagedException extends RuntimeException
{
    public function __construct(
        public readonly int $clientWorkspaceId,
        public readonly int $existingAgencyWorkspaceId,
    ) {
        parent::__construct(
            "Client Workspace [{$clientWorkspaceId}] is already managed by Agency Workspace [{$existingAgencyWorkspaceId}]."
        );
    }
}
