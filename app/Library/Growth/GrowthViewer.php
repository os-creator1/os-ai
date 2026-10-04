<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * Who is looking at the Growth Center, and which Locations that person may
 * see — resolved ONCE per request and handed to every reader BEFORE it
 * aggregates anything (Growth Center §54: filter first, aggregate second).
 *
 * Location access is decided by LocationAccessGuard and only by it; this class
 * is an adapter that uses its constant-query bulk method. It also answers the
 * one question the Growth Score needs: does this actor see EVERYTHING in the
 * Business? The score is a Business-wide aggregate, so an actor who cannot see
 * every Location is not shown it — a number built from facts they may not see
 * would leak them.
 */
final class GrowthViewer
{
    /**
     * @param  array<int, int>  $accessibleLocationIds
     */
    private function __construct(
        public readonly int $userId,
        public readonly array $accessibleLocationIds,
        public readonly bool $fullAccess,
    ) {
    }

    public static function resolve(LocationAccessGuard $guard, int $userId, Business $business): self
    {
        $accessible = array_values(array_map('intval', $guard->accessibleLocationIdsForBusiness($userId, $business)));

        $all = BusinessLocation::query()->where('business_id', $business->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return new self($userId, $accessible, count(array_diff($all, $accessible)) === 0);
    }

    /** For tests and system callers that already know the answer. */
    public static function forTest(int $userId, array $accessibleLocationIds, bool $fullAccess): self
    {
        return new self($userId, $accessibleLocationIds, $fullAccess);
    }

    public function mayViewLocation(?int $locationId): bool
    {
        return $locationId === null || $this->fullAccess || in_array($locationId, $this->accessibleLocationIds, true);
    }

    /** Whether the actor may see the Business-wide Growth Score. */
    public function maySeeScore(): bool
    {
        return $this->fullAccess;
    }
}
