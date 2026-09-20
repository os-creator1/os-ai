<?php

namespace App\Library\Seo;

use App\Exceptions\Seo\SeoReviewException;
use App\Models\Business;
use App\Models\SeoLocationReviewLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contract 18 §8.6 — the manual per-Location review link.
 *
 * WHAT IT IS. One https link per Location that the Business pasted in.
 *
 * WHAT IT IS NOT. It never fetches, resolves, shortens, redirects or
 * click-tracks the link; it stores no Google value (the Google review URI is
 * only ever read through the GBP read model, at read time, by
 * SeoReviewsPageReader); and it accepts no query-string or redirect
 * parameter. Its only write target is `seo_location_review_links`.
 *
 * Authority is SeoReviewWriteGate: Business access re-derived under a row
 * lock, Location access through LocationAccessGuard, Location operational.
 */
final class SeoReviewLinkManager
{
    public function __construct(private readonly SeoReviewWriteGate $gate)
    {
    }

    /**
     * Sets or replaces the Location's manual link.
     *
     * @throws SeoReviewException
     */
    public function set(int $actorUserId, Business $business, string $locationUid, string $url): SeoLocationReviewLink
    {
        $safe = SeoLinkSafety::safeHttpsUrl(trim($url));

        return DB::transaction(function () use ($actorUserId, $business, $locationUid, $safe) {
            $locked = $this->gate->lockAuthorizedBusiness($actorUserId, $business);
            $location = $this->gate->activeLocationByUid($actorUserId, $locked, $locationUid);

            // Validated only after authorization, so an unauthorized caller
            // learns nothing about validation rules for a Location they
            // cannot reach.
            if ($safe === null) {
                throw SeoReviewException::invalidUrl();
            }

            $link = SeoLocationReviewLink::query()->where('business_location_id', $location->id)->first()
                ?? new SeoLocationReviewLink(['business_location_id' => $location->id]);

            $link->fill([
                'business_id' => $locked->id,
                'review_url' => $safe,
                'set_by_user_id' => $actorUserId,
            ]);
            $link->save();

            return $link;
        });
    }

    /**
     * Removes the Location's manual link (the Google fallback, if any, then
     * applies again at read time). Idempotent.
     *
     * @throws SeoReviewException
     */
    public function clear(int $actorUserId, Business $business, string $locationUid): void
    {
        DB::transaction(function () use ($actorUserId, $business, $locationUid): void {
            $locked = $this->gate->lockAuthorizedBusiness($actorUserId, $business);
            $location = $this->gate->activeLocationByUid($actorUserId, $locked, $locationUid);

            SeoLocationReviewLink::query()
                ->where('business_id', $locked->id)
                ->where('business_location_id', $location->id)
                ->delete();
        });
    }

    /**
     * Manual links for a set of ALREADY-AUTHORIZED Location ids, keyed by
     * Location id, in one query. The caller obtained the ids from
     * LocationAccessGuard; a Location not in the list is never read.
     *
     * @param  array<int, int>  $accessibleLocationIds
     * @return Collection<int, SeoLocationReviewLink>
     */
    public function forAccessibleLocations(Business $business, array $accessibleLocationIds): Collection
    {
        if ($accessibleLocationIds === []) {
            return new Collection();
        }

        return SeoLocationReviewLink::query()
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $accessibleLocationIds)
            ->get()
            ->keyBy(fn (SeoLocationReviewLink $link) => (int) $link->business_location_id);
    }
}
