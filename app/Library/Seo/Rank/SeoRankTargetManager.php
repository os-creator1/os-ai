<?php

namespace App\Library\Seo\Rank;

use App\Enums\Business\BusinessStatus;
use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of seo_rank_targets: start, stop and restart tracking.
 *
 * AUTHORITY. Every mutation re-derives everything under a row lock on the
 * Business: the Business must be Active and reachable by the actor, the keyword
 * is resolved by uid INSIDE the Business through SeoKeywordManager::findAccessible
 * (so keyword Location ACL is the existing authority, with no second algorithm),
 * and the search geography must resolve through SeoRankLocationCatalog. A forged
 * or foreign id is indistinguishable from a missing one.
 *
 * ALLOWANCE. Slots in use = tracking targets whose keyword is active. The limit
 * comes from SeoRankEntitlement/SeoConfig. The lock on the Business row means two
 * simultaneous starts cannot both take the last slot.
 *
 * Stopping keeps every observation and frees the slot; restarting flips the same
 * row back, so a keyword/geography/device is never duplicated and history
 * resumes. No provider call and no spend happens here.
 */
class SeoRankTargetManager
{
    public function __construct(
        private readonly SeoKeywordManager $keywords,
        private readonly SeoRankLocationCatalog $locations,
        private readonly SeoRankEntitlement $entitlement,
        private readonly SeoRankTrackingBudget $budget,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    /**
     * @throws SeoRankException
     */
    public function track(int $actorUserId, Business $business, string $keywordUid, int $locationCode): SeoRankTarget
    {
        $keyword = $this->keywords->findAccessible($actorUserId, $business, $keywordUid);

        if ($keyword === null) {
            throw SeoRankException::accessDenied();
        }

        $location = $this->locations->find($locationCode);

        if ($location === null) {
            throw SeoRankException::invalidLocation();
        }

        return DB::transaction(function () use ($actorUserId, $business, $keyword, $location) {
            $locked = $this->lockAuthorizedBusiness($actorUserId, $business);

            $freshKeyword = SeoKeyword::query()->where('business_id', $locked->id)->whereKey($keyword->id)->lockForUpdate()->first();

            if ($freshKeyword === null) {
                throw SeoRankException::accessDenied();
            }

            if (! $freshKeyword->isActive()) {
                throw SeoRankException::keywordNotActive();
            }

            $existing = SeoRankTarget::query()
                ->where('seo_keyword_id', $freshKeyword->id)
                ->where('provider', $this->budget->providerKey())
                ->where('search_location_code', $location->location_code)
                ->where('device', 'mobile')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isTracking()) {
                return $existing;
            }

            $this->assertSlotAvailable($locked);

            $now = CarbonImmutable::now('UTC');

            if ($existing !== null) {
                $existing->forceFill([
                    'tracking_state' => SeoRankTrackingState::Tracking->value,
                    'stopped_at' => null,
                    'next_check_at' => null,
                    'updated_by_user_id' => $actorUserId,
                ])->save();

                return $existing;
            }

            $target = new SeoRankTarget();
            $target->forceFill([
                'business_id' => $locked->id,
                'seo_keyword_id' => $freshKeyword->id,
                'provider' => $this->budget->providerKey(),
                'seo_rank_location_id' => $location->id,
                'search_location_code' => $location->location_code,
                'language_code' => SeoRankLocationCatalog::LANGUAGE,
                'device' => 'mobile',
                'tracking_state' => SeoRankTrackingState::Tracking->value,
                'tracked_since' => $now,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ])->save();

            return $target;
        });
    }

    /**
     * @throws SeoRankException
     */
    public function stop(int $actorUserId, Business $business, string $targetUid): SeoRankTarget
    {
        return DB::transaction(function () use ($actorUserId, $business, $targetUid) {
            $locked = $this->lockAuthorizedBusiness($actorUserId, $business);
            $target = $this->lockedTarget($actorUserId, $locked, $targetUid);

            if ($target->isTracking()) {
                $target->forceFill([
                    'tracking_state' => SeoRankTrackingState::Stopped->value,
                    'stopped_at' => CarbonImmutable::now('UTC'),
                    'next_check_at' => null,
                    'updated_by_user_id' => $actorUserId,
                ])->save();
            }

            return $target;
        });
    }

    /**
     * @throws SeoRankException
     */
    public function restart(int $actorUserId, Business $business, string $targetUid): SeoRankTarget
    {
        return DB::transaction(function () use ($actorUserId, $business, $targetUid) {
            $locked = $this->lockAuthorizedBusiness($actorUserId, $business);
            $target = $this->lockedTarget($actorUserId, $locked, $targetUid);

            if ($target->isTracking()) {
                return $target;
            }

            if (! $target->keyword->isActive()) {
                throw SeoRankException::keywordNotActive();
            }

            $this->assertSlotAvailable($locked);

            $target->forceFill([
                'tracking_state' => SeoRankTrackingState::Tracking->value,
                'stopped_at' => null,
                'next_check_at' => null,
                'updated_by_user_id' => $actorUserId,
            ])->save();

            return $target;
        });
    }

    /** A target by uid, resolved inside the Business and behind its keyword's Location ACL. */
    public function findAccessible(int $actorUserId, Business $business, string $targetUid): ?SeoRankTarget
    {
        $target = SeoRankTarget::query()
            ->where('business_id', $business->id)
            ->where('uid', $targetUid)
            ->with(['keyword.location', 'searchLocation'])
            ->first();

        if ($target === null) {
            return null;
        }

        return $this->keywords->findAccessible($actorUserId, $business, $target->keyword->uid) === null ? null : $target;
    }

    public function slotsUsed(Business $business): int
    {
        return $this->budget->trackedTargetsQuery($business)->count();
    }

    private function assertSlotAvailable(Business $locked): void
    {
        $plan = $this->entitlement->planFor($locked);

        if ($plan === null) {
            throw SeoRankException::notEntitled();
        }

        $used = $this->slotsUsed($locked);

        if ($used >= $plan->trackedTargets) {
            throw SeoRankException::limitReached($used, $plan->trackedTargets);
        }
    }

    private function lockedTarget(int $actorUserId, Business $locked, string $targetUid): SeoRankTarget
    {
        $target = SeoRankTarget::query()
            ->where('business_id', $locked->id)
            ->where('uid', $targetUid)
            ->with('keyword')
            ->lockForUpdate()
            ->first();

        if ($target === null || $this->keywords->findAccessible($actorUserId, $locked, $target->keyword->uid) === null) {
            throw SeoRankException::accessDenied();
        }

        return $target;
    }

    private function lockAuthorizedBusiness(int $actorUserId, Business $business): Business
    {
        $locked = Business::query()->whereKey($business->id)->lockForUpdate()->first();

        if ($locked === null
            || $locked->status !== BusinessStatus::Active
            || ! $this->workspaces->userCanAccessBusiness($actorUserId, $locked)) {
            throw SeoRankException::accessDenied();
        }

        return $locked;
    }
}
