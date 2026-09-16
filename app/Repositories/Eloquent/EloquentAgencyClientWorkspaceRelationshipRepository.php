<?php

namespace App\Repositories\Eloquent;

use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * @see AgencyClientWorkspaceRelationshipRepository for the contract this
 *      implements, and for why it exposes no delete.
 */
class EloquentAgencyClientWorkspaceRelationshipRepository extends EloquentBaseRepository implements AgencyClientWorkspaceRelationshipRepository
{
    public function __construct(AgencyClientWorkspaceRelationship $relationship)
    {
        parent::__construct($relationship);
    }

    public function create(array $attributes): AgencyClientWorkspaceRelationship
    {
        /** @var AgencyClientWorkspaceRelationship $relationship */
        $relationship = $this->make(Arr::only($attributes, [
            'agency_workspace_id',
            'client_workspace_id',
            'status',
            'established_by_user_id',
            'established_at',
        ]));
        $relationship->save();

        return $relationship;
    }

    public function findById(int $id): ?AgencyClientWorkspaceRelationship
    {
        return $this->query()->find($id);
    }

    public function findForUpdate(int $id): ?AgencyClientWorkspaceRelationship
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findActiveForClientWorkspace(int $clientWorkspaceId): ?AgencyClientWorkspaceRelationship
    {
        return $this->activeForClientQuery($clientWorkspaceId)->first();
    }

    public function findActiveForClientWorkspaceForUpdate(int $clientWorkspaceId): ?AgencyClientWorkspaceRelationship
    {
        return $this->activeForClientQuery($clientWorkspaceId)->lockForUpdate()->first();
    }

    public function activeForAgencyWorkspace(int $agencyWorkspaceId): Collection
    {
        return $this->query()
            ->where('agency_workspace_id', $agencyWorkspaceId)
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->orderBy('id')
            ->get();
    }

    public function historyForClientWorkspace(int $clientWorkspaceId): Collection
    {
        return $this->query()
            ->where('client_workspace_id', $clientWorkspaceId)
            ->orderBy('id')
            ->get();
    }

    public function markTerminated(
        AgencyClientWorkspaceRelationship $relationship,
        int $terminatedByUserId,
        string $reason,
    ): AgencyClientWorkspaceRelationship {
        $relationship->status = AgencyClientRelationshipStatus::Terminated;
        $relationship->terminated_by_user_id = $terminatedByUserId;
        $relationship->terminated_at = now();
        $relationship->termination_reason = $reason;
        $relationship->save();

        // active_client_workspace_id is a stored generated column: MySQL has
        // just recomputed it to NULL, and this model instance still holds the
        // pre-termination value until it is read back.
        return $relationship->refresh();
    }

    private function activeForClientQuery(int $clientWorkspaceId)
    {
        return $this->query()
            ->where('client_workspace_id', $clientWorkspaceId)
            ->where('status', AgencyClientRelationshipStatus::Active->value);
    }
}
