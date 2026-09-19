<?php

namespace App\Library\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Seo\SeoKeywordLifecycleState;
use App\Exceptions\Seo\SeoKeywordException;
use App\Library\Workspace\LocationAccessGuard;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoKeyword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contract 18 Sub-slice D §8.4 — the ONLY writer of SEO keywords: create,
 * update, archive, reactivate, plus the read paths that keep Location access
 * enforced.
 *
 * AUTHORITY. Every mutation, inside one transaction and under a row lock on the
 * Business, re-derives everything from persistence and trusts no passed-in
 * model: the Business must still be Active and reachable by the actor
 * (WorkspaceManager::userCanAccessBusiness), and any Location involved must
 * belong to that Business, still be operational (isActive(), Contract 18
 * §10.5) and pass LocationAccessGuard::userCanAccessLocation(). There is no
 * SEO-specific Location rule and no second access algorithm; the guard is the
 * only Location authority. A keyword the actor cannot reach is
 * indistinguishable from one that does not exist (accessDenied()).
 *
 * BUSINESS-WIDE keywords (no Location) need Business access only: definitions
 * are Business-wide configuration (Blueprint §5).
 *
 * CONCURRENCY. The Business row lock serializes every write for one Business,
 * so the active-keyword ceiling cannot be raced past by two simultaneous
 * creates/reactivations. The unique index is the final backstop for
 * duplicates.
 *
 * WRITES ONLY seo_keywords. Nothing here writes a Business, Location, Website
 * or Google row (Contract 18 §12); the Business row is only locked and read.
 * Legacy inbound-SMS Keywords are never touched or referenced.
 */
final class SeoKeywordManager
{
    public const MAX_PHRASE_LENGTH = 120;

    public function __construct(
        private readonly LocationAccessGuard $locationGuard,
        private readonly WorkspaceManager $workspaceManager,
        private readonly SeoConfig $config,
    ) {
    }

    // -----------------------------------------------------------------
    // Reads — Location access is applied BEFORE anything is returned or counted.
    // -----------------------------------------------------------------

    /**
     * Keywords this actor may see: every Business-wide keyword, plus keywords
     * whose Location the actor may access. Archived keywords are included.
     *
     * A caller that has ALREADY resolved the actor's accessible Location ids
     * (from LocationAccessGuard, e.g. via SeoLocationScope) may pass them to
     * avoid resolving them twice; they are never taken from request input.
     *
     * @param  array<int, int>|null  $accessibleLocationIds
     * @return Collection<int, SeoKeyword>
     */
    public function listVisible(int $actorUserId, Business $business, ?array $accessibleLocationIds = null): Collection
    {
        $accessibleIds = $accessibleLocationIds ?? $this->locationGuard->accessibleLocationIdsForBusiness($actorUserId, $business);

        return $this->visibleQuery($business, $accessibleIds)
            ->with('location')
            ->orderBy('lifecycle_state')
            ->orderBy('phrase_normalized')
            ->orderBy('id')
            ->get();
    }

    /**
     * ACTIVE keywords visible to the actor, counted over the already-filtered
     * set (filter first, count second — Contract 18 §6). The caller supplies
     * the accessible Location ids it has already resolved.
     *
     * @param  array<int, int>  $accessibleLocationIds
     */
    public function countActiveVisible(Business $business, array $accessibleLocationIds): int
    {
        return $this->visibleQuery($business, $accessibleLocationIds)->active()->count();
    }

    /**
     * A keyword by uid, resolved INSIDE the Business and only if the actor may
     * reach it. Unknown, foreign and inaccessible are all null.
     */
    public function findAccessible(int $actorUserId, Business $business, string $uid): ?SeoKeyword
    {
        $keyword = SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where('uid', $uid)
            ->with('location')
            ->first();

        if ($keyword === null) {
            return null;
        }

        if ($keyword->business_location_id === null) {
            return $keyword;
        }

        $accessibleIds = $this->locationGuard->accessibleLocationIdsForBusiness($actorUserId, $business);

        return in_array((int) $keyword->business_location_id, $accessibleIds, true) ? $keyword : null;
    }

    // -----------------------------------------------------------------
    // Writes.
    // -----------------------------------------------------------------

    /**
     * @throws SeoKeywordException
     */
    public function create(int $actorUserId, Business $business, string $phrase, ?BusinessLocation $location = null): SeoKeyword
    {
        [$typed, $normalized] = $this->preparePhrase($phrase);

        return DB::transaction(function () use ($actorUserId, $business, $typed, $normalized, $location) {
            $lockedBusiness = $this->lockAuthorizedBusiness($actorUserId, $business);
            $locationId = $this->authorizeLocation($actorUserId, $lockedBusiness, $location);
            $this->assertUnderCeiling($lockedBusiness);

            $keyword = new SeoKeyword([
                'business_location_id' => $locationId,
                'phrase' => $typed,
                'phrase_normalized' => $normalized,
                'source' => 'manual',
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $keyword->forceFill(['business_id' => $lockedBusiness->id]);

            $this->saveOrDuplicate($keyword);

            return $keyword;
        });
    }

    /**
     * Changes the phrase and/or the Location attribution of an ACTIVE keyword.
     * The actor needs access to the keyword's current Location (if any) AND to
     * the new one (if any).
     *
     * @throws SeoKeywordException
     */
    public function update(int $actorUserId, Business $business, SeoKeyword $keyword, string $phrase, ?BusinessLocation $location = null): SeoKeyword
    {
        [$typed, $normalized] = $this->preparePhrase($phrase);

        return DB::transaction(function () use ($actorUserId, $business, $keyword, $typed, $normalized, $location) {
            $lockedBusiness = $this->lockAuthorizedBusiness($actorUserId, $business);
            $current = $this->lockedKeyword($lockedBusiness, $keyword);

            $this->authorizeExistingLocation($actorUserId, $lockedBusiness, $current);

            if (! $current->isActive()) {
                throw SeoKeywordException::notActive();
            }

            $newLocationId = $this->authorizeLocation($actorUserId, $lockedBusiness, $location);

            $currentLocationId = $current->business_location_id === null ? null : (int) $current->business_location_id;

            if ($current->phrase === $typed
                && $current->phrase_normalized === $normalized
                && $currentLocationId === $newLocationId) {
                return $current;
            }

            $current->fill([
                'business_location_id' => $newLocationId,
                'phrase' => $typed,
                'phrase_normalized' => $normalized,
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->saveOrDuplicate($current);

            return $current;
        });
    }

    /**
     * @throws SeoKeywordException
     */
    public function archive(int $actorUserId, Business $business, SeoKeyword $keyword): SeoKeyword
    {
        return DB::transaction(function () use ($actorUserId, $business, $keyword) {
            $lockedBusiness = $this->lockAuthorizedBusiness($actorUserId, $business);
            $current = $this->lockedKeyword($lockedBusiness, $keyword);

            $this->authorizeExistingLocation($actorUserId, $lockedBusiness, $current);

            if (! $current->isActive()) {
                return $current;
            }

            $current->forceFill([
                'lifecycle_state' => SeoKeywordLifecycleState::Archived,
                'archived_at' => now(),
                'updated_by_user_id' => $actorUserId,
            ])->save();

            return $current;
        });
    }

    /**
     * Reactivating counts against the same ceiling as creating.
     *
     * @throws SeoKeywordException
     */
    public function reactivate(int $actorUserId, Business $business, SeoKeyword $keyword): SeoKeyword
    {
        return DB::transaction(function () use ($actorUserId, $business, $keyword) {
            $lockedBusiness = $this->lockAuthorizedBusiness($actorUserId, $business);
            $current = $this->lockedKeyword($lockedBusiness, $keyword);

            $this->authorizeExistingLocation($actorUserId, $lockedBusiness, $current);

            if ($current->isActive()) {
                return $current;
            }

            $this->assertUnderCeiling($lockedBusiness);

            $current->forceFill([
                'lifecycle_state' => SeoKeywordLifecycleState::Active,
                'archived_at' => null,
                'updated_by_user_id' => $actorUserId,
            ])->save();

            return $current;
        });
    }

    // -----------------------------------------------------------------
    // Internals.
    // -----------------------------------------------------------------

    /**
     * @return array{0: string, 1: string} [typed phrase, normalized phrase]
     */
    private function preparePhrase(string $phrase): array
    {
        $typed = trim($phrase);

        if ($typed === '' || preg_match('/[\p{Cc}\p{Cf}]/u', $typed) === 1 || mb_strlen($typed) > self::MAX_PHRASE_LENGTH) {
            throw SeoKeywordException::invalidPhrase();
        }

        $normalized = SeoPhraseNormalizer::normalize($typed);

        // NFKC can EXPAND text (ligatures, compatibility forms), so the
        // normalized form is checked against the column width separately.
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_PHRASE_LENGTH) {
            throw SeoKeywordException::invalidPhrase();
        }

        return [$typed, $normalized];
    }

    private function lockAuthorizedBusiness(int $actorUserId, Business $business): Business
    {
        $locked = Business::query()->whereKey($business->id)->lockForUpdate()->first();

        if ($locked === null
            || $locked->status !== BusinessStatus::Active
            || ! $this->workspaceManager->userCanAccessBusiness($actorUserId, $locked)) {
            throw SeoKeywordException::accessDenied();
        }

        return $locked;
    }

    private function lockedKeyword(Business $lockedBusiness, SeoKeyword $keyword): SeoKeyword
    {
        $current = SeoKeyword::query()
            ->where('business_id', $lockedBusiness->id)
            ->whereKey($keyword->id)
            ->lockForUpdate()
            ->first();

        if ($current === null) {
            throw SeoKeywordException::accessDenied();
        }

        return $current;
    }

    /**
     * A Location the actor is attributing a keyword to (create / change).
     * Re-derived from persistence; must belong to this Business, be active and
     * be accessible to the actor. Returns its id, or null for Business-wide.
     */
    private function authorizeLocation(int $actorUserId, Business $lockedBusiness, ?BusinessLocation $location): ?int
    {
        return $location === null
            ? null
            : $this->authorizeLocationId($actorUserId, $lockedBusiness, (int) $location->id);
    }

    /**
     * The one Location check: the Location is re-read scoped to THIS Business
     * (a foreign or unknown id is indistinguishable from an inaccessible one),
     * LocationAccessGuard decides access, and it must still be operational.
     */
    private function authorizeLocationId(int $actorUserId, Business $lockedBusiness, int $locationId): int
    {
        $current = BusinessLocation::query()
            ->where('business_id', $lockedBusiness->id)
            ->find($locationId);

        if ($current === null || ! $this->locationGuard->userCanAccessLocation($actorUserId, $current)) {
            throw SeoKeywordException::accessDenied();
        }

        if (! $current->isActive()) {
            throw SeoKeywordException::locationNotActive();
        }

        return (int) $current->id;
    }

    /**
     * A keyword that already carries a Location may only be touched by an
     * actor who can access that Location, and only while it is operational.
     * A Business-wide keyword needs no Location authority.
     */
    private function authorizeExistingLocation(int $actorUserId, Business $lockedBusiness, SeoKeyword $keyword): void
    {
        if ($keyword->business_location_id === null) {
            return;
        }

        $this->authorizeLocationId($actorUserId, $lockedBusiness, (int) $keyword->business_location_id);
    }

    private function assertUnderCeiling(Business $lockedBusiness): void
    {
        $limit = $this->config->keywordsMaxActivePerBusiness();

        $active = SeoKeyword::query()
            ->where('business_id', $lockedBusiness->id)
            ->active()
            ->count();

        if ($active >= $limit) {
            throw SeoKeywordException::limitReached($limit);
        }
    }

    private function saveOrDuplicate(SeoKeyword $keyword): void
    {
        try {
            $keyword->save();
        } catch (UniqueConstraintViolationException) {
            throw SeoKeywordException::duplicate();
        }
    }

    /**
     * Business-wide keywords, plus keywords of the given Locations.
     *
     * @param  array<int, int>  $accessibleLocationIds
     */
    private function visibleQuery(Business $business, array $accessibleLocationIds): \Illuminate\Database\Eloquent\Builder
    {
        return SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($accessibleLocationIds) {
                $query->whereNull('business_location_id');

                if ($accessibleLocationIds !== []) {
                    $query->orWhereIn('business_location_id', $accessibleLocationIds);
                }
            });
    }
}
