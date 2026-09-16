<?php

namespace App\Repositories\Eloquent;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\CrossBusinessLocationAssignmentException;
use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Models\BusinessLocation;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipLocation;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 02 (Location ACL Foundation) §3/§5/§7 — mirrors
 * EloquentWorkspaceMembershipBusinessRepository's exact shape and
 * transactional discipline for the new workspace_membership_locations
 * pivot, with one addition: the transitional composed check (§5) that
 * EloquentWorkspaceMembershipBusinessRepository has no equivalent of,
 * since the Business pivot is the authority being composed with, not a
 * consumer of it.
 */
class EloquentWorkspaceMembershipLocationRepository extends EloquentBaseRepository implements WorkspaceMembershipLocationRepository
{
    public function __construct(
        WorkspaceMembershipLocation $assignment,
        private readonly WorkspaceMembershipBusinessRepository $membershipBusinessRepository,
    ) {
        parent::__construct($assignment);
    }

    public function assignedLocationIds(WorkspaceMembership $membership): Collection
    {
        return $this->query()
            ->where('workspace_membership_id', $membership->id)
            ->pluck('business_location_id');
    }

    /**
     * Memoized per membership+location for the life of the current
     * request only, mirroring isAssigned()'s own Business-pivot
     * precedent exactly (Automations V2 §18, Phase 2's shared
     * query-budget optimization).
     */
    public function isAssigned(WorkspaceMembership $membership, int $businessLocationId): bool
    {
        return $this->rememberForRequest(
            "membership_location:isAssigned:{$membership->id}:{$businessLocationId}",
            fn () => $this->query()
                ->where('workspace_membership_id', $membership->id)
                ->where('business_location_id', $businessLocationId)
                ->exists(),
        );
    }

    public function assign(WorkspaceMembership $membership, BusinessLocation $location): WorkspaceMembershipLocation
    {
        $this->guardAssignable($membership, $location);

        /** @var WorkspaceMembershipLocation $assignment */
        $assignment = $this->query()->firstOrCreate([
            'workspace_membership_id' => $membership->id,
            'business_location_id' => $location->id,
        ]);
        $this->forgetRequestCache("membership_location:isAssigned:{$membership->id}:{$location->id}");

        return $assignment;
    }

    public function syncForMembership(WorkspaceMembership $membership, array $businessLocationIds): Collection
    {
        $normalizedIds = collect($businessLocationIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($normalizedIds->isEmpty()) {
            $this->query()->where('workspace_membership_id', $membership->id)->delete();

            return new Collection();
        }

        // Existence check first (throws ModelNotFoundException), then both
        // guardAssignable() checks — all complete before any write below,
        // so one invalid ID can never leave a partial sync.
        $locations = BusinessLocation::query()->findOrFail($normalizedIds->all());

        foreach ($locations as $location) {
            $this->guardAssignable($membership, $location);
        }

        $result = DB::transaction(function () use ($membership, $normalizedIds) {
            $this->query()
                ->where('workspace_membership_id', $membership->id)
                ->whereNotIn('business_location_id', $normalizedIds)
                ->delete();

            foreach ($normalizedIds as $businessLocationId) {
                $this->query()->firstOrCreate([
                    'workspace_membership_id' => $membership->id,
                    'business_location_id' => $businessLocationId,
                ]);
            }

            return $this->query()
                ->where('workspace_membership_id', $membership->id)
                ->get();
        });

        $this->forgetRequestCachePrefixed("membership_location:isAssigned:{$membership->id}:");

        return $result;
    }

    public function unassign(WorkspaceMembership $membership, int $businessLocationId): void
    {
        $this->query()
            ->where('workspace_membership_id', $membership->id)
            ->where('business_location_id', $businessLocationId)
            ->delete();
        $this->forgetRequestCache("membership_location:isAssigned:{$membership->id}:{$businessLocationId}");
    }

    public function removeAllForBusinessInWorkspace(int $businessId, int $sourceWorkspaceId): Collection
    {
        $grants = $this->query()
            ->whereHas('businessLocation', fn ($query) => $query->where('business_id', $businessId))
            ->whereHas('membership', fn ($query) => $query->where('workspace_id', $sourceWorkspaceId))
            ->get();

        if ($grants->isEmpty()) {
            return $grants;
        }

        $this->query()->whereIn('id', $grants->pluck('id'))->delete();

        foreach ($grants as $grant) {
            $this->forgetRequestCache("membership_location:isAssigned:{$grant->workspace_membership_id}:{$grant->business_location_id}");
        }

        return $grants;
    }

    /** Contract 02 §5's two-step, in-order check. */
    private function guardAssignable(WorkspaceMembership $membership, BusinessLocation $location): void
    {
        $business = $location->business;

        if ($business === null || (int) $business->workspace_id !== (int) $membership->workspace_id) {
            throw new CrossWorkspaceAssignmentException(
                "WorkspaceMembership [{$membership->id}] belongs to Workspace [{$membership->workspace_id}]; " .
                'BusinessLocation [' . $location->id . '] belongs to Business [' . ($business->id ?? 'null')
                . '] in Workspace [' . ($business->workspace_id ?? 'null') . '].'
            );
        }

        $canReachBusiness = $membership->business_access_scope === WorkspaceBusinessAccessScope::All
            || $this->membershipBusinessRepository->isAssigned($membership, $business->id);

        if (! $canReachBusiness) {
            throw new CrossBusinessLocationAssignmentException(
                "WorkspaceMembership [{$membership->id}] is not Business-level-granted access to Business "
                . "[{$business->id}]; a Location-level grant can never be wider than the Business-level grant "
                . 'already in force during the transition (Contract 02 §5).'
            );
        }
    }
}
