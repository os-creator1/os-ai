<?php

namespace App\Library\AgencyOutreach\Import;

/**
 * Deterministic CSV column → prospect field detection. Header aliases first, then data-type detection
 * on the values. Never AI, never a silent guess: an ambiguous column is left unmapped and flagged so the
 * user decides. The mapping is always shown as editable selectors.
 */
final class ProspectFieldMapper
{
    /** field key => label shown in the selectors */
    public const FIELDS = [
        'business_name' => 'Business name',
        'phone' => 'Phone',
        'business_type' => 'Business type',
        'rating' => 'Google rating',
        'review_count' => 'Review count',
        'address' => 'Address',
        'city' => 'City',
        'state' => 'State / region',
        'country' => 'Country',
        'maps_url' => 'Google Maps link + location',
        'website' => 'Website',
        'has_website' => 'Has website',
        'opening_hours' => 'Opening hours',
        'source' => 'Source / list',
        'email' => 'Email',
        'contact_name' => 'Contact name',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
    ];

    /** @var array<string, list<string>> */
    private const ALIASES = [
        'business_name' => ['name', 'business name', 'business', 'business_name', 'company', 'company name', 'company_name', 'title', 'organization', 'organisation'],
        'phone' => ['phone', 'phone number', 'phone_number', 'phonenumber', 'mobile', 'mobile number', 'cell', 'cell phone', 'telephone', 'tel', 'contact number', 'whatsapp'],
        'business_type' => ['primary category', 'category', 'categories', 'business type', 'type', 'industry', 'niche', 'primary_category', 'main category'],
        'rating' => ['rating', 'google rating', 'stars', 'average rating', 'avg rating', 'review rating'],
        'review_count' => ['review amount', 'reviews', 'review count', 'review_count', 'reviews count', 'number of reviews', 'total reviews', 'user ratings total', 'review amount'],
        'address' => ['address', 'full address', 'street address', 'street', 'location', 'full_address'],
        'city' => ['city', 'town', 'locality'],
        'state' => ['state', 'region', 'province', 'state/province', 'county'],
        'country' => ['country', 'country code'],
        'maps_url' => ['link', 'google maps link', 'maps link', 'maps url', 'google maps url', 'google_maps_url', 'map link', 'url google maps'],
        'website' => ['lcr4fd href', 'website', 'website url', 'website_url', 'web', 'site', 'url', 'domain', 'web site', 'homepage'],
        'has_website' => ['website or no website', 'has website', 'has_website', 'website?'],
        'opening_hours' => ['hours', 'opening hours', 'opening_hours', 'hours, opening hours', 'hours opening hours', 'business hours', 'open hours', 'working hours'],
        'source' => ['source', 'lead source', 'list', 'list name', 'origin', 'campaign source'],
        'email' => ['email', 'email address', 'e-mail', 'mail'],
        'contact_name' => ['contact', 'contact name', 'owner', 'owner name', 'first name', 'person'],
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'lng', 'lon', 'long'],
    ];

    public static function labelFor(string $field): string
    {
        return self::FIELDS[$field] ?? 'Ignore this column';
    }

    public static function normaliseHeader(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = preg_replace('/[\s_\-]+/u', ' ', $h) ?? $h;

        return trim($h);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string,string>>  $rows
     * @return array{mapping: array<string, ?string>, detail: array<string, array{via: string, ambiguous: bool}>}
     *         mapping: header => field|null
     */
    public static function detect(array $headers, array $rows): array
    {
        $mapping = array_fill_keys($headers, null);
        $detail = [];
        $claimed = [];

        // Pass 1: header aliases (exact, after normalisation).
        $aliasIndex = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $aliasIndex[self::normaliseHeader($alias)][] = $field;
            }
        }

        $byField = [];
        foreach ($headers as $header) {
            $fields = array_values(array_unique($aliasIndex[self::normaliseHeader($header)] ?? []));

            if ($fields === []) {
                continue;
            }

            // "location" and "url" are ambiguous on their own: decided by content below.
            $field = $fields[0];
            $byField[$field][] = $header;
        }

        foreach ($byField as $field => $cols) {
            // First column wins; the others are left for the user to review (never silently dropped).
            $mapping[$cols[0]] = $field;
            $claimed[$field] = true;
            $detail[$cols[0]] = ['via' => 'header', 'ambiguous' => false];

            foreach (array_slice($cols, 1) as $extra) {
                $detail[$extra] = ['via' => 'duplicate', 'ambiguous' => true];
            }
        }

        // Pass 2: data-type detection for columns the aliases did not place.
        foreach ($headers as $header) {
            if ($mapping[$header] !== null || isset($detail[$header])) {
                continue;
            }

            $values = array_values(array_filter(array_map(fn ($r) => trim((string) ($r[$header] ?? '')), $rows), fn ($v) => $v !== ''));

            if (count($values) < 3) {
                continue;
            }

            $guess = null;

            if (! isset($claimed['phone']) && self::share($values, fn ($v) => (bool) preg_match('/^\+?[\d\s().\-]{7,20}$/', $v) && strlen(preg_replace('/\D/', '', $v)) >= 7) >= 0.8) {
                $guess = 'phone';
            } elseif (! isset($claimed['maps_url']) && self::share($values, fn ($v) => (bool) preg_match('~google\.[a-z.]+/maps|maps\.app\.goo\.gl|goo\.gl/maps~i', $v)) >= 0.8) {
                $guess = 'maps_url';
            } elseif (! isset($claimed['website']) && self::share($values, fn ($v) => (bool) preg_match('~^(https?://|www\.)\S+$~i', $v)) >= 0.8) {
                $guess = 'website';
            } elseif (! isset($claimed['email']) && self::share($values, fn ($v) => (bool) filter_var($v, FILTER_VALIDATE_EMAIL)) >= 0.8) {
                $guess = 'email';
            }

            if ($guess !== null) {
                $mapping[$header] = $guess;
                $claimed[$guess] = true;
                $detail[$header] = ['via' => 'content', 'ambiguous' => false];
            }
        }

        foreach ($headers as $header) {
            $detail[$header] ??= ['via' => $mapping[$header] === null ? 'none' : 'header', 'ambiguous' => false];
        }

        return ['mapping' => $mapping, 'detail' => $detail];
    }

    /** @param list<string> $values */
    private static function share(array $values, callable $test): float
    {
        if ($values === []) {
            return 0.0;
        }

        $hits = 0;
        foreach ($values as $v) {
            if ($test($v)) {
                $hits++;
            }
        }

        return $hits / count($values);
    }

    /**
     * Only fields we know; anything else becomes "ignore". Each field may be used by at most one column.
     * $submitted is keyed by the column's position, so odd header text can never break the form post.
     */
    public static function sanitise(array $submitted, array $headers): array
    {
        $out = array_fill_keys($headers, null);
        $used = [];

        foreach (array_values($headers) as $i => $header) {
            $field = $submitted[$i] ?? null;

            if (is_string($field) && isset(self::FIELDS[$field]) && ! isset($used[$field])) {
                $out[$header] = $field;
                $used[$field] = true;
            }
        }

        return $out;
    }
}
