<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\Workspace\CrossWorkspaceAssignmentException;
use App\Models\Business;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentWorkspaceMembershipBusinessRepository extends EloquentBaseRepository implements WorkspaceMembershipBusinessRepository
{
    public function __construct(WorkspaceMembershipBusiness $assignment)
    {
        parent::__construct($assignment);
    }

    public function assignedBusinessIds(WorkspaceMembership $membership): Collection
    {
        return $this->query()
            ->where('workspace_membership_id', $membership->id)
            ->pluck('business_id');
    }

    public function isAssigned(WorkspaceMembership $membership, int $businessId): bool
    {
        return $this->query()
            ->where('workspace_membership_id', $membership->id)
            ->where('business_id', $businessId)
            ->exists();
    }

    public function assign(WorkspaceMembership $membership, Business $business): WorkspaceMembershipBusiness
    {
        $this->guardSameWorkspace($membership, $business);

        /** @var WorkspaceMembershipBusiness $assignment */
        $assignment = $this->query()->firstOrCreate([
            'workspace_membership_id' => $membership->id,
            'business_id' => $business->id,
        ]);

        return $assignment;
    }

    public function syncForMembership(WorkspaceMembership $membership, array $businessIds): Collection
    {
        $normalizedIds = collect($businessIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($normalizedIds->isEmpty()) {
            $this->query()->where('workspace_membership_id', $membership->id)->delete();

            return new Collection();
        }

        // Existence check first (throws ModelNotFoundException), then the
        // Workspace-match check — both complete before any write below, so
        // one invalid ID can never leave a partial sync (RFC-003 §12.3).
        $businesses = Business::query()->findOrFail($normalizedIds->all());

        foreach ($businesses as $business) {
            $this->guardSameWorkspace($membership, $business);
        }

        return DB::transaction(function () use ($membership, $normalizedIds) {
            $this->query()
                ->where('workspace_membership_id', $membership->id)
                ->whereNotIn('business_id', $normalizedIds)
                ->delete();

            foreach ($normalizedIds as $businessId) {
                $this->query()->firstOrCreate([
                    'workspace_membership_id' => $membership->id,
                    'business_id' => $businessId,
                ]);
            }

            return $this->query()
                ->where('workspace_membership_id', $membership->id)
                ->get();
        });
    }

    public function unassign(WorkspaceMembership $membership, int $businessId): void
    {
        $this->query()
            ->where('workspace_membership_id', $membership->id)
            ->where('business_id', $businessId)
            ->delete();
    }

    private function guardSameWorkspace(WorkspaceMembership $membership, Business $business): void
    {
        if ((int) $business->workspace_id !== (int) $membership->workspace_id) {
            throw new CrossWorkspaceAssignmentException(
                "WorkspaceMembership [{$membership->id}] belongs to Workspace [{$membership->workspace_id}]; " .
                'Business [' . $business->id . '] belongs to Workspace [' . ($business->workspace_id ?? 'null') . '].'
            );
        }
    }
}
