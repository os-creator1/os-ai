<?php

namespace App\Library\BusinessEmail;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Library\Workspace\LocationAccessGuard;
use Illuminate\Support\Collection;

/**
 * The customer-facing Email surface's Location ACL — a thin adapter over the
 * ONE canonical LocationAccessGuard. It adds no ACL algorithm of its own.
 *
 * Semantics follow the established CRM precedent (ContactsController /
 * ChatBoxController, Contract 08B): a record with a proven `location_id` is
 * re-checked against the guard from persistence; a record whose Location was
 * never proven (`NULL`, legacy) is never denied on its own — the actor's
 * Business-level access, already confirmed by the tenancy chain, governs.
 *
 * LISTS never call the guard per row. They take the actor's accessible
 * Location ids ONCE (accessibleLocationIds) and push the scope into SQL, so a
 * list costs the same number of queries for any number of rows.
 */
final class BusinessEmailLocationScope
{
    public function __construct(private readonly LocationAccessGuard $guard)
    {
    }

    /**
     * Ids of the Business's Locations this actor may work in.
     *
     * @return list<int>
     */
    public function accessibleLocationIds(int $userId, Business $business): array
    {
        return array_values(array_map('intval', $this->guard->accessibleLocationIdsForBusiness($userId, $business)));
    }

    /**
     * Narrows a business_email_messages query (already scoped to ONE Business)
     * to what this actor may see: rows in their Location reach, plus rows whose
     * Location is unresolved (NULL) ONLY when the actor can reach every
     * Location of the Business. An unresolved inbound email is therefore never
     * shown to a user limited to some Locations.
     */
    public function restrictMessages(\Illuminate\Database\Eloquent\Builder $query, int $userId, Business $business): void
    {
        $reachable = $this->accessibleLocationIds($userId, $business);

        $hasUnreachable = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->when($reachable !== [], fn ($q) => $q->whereNotIn('id', $reachable))
            ->exists();

        $query->where(function ($where) use ($reachable, $hasUnreachable): void {
            $where->whereIn('location_id', $reachable);

            if (! $hasUnreachable) {
                $where->orWhereNull('location_id');
            }
        });
    }

    /**
     * May this actor act on this (already tenancy-verified) Contact? A Contact
     * with a persisted Location must be in the actor's Location reach,
     * re-derived from persistence; a NULL-Location Contact is not denied here.
     */
    public function contactAccessible(int $userId, Contacts $contact): bool
    {
        if ($contact->location_id === null) {
            return true;
        }

        $location = BusinessLocation::query()->find($contact->location_id);

        return $location !== null
            && (int) $location->business_id === (int) $contact->business_id
            && $this->guard->userCanAccessLocation($userId, $location);
    }

    /**
     * The Business's ACTIVE Locations the actor may send from, and how many
     * active Locations the Business has in total (the form only needs to ask
     * for a Location when there is more than one to choose from).
     *
     * @param list<int> $accessibleIds
     * @return array{selectable: Collection<int, BusinessLocation>, activeTotal: int}
     */
    public function activeLocations(Business $business, array $accessibleIds): array
    {
        $active = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->orderBy('id')
            ->get(['id', 'uid', 'name']);

        $allowed = array_flip($accessibleIds);

        return [
            'selectable' => $active->filter(fn (BusinessLocation $location) => isset($allowed[(int) $location->id]))->values(),
            'activeTotal' => $active->count(),
        ];
    }
}
