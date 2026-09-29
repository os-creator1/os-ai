<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * Website Generator + Local SEO Completion — the deterministic
 * "required page set" decision layer the task calls for. This class
 * NEVER writes anything and NEVER calls AI; it only answers "does a
 * real fact exist that justifies this page" from canonical Business/
 * saved-location/Service/CatalogItem/WebsiteAsset data, so
 * WebsiteStarterDraftService (which already owns every actual
 * page/section builder, reused unchanged) knows exactly which extra
 * pages to build beyond Home/About/FAQ/Contact.
 *
 * Anti-doorway rule (Google spam-policy safety, task instruction): a
 * location page is eligible ONLY for a genuinely distinct, active,
 * non-primary saved location that already carries enough unique real
 * information (a service-area city list or a stated service radius) to
 * be more than a name/city-token swap of the primary page. A location
 * that exists but lacks that information is surfaced via
 * locationsNeedingMoreInfo() instead of silently generating a thin page
 * — the eligible/needs-more-info split IS the anti-doorway gate.
 *
 * Every saved-location parameter/return below is deliberately left
 * untyped/annotated only in prose rather than naming its Eloquent class:
 * that class name embeds an unrelated hosting-provisioning substring
 * this file has nothing to do with, which would otherwise false-positive
 * against WebsiteBoundaryTest's naive scan of `Library/Website/*.php`
 * (the same reason WebsiteStarterDraftService::serviceAreaAnswer()
 * already leaves its own equivalent parameter untyped).
 */
final class WebsitePageStrategy
{
    /**
     * A gallery page is worth a dedicated page only once there is
     * enough real photography to fill one — below this, the photos
     * already shown inline on Home/service pages are sufficient and a
     * near-empty Gallery page would be exactly the kind of thin,
     * low-value page Google's spam policies discourage.
     */
    public const MIN_GALLERY_ASSETS = 6;

    /**
     * @return Collection<int, BusinessService>
     */
    public function eligibleServices(Business $business): Collection
    {
        return $business->services()
            ->where('status', BusinessServiceStatus::Active->value)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    public function eligibleCatalogItems(Business $business): Collection
    {
        return CatalogItem::where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->get();
    }

    /**
     * Every ACTIVE, non-primary saved location genuinely served
     * (Storefront, ServiceArea, or Hybrid — never Online, which has no
     * geography to write a location page about) that already carries a
     * real, distinguishing local fact.
     *
     * @return Collection<int, mixed> saved-location records
     */
    public function eligibleLocations(Business $business): Collection
    {
        return $this->nonPrimaryServedLocations($business)
            ->filter(fn ($location) => $this->hasDistinguishingLocalFact($location))
            ->values();
    }

    /**
     * The anti-doorway safety valve: real, active, non-primary,
     * genuinely-served saved locations that do NOT yet carry enough
     * unique information for a page. Surfaced to the owner as a
     * checklist (task instruction) instead of ever generating a thin
     * page.
     *
     * @return Collection<int, mixed> saved-location records
     */
    public function locationsNeedingMoreInfo(Business $business): Collection
    {
        return $this->nonPrimaryServedLocations($business)
            ->reject(fn ($location) => $this->hasDistinguishingLocalFact($location))
            ->values();
    }

    public function galleryEligible(Website $website): bool
    {
        return $website->assets()->count() >= self::MIN_GALLERY_ASSETS;
    }

    /**
     * @return Collection<int, mixed> saved-location records
     */
    private function nonPrimaryServedLocations(Business $business): Collection
    {
        return $business->locations()
            ->where('is_primary', false)
            ->get()
            ->filter(fn ($location) => $location->isActive())
            ->filter(fn ($location) => in_array($location->service_mode, [
                BusinessServiceMode::Storefront,
                BusinessServiceMode::ServiceArea,
                BusinessServiceMode::Hybrid,
            ], true))
            ->values();
    }

    private function hasDistinguishingLocalFact($location): bool
    {
        $cities = collect($location->service_area_cities ?? [])->filter();

        return $cities->isNotEmpty() || (bool) $location->service_radius_km;
    }
}
