<?php

namespace App\Events\Workspace;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An Agency Workspace's management of a Client Workspace ended (Addendum §2,
 * Implementation Contract 01 §10). The relationship row itself survives as
 * history; this announces the transition.
 *
 * actorUserId is the REAL acting User — the Agency Workspace owner, or the
 * admin-side actor holding the dedicated termination permission.
 */
class AgencyClientRelationshipTerminated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $relationshipId,
        public readonly int $agencyWorkspaceId,
        public readonly int $clientWorkspaceId,
        public readonly int $actorUserId,
        public readonly string $reason,
    ) {
    }
}
