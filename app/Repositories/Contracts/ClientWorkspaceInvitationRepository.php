<?php

namespace App\Repositories\Contracts;

use App\Models\ClientWorkspaceInvitation;

/**
 * Plain data-access contract for client Workspace invitations
 * (Implementation Contract 07 §5). No authority, token, or expiry decision
 * lives here — every one of those is ClientInvitationManager's/
 * AgencyClientProvisioningManager's responsibility, asserted before or
 * after calling this repository, following
 * AgencyClientWorkspaceRelationshipRepository's own
 * "no business-rule logic in the repository" precedent.
 *
 * Deliberately exposes no delete: an invitation only ever transitions
 * status, never disappears, matching this codebase's general audit-history
 * posture.
 */
interface ClientWorkspaceInvitationRepository extends BaseRepository
{
    public function create(array $attributes): ClientWorkspaceInvitation;

    public function findByUid(string $uid): ?ClientWorkspaceInvitation;

    /**
     * The invitations THIS Agency Workspace sent that can still be accepted — Pending and not
     * yet past their expiry — newest first, at most $limit. Keyed by the Agency Workspace id
     * alone, so another Agency's invitations are unreachable by construction. Plain data access:
     * who may see them is the caller's (the Clients page's) gate.
     *
     * @return \Illuminate\Support\Collection<int, ClientWorkspaceInvitation>
     */
    public function pendingForAgencyWorkspace(int $agencyWorkspaceId, int $limit = 25): \Illuminate\Support\Collection;

    /**
     * Row-locking variant of findByUid(), for callers that must hold the
     * row lock for the duration of a transaction — the same shape
     * WorkspaceRepository::findForUpdate() established. Used by
     * AgencyClientProvisioningManager to make acceptance single-use and
     * concurrency-safe: a second concurrent acceptance attempt blocks on
     * this lock until the first transaction commits (Accepted) or rolls
     * back, and then observes the now-non-Pending status.
     */
    public function findByUidForUpdate(string $uid): ?ClientWorkspaceInvitation;

    /**
     * Narrow, single-purpose status transition — writes only the Revoked
     * status, matching AgencyClientWorkspaceRelationshipRepository::
     * markTerminated()'s own convention of never accepting an arbitrary
     * attribute array.
     */
    public function markRevoked(ClientWorkspaceInvitation $invitation): ClientWorkspaceInvitation;

    /**
     * Narrow, single-purpose status transition for the acceptance-time
     * orchestrator: writes Accepted, accepted_at and
     * created_client_workspace_id together, atomically with the caller's
     * own transaction.
     */
    public function markAccepted(
        ClientWorkspaceInvitation $invitation,
        int $createdClientWorkspaceId,
    ): ClientWorkspaceInvitation;
}
