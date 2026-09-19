<?php

namespace App\Library\Seo;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\Seo\SeoReviewException;
use App\Library\Workspace\LocationAccessGuard;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * Contract 18 §8.6 / §10 — the ONE authorization prelude both review
 * managers run inside their transaction, so it cannot drift between them.
 *
 * It adds no authority of its own. Business access is
 * WorkspaceManager::userCanAccessBusiness(), Location access is
 * LocationAccessGuard, and both are re-derived from persistence — no
 * caller-supplied Business or Location model is trusted. Locking the
 * Business row is what serializes every review write for that Business (the
 * cooldown check-then-insert cannot interleave with another).
 *
 * Must be called inside a DB transaction: the row lock is released at its end.
 */
final class SeoReviewWriteGate
{
    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly LocationAccessGuard $locationGuard,
    ) {
    }

    /**
     * @throws SeoReviewException access denied
     */
    public function lockAuthorizedBusiness(int $actorUserId, Business $business): Business
    {
        $locked = Business::query()->whereKey($business->id)->lockForUpdate()->first();

        if ($locked === null
            || $locked->status !== BusinessStatus::Active
            || ! $this->workspaceManager->userCanAccessBusiness($actorUserId, $locked)) {
            throw SeoReviewException::accessDenied();
        }

        return $locked;
    }

    /**
     * A Location of THIS Business, by uid, that the actor may access and that
     * is still operational (§10.5). Unknown, foreign and inaccessible are all
     * the same refusal.
     *
     * @throws SeoReviewException
     */
    public function activeLocationByUid(int $actorUserId, Business $lockedBusiness, string $locationUid): BusinessLocation
    {
        $location = BusinessLocation::query()
            ->where('business_id', $lockedBusiness->id)
            ->where('uid', $locationUid)
            ->first();

        return $this->assertAccessibleAndActive($actorUserId, $location);
    }

    /**
     * @throws SeoReviewException
     */
    public function activeLocationById(int $actorUserId, Business $lockedBusiness, int $locationId): BusinessLocation
    {
        $location = BusinessLocation::query()
            ->where('business_id', $lockedBusiness->id)
            ->find($locationId);

        return $this->assertAccessibleAndActive($actorUserId, $location);
    }

    private function assertAccessibleAndActive(int $actorUserId, ?BusinessLocation $location): BusinessLocation
    {
        if ($location === null || ! $this->locationGuard->userCanAccessLocation($actorUserId, $location)) {
            throw SeoReviewException::accessDenied();
        }

        if (! $location->isActive()) {
            throw SeoReviewException::locationNotActive();
        }

        return $location;
    }
}
