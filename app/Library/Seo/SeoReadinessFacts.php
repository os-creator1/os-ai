<?php

namespace App\Library\Seo;

use App\Enums\Business\BusinessServiceMode;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;

/**
 * Contract 18 §5.2 — the plain facts the readiness registry evaluates.
 *
 * Built from platform-owned data only. `locationsTotal` / `locationsReady`
 * are computed over the actor's ACCESSIBLE, ACTIVE Locations that the caller
 * already filtered (Contract 18 §6: filter first, aggregate second) — this
 * class never sees, and so can never count, an inaccessible Location.
 */
final class SeoReadinessFacts
{
    public function __construct(
        public readonly bool $websiteUrlSet,
        public readonly bool $phoneSet,
        public readonly bool $websitePublished,
        public readonly bool $gbpUrlPresent,
        public readonly int $locationsTotal,
        public readonly int $locationsReady,
        public readonly int $keywordsDefined,
    ) {
    }

    /**
     * @param  Collection<int, BusinessLocation>  $accessibleActiveLocations  already ACL-filtered
     * @param  int  $keywordsDefined  ACTIVE SEO keywords visible to the actor (already ACL-filtered)
     */
    public static function build(Business $business, bool $websitePublished, Collection $accessibleActiveLocations, int $keywordsDefined): self
    {
        return new self(
            websiteUrlSet: self::filled($business->website_url),
            phoneSet: self::filled($business->phone),
            websitePublished: $websitePublished,
            gbpUrlPresent: self::filled($business->google_business_profile_url),
            locationsTotal: $accessibleActiveLocations->count(),
            locationsReady: $accessibleActiveLocations->filter(fn (BusinessLocation $l) => self::locationIsReady($l))->count(),
            keywordsDefined: $keywordsDefined,
        );
    }

    /**
     * "Usable address or service area." An online-only Location needs
     * neither. A storefront needs a street line and a city; a service-area
     * Location needs a radius or at least one named city; a hybrid needs
     * either. Only PRESENCE is tested here — the address itself is never
     * copied out, logged or transmitted (GBP §23 private-address rules are
     * unaffected: nothing is read beyond "is it set").
     */
    public static function locationIsReady(BusinessLocation $location): bool
    {
        $hasAddress = self::filled($location->address_line_1) && self::filled($location->city);
        $hasServiceArea = ((int) $location->service_radius_km) > 0
            || (is_array($location->service_area_cities) && count(array_filter($location->service_area_cities, fn ($c) => self::filled(is_string($c) ? $c : null))) > 0);

        return match ($location->service_mode) {
            BusinessServiceMode::Online => true,
            BusinessServiceMode::Storefront => $hasAddress,
            BusinessServiceMode::ServiceArea => $hasServiceArea,
            BusinessServiceMode::Hybrid => $hasAddress || $hasServiceArea,
            default => false,
        };
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
