<?php

namespace App\Library\Navigation;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Library\Workspace\LocationAccessGuard;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Collection;

/**
 * Blueprint §7 — the shell's Location switcher state.
 *
 * A Location is a lens INSIDE the current Business, never a second tenancy
 * layer. This class owns two things only:
 *
 *  - options(): the ACTIVE Locations the actor may already reach, decided
 *    entirely by LocationAccessGuard. Nothing here grants access;
 *  - selectedFor(): the remembered choice (session, per Business), re-validated
 *    against options() on every read, so a revoked grant, an archived Location
 *    or a forged uid simply stops being the selection. No selection means "all
 *    my Locations", which is the unscoped behaviour every screen had before.
 *
 * The Workspace/Business context is untouched by a Location choice.
 */
final class CurrentLocation
{
    public const SESSION_KEY = 'customer_location';

    public function __construct(
        private readonly LocationAccessGuard $guard,
        private readonly BusinessLocationRepository $locations,
    ) {
    }

    /** @return Collection<int, BusinessLocation> */
    public function options(Business $business, int $userId): Collection
    {
        $accessible = $this->guard->accessibleLocationIdsForBusiness($userId, $business);

        return $this->locations->forBusiness($business)
            ->filter(fn (BusinessLocation $location) => $location->isActive() && in_array((int) $location->id, $accessible, true))
            ->values();
    }

    public function showsSwitcher(Business $business, int $userId): bool
    {
        return $this->options($business, $userId)->count() > 1;
    }

    public function selectedFor(Business $business, int $userId): ?BusinessLocation
    {
        $uid = (session(self::SESSION_KEY) ?? [])[$business->uid] ?? null;

        // The common case (nothing chosen) costs no query at all.
        if (! is_string($uid) || $uid === '') {
            return null;
        }

        $options = $this->options($business, $userId);

        return $options->count() > 1 ? $options->firstWhere('uid', $uid) : null;
    }

    public function select(Business $business, ?BusinessLocation $location): void
    {
        $all = session(self::SESSION_KEY) ?? [];

        if ($location === null) {
            unset($all[$business->uid]);
        } else {
            $all[$business->uid] = $location->uid;
        }

        session([self::SESSION_KEY => $all]);
    }
}
