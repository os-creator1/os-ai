<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An Agency Workspace began managing a Client Workspace (Addendum §2,
 * Implementation Contract 01 §10).
 *
 * actorUserId is the REAL acting User, never a viewed or impersonated
 * identity — the convention this slice sets for Contract 04's View As to
 * inherit.
 */
class AgencyClientRelationshipEstablished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $relationshipId,
        public readonly int $agencyWorkspaceId,
        public readonly int $clientWorkspaceId,
        public readonly int $actorUserId,
    ) {
    }
}
