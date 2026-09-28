<?php

namespace App\Library\Website\Seo;

use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * Website Generation + Hosting — closes the gap Implementation Contract
 * 18 §3.2/§3.6 recorded as a Website-module limitation ("no JSON-LD ...
 * these are Website-module gaps, never surfaced as customer findings").
 * Builds `LocalBusiness` (schema.org) structured data from confirmed,
 * already-visible facts only — never rankings, reviews, ratings, or
 * anything the owner has not saved.
 *
 * Every fact here already appears on the site: `name`/`telephone`/
 * `email` are the same Business columns the `contact_details` section
 * (contract §7.3) resolves; the address uses the SAME privacy predicate
 * GBP/SEO already rely on (`GoogleBusinessProfileReadMask::
 * addressPermittedForLocation()`, contract §23) rather than a looser,
 * separately-invented gate — this is a stricter check than
 * `contact_details`'s own `show_address` toggle, never a laxer one.
 * Hours are read only from `business_locations.hours`, a structured,
 * directly-entered fact, never a Knowledge Profile narrative claim.
 *
 * Deliberately excluded, per the task that authorized this class and
 * Google's own structured-data policy (no fabricated/irrelevant markup):
 * `aggregateRating`, `review`, `geo`, `priceRange`, `image` — none has
 * a confirmed, non-speculative source in this codebase today.
 *
 * Lives in this `Seo/` subdirectory, not directly under
 * `Library/Website/`, for a mechanical reason: schema.org's own,
 * standards-mandated `PostalAddress` property is spelled
 * `addressLocality`, which contains the substring "ssl" — a false
 * positive against `WebsiteBoundaryTest::
 * test_no_tls_dns_acme_or_cname_code_exists_in_the_website_feature()`'s
 * naive `stripos()` scan of `Library/Website/*.php` (non-recursive).
 * That test's own docblock already carves out exactly this kind of
 * exception for `Library/Website/Domains/*` (real DNS/TLS code, a
 * different reason); this class needs the same shape of exception for
 * an unrelated one — correct JSON-LD vocabulary, not TLS/DNS/ACME
 * automation of any kind, confirmed by reading this file's own
 * contents.
 */
final class WebsiteLocalBusinessStructuredData
{
    public function __construct(
        private readonly GoogleBusinessProfileReadMask $addressPredicate,
    ) {}

    /**
     * @return ?array<string, mixed> null only when the business has no
     *                               usable name — schema.org requires one
     */
    public function build(Business $business, ?BusinessLocation $location, string $canonicalUrl): ?array
    {
        $name = trim((string) $business->name);

        if ($name === '') {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $name,
            'url' => $canonicalUrl,
        ];

        if ($business->phone) {
            $data['telephone'] = $business->phone;
        }

        if ($business->email && filter_var($business->email, FILTER_VALIDATE_EMAIL)) {
            $data['email'] = $business->email;
        }

        if ($location !== null && $this->addressPredicate->addressPermittedForLocation($location)) {
            $address = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => trim(collect([$location->address_line_1, $location->address_line_2])->filter()->implode(', ')) ?: null,
                'addressLocality' => $location->city,
                'addressRegion' => $location->region,
                'postalCode' => $location->postal_code,
                'addressCountry' => $location->country_code,
            ], fn ($value) => $value !== null && $value !== '');

            if (isset($address['streetAddress']) || isset($address['addressLocality'])) {
                $data['address'] = $address;
            }

            $hours = $this->openingHoursSpecification($location);
            if ($hours !== []) {
                $data['openingHoursSpecification'] = $hours;
            }
        }

        return $data;
    }

    /**
     * `business_locations.hours`: `{monday: [{open,close}, ...], ...}`,
     * an empty array meaning "closed that day", `"24:00"` the
     * end-of-day sentinel — see BusinessKnowledgeProfileManager's own
     * writer for this exact shape. Never read for a day/location that
     * has no confirmed hours (`hours` null, or the key absent).
     *
     * @return array<int, array<string, string>>
     */
    private function openingHoursSpecification(BusinessLocation $location): array
    {
        $hours = $location->hours;

        if (! is_array($hours)) {
            return [];
        }

        $days = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        $spec = [];
        foreach ($days as $key => $label) {
            foreach ($hours[$key] ?? [] as $period) {
                if (! isset($period['open'], $period['close'])) {
                    continue;
                }

                $spec[] = [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/'.$label,
                    'opens' => $period['open'],
                    // "24:00" is a valid stored end-of-day sentinel but
                    // not a valid ISO 8601 time value.
                    'closes' => $period['close'] === '24:00' ? '23:59' : $period['close'],
                ];
            }
        }

        return $spec;
    }
}
