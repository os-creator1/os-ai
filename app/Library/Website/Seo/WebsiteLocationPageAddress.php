<?php

namespace App\Library\Website\Seo;

use App\Library\Website\WebsiteSlugRules;
use App\Models\Business;

/**
 * SEO V1 final — a "Serving <place>" page must never present a street address that is not that place's.
 *
 * A Website carries ONE physical address authority: the Business's PRIMARY Location. A page can say
 * which place it is about only through its generated slug (`serving-<city-region>`), because pages store
 * no Location id. So:
 *  - a page that is not a "serving-*" page is the business's own page: the primary address is correct;
 *  - a "serving-*" page may show the address only when it is the primary Location's own page (the slug
 *    the generator gives the primary Location's city and region);
 *  - every other "serving-*" page (a secondary Location, or a service-area page that implies no storefront)
 *    shows NO street address in the page or in its structured data. Omitting is safe; showing the primary
 *    address as if it were that Location's is not.
 */
final class WebsiteLocationPageAddress
{
    public static function pageMayShowAddress(?string $slug, ?Business $business): bool
    {
        if ($slug === null || ! str_starts_with($slug, 'serving-')) {
            return true;
        }

        $location = $business?->primaryLocation;

        if ($location === null) {
            return false;
        }

        $label = collect([$location->city, $location->region])->filter()->implode(', ');

        return $slug === WebsiteSlugRules::bounded('serving-', $label, 'location');
    }
}
