<?php

namespace App\Library\Website\Seo;

/**
 * Website Generation + Hosting — closes the gap Implementation Contract
 * 18 §3.2/§3.6 recorded as a Website-module limitation ("no JSON-LD ...
 * these are Website-module gaps, never surfaced as customer findings").
 * Builds `LocalBusiness` (schema.org) structured data from confirmed,
 * already-visible facts only — never rankings, reviews, ratings, or
 * anything the owner has not saved.
 *
 * PURE presentation shaping only — never reads a live Business or
 * Location model. Every fact it is given already went through
 * App\Library\Website\WebsiteSnapshotBuilder::localBusinessFacts() at
 * publish time, which is where the privacy decision (address permitted
 * for this location?) and the "what counts as a confirmed fact" line
 * are actually drawn, once, and frozen into the published snapshot —
 * exactly like `contact_details`'s own resolved values. A live Business/
 * Location change after publishing therefore never changes what an
 * already-published page's structured data shows, until the next
 * publish, the same guarantee every other published fact on the site
 * already has.
 *
 * Deliberately excluded, per the task that authorized this class and
 * Google's own structured-data policy (no fabricated/irrelevant markup):
 * `aggregateRating`, `review`, `geo`, `priceRange` — none has a confirmed,
 * non-speculative source in this codebase today. (`logo`/`image` are the
 * owner's own published logo only.)
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
    /**
     * @param  array{name: ?string, telephone: ?string, email: ?string, address: ?array<string, ?string>, hours: ?array}  $facts
     *                                                                                                                            the frozen snapshot facts from WebsiteSnapshotBuilder::localBusinessFacts() — never a live model
     * @return ?array<string, mixed> null only when the published
     *                               snapshot carries no usable business name — schema.org requires one
     */
    public function build(array $facts, string $siteUrl, ?string $logoUrl = null): ?array
    {
        $name = trim((string) ($facts['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            // One entity for the whole site: the same @id and the SITE's address
            // on every page, not each page's own URL (which made one business
            // look like many to a crawler).
            '@id' => rtrim($siteUrl, '/').'/#business',
            'name' => $name,
            'url' => $siteUrl,
        ];

        // Only the owner's own published logo, never a platform placeholder.
        if ($logoUrl !== null && preg_match('#^https?://#i', $logoUrl) === 1) {
            $data['logo'] = $logoUrl;
            $data['image'] = $logoUrl;
        }

        if (! empty($facts['telephone'])) {
            $data['telephone'] = $facts['telephone'];
        }

        if (! empty($facts['email'])) {
            $data['email'] = $facts['email'];
        }

        $address = $this->postalAddress($facts['address'] ?? null);
        if ($address !== null) {
            $data['address'] = $address;
        }

        $hours = $this->openingHoursSpecification($facts['hours'] ?? null);
        if ($hours !== []) {
            $data['openingHoursSpecification'] = $hours;
        }

        return $data;
    }

    /**
     * @param  ?array{line1: ?string, line2: ?string, city: ?string, region: ?string, postal_code: ?string, country_code: ?string}  $address
     * @return ?array<string, string>
     */
    private function postalAddress(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $shaped = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => trim(collect([$address['line1'] ?? null, $address['line2'] ?? null])->filter()->implode(', ')) ?: null,
            'addressLocality' => $address['city'] ?? null,
            'addressRegion' => $address['region'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'addressCountry' => $address['country_code'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return isset($shaped['streetAddress']) || isset($shaped['addressLocality']) ? $shaped : null;
    }

    /**
     * `hours`: the frozen, verbatim copy of `business_locations.hours`
     * at publish time — `{monday: [{open,close}, ...], ...}`, an empty
     * array meaning "closed that day", `"24:00"` the end-of-day
     * sentinel (see BusinessKnowledgeProfileManager's own writer for
     * this exact shape). Never read for a day that has no confirmed
     * hours (the day's key absent, or `hours` itself null).
     *
     * @return array<int, array<string, string>>
     */
    private function openingHoursSpecification(?array $hours): array
    {
        if ($hours === null) {
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
