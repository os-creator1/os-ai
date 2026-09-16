<?php

namespace App\Repositories\Contracts;

use App\Models\AgencyClientWorkspaceRelationship;
use Illuminate\Support\Collection;

/**
 * Plain data-access contract for the Agency -> Client Workspace management
 * relationship (Implementation Contract 01 §5/§7). No authority, entitlement,
 * self-link or uniqueness decision lives here — every one of those is
 * AgencyClientRelationshipManager's responsibility, asserted before it calls
 * this repository, following WorkspacePlanAssignmentRepository's own
 * "no business-rule logic in the repository" precedent.
 *
 * Deliberately exposes no delete of any kind. Addendum §2 requires management
 * history to survive termination, so a relationship only ever transitions
 * status — the same posture RFC-003 §27 already takes for Workspace,
 * WorkspaceMembership and Business.
 */
interface AgencyClientWorkspaceRelationshipRepository extends BaseRepository
{
    public function create(array $attributes): AgencyClientWorkspaceRelationship;

    public function findById(int $id): ?AgencyClientWorkspaceRelationship;

    /**
     * Row-locking variant of findById(), for callers that must hold the row
     * lock for the duration of a transaction — the same shape
     * WorkspaceRepository::findForUpdate() established.
     */
    public function findForUpdate(int $id): ?AgencyClientWorkspaceRelationship;

    /**
     * The one active managing Agency of this Client Workspace, or null when
     * it has none. Terminated rows are never returned, however many of them
     * this Client Workspace has accumulated.
     */
    public function findActiveForClientWorkspace(int $clientWorkspaceId): ?AgencyClientWorkspaceRelationship;

    /**
     * A locking/current read — deliberately NOT an ordinary
     * consistent-snapshot query. Guarantees visibility of an Active row
     * committed on another connection before this caller acquired its own
     * Workspace row locks, which MySQL/InnoDB REPEATABLE READ would
     * otherwise hide behind the transaction's earlier snapshot. Required by
     * AgencyClientRelationshipManager::create()'s duplicate check, mirroring
     * WorkspacePlanAssignmentRepository::hasNonComplimentaryForCatalogForUpdate()'s
     * identical reasoning.
     */
    public function findActiveForClientWorkspaceForUpdate(int $clientWorkspaceId): ?AgencyClientWorkspaceRelationship;

    /**
     * Every Client Workspace this Agency Workspace currently manages, oldest
     * relationship first.
     *
     * @return Collection<int, AgencyClientWorkspaceRelationship>
     */
    public function activeForAgencyWorkspace(int $agencyWorkspaceId): Collection;

    /**
     * Every relationship this Client Workspace has ever had, active and
     * terminated alike, oldest first — the audit read Addendum §2's
     * history-preservation rule exists for.
     *
     * @return Collection<int, AgencyClientWorkspaceRelationship>
     */
    public function historyForClientWorkspace(int $clientWorkspaceId): Collection;

    /**
     * Narrow, single-purpose status transition — it writes only the four
     * termination columns and never accepts an arbitrary attribute array,
     * matching WorkspaceRepository::setActive()/transferOwnership()'s own
     * convention. Dispatches no event; that is the manager's responsibility.
     */
    public function markTerminated(
        AgencyClientWorkspaceRelationship $relationship,
        int $terminatedByUserId,
        string $reason,
    ): AgencyClientWorkspaceRelationship;
}
