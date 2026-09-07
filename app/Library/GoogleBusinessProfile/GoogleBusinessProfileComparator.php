<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleComparisonRow;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessLocation;

/**
 * GBP Slice A contract §22 — the deterministic platform-versus-Google
 * comparison.
 *
 * PURE FUNCTION: no I/O, no persistence, no clock beyond the freshness
 * check it is handed. That is a contract requirement, not a style note —
 * it is what makes §13.3 ("computed at read time and NEVER persisted")
 * cheap and total, and it is why there is no comparison table, no
 * comparison column and no cached comparison anywhere in this slice.
 *
 * Exactly four columns and exactly five statuses (§22.1). There is no
 * score, percentage, grade, severity ordering, recommendation engine or AI
 * interpretation, and a "Proposed change" column does not exist until a
 * mutation slice contracts one.
 */
final class GoogleBusinessProfileComparator
{
    public function __construct(private readonly GoogleBusinessProfileReadMask $readMask)
    {
    }

    /**
     * @return array<int, GoogleComparisonRow>
     */
    public function compare(Business $business, BusinessLocation $location, BusinessGoogleLocation $binding): array
    {
        // Contract §13.3 — an absent or expired mirror renders EVERY row's
        // Google column as Not comparable. It never falls back to a stale
        // mirror and never silently shows an old value.
        if (! $binding->mirrorIsFresh()) {
            return $this->unavailableRows($business, $location);
        }

        $mirror = $binding->freshMirror();
        $addressPermitted = $this->readMask->addressPermittedForLocation($location);

        return [
            $this->row(
                'Business name',
                $business->name,
                $mirror['title'] ?? null,
                fn ($p, $g) => GoogleBusinessProfileComparisonRules::text($p) === GoogleBusinessProfileComparisonRules::text($g),
            ),
            $this->row(
                'Phone',
                $business->phone,
                $mirror['phone_primary'] ?? null,
                fn ($p, $g) => GoogleBusinessProfileComparisonRules::phone($p) === GoogleBusinessProfileComparisonRules::phone($g),
            ),
            $this->row(
                'Website',
                $business->website_url,
                $mirror['website_uri'] ?? null,
                fn ($p, $g) => GoogleBusinessProfileComparisonRules::websiteUrl($p) === GoogleBusinessProfileComparisonRules::websiteUrl($g),
            ),

            // Contract §22.5 — BusinessIndustry's seven platform-invented
            // values are NOT Google categories, and Google publishes no
            // mapping primitive (a verified negative). The Google category
            // is DISPLAYED beside the platform industry and never compared.
            new GoogleComparisonRow(
                field: 'Primary category',
                platformValue: $this->presentable($business->industry),
                googleValue: $mirror['primary_category_name'] ?? null,
                status: GoogleComparisonStatus::NotComparable,
                reason: 'The platform industry list is not Google\'s category taxonomy; Google publishes no mapping between them.',
            ),
            new GoogleComparisonRow(
                field: 'Additional categories',
                platformValue: null,
                googleValue: $this->joinNames($mirror['additional_category_names'] ?? []),
                status: GoogleComparisonStatus::NotComparable,
                reason: 'The platform has no equivalent field.',
            ),

            // Contract §23.5 — the street address is Not comparable in
            // EVERY case, even when public_address is true: Slice A never
            // requests addressLines, so there is no Google value, and
            // address equality is a normalization problem that produces
            // confident wrong answers.
            new GoogleComparisonRow(
                field: 'Street address',
                platformValue: null,
                googleValue: null,
                status: GoogleComparisonStatus::NotComparable,
                reason: $addressPermitted
                    ? 'Street addresses are never compared; locality and country carry the useful signal.'
                    : 'Address withheld by consent (public_address is off for this location).',
            ),

            $addressPermitted
                ? $this->row(
                    'City',
                    $location->city,
                    $mirror['locality'] ?? null,
                    fn ($p, $g) => GoogleBusinessProfileComparisonRules::text($p) === GoogleBusinessProfileComparisonRules::text($g),
                )
                // Contract §23.4 point 5 — the value is never rendered and
                // no verdict is implied.
                : new GoogleComparisonRow(
                    field: 'City',
                    platformValue: null,
                    googleValue: null,
                    status: GoogleComparisonStatus::NotComparable,
                    reason: 'Address withheld by consent (public_address is off for this location).',
                ),

            $addressPermitted
                ? $this->row(
                    'Country',
                    $location->country_code,
                    $mirror['region_code'] ?? null,
                    fn ($p, $g) => strtoupper((string) $p) === strtoupper((string) $g),
                )
                : new GoogleComparisonRow(
                    field: 'Country',
                    platformValue: null,
                    googleValue: null,
                    status: GoogleComparisonStatus::NotComparable,
                    reason: 'Address withheld by consent (public_address is off for this location).',
                ),

            $this->serviceModelRow($location, $mirror),

            // Contract §22.4 — Google models service areas as up to 20
            // REGION PLACE IDs; the platform models them as an integer
            // radius plus free-text city names. There is no correct
            // conversion and Slice A takes no Places API dependency.
            new GoogleComparisonRow(
                field: 'Service radius',
                platformValue: $location->service_radius_km !== null ? $location->service_radius_km . ' km' : null,
                googleValue: null,
                status: GoogleComparisonStatus::NotComparable,
                reason: 'Google uses regions; the platform uses a radius and city names.',
            ),
            new GoogleComparisonRow(
                field: 'Service areas',
                platformValue: $this->joinNames($location->service_area_cities ?? []),
                googleValue: isset($mirror['service_area_place_count']) && $mirror['service_area_place_count'] !== null
                    ? $mirror['service_area_place_count'] . ' Google service area(s)'
                    : null,
                status: GoogleComparisonStatus::NotComparable,
                reason: 'Google uses regions; the platform uses a radius and city names.',
            ),

            // Contract §22.6 — hours are absent from the platform
            // entirely, and Slice A does not request regularHours.
            new GoogleComparisonRow(
                field: 'Opening hours',
                platformValue: null,
                googleValue: null,
                status: GoogleComparisonStatus::NotSetOnPlatform,
                reason: 'The platform does not yet store opening hours.',
            ),

            $this->row(
                'Latitude',
                GoogleBusinessProfileComparisonRules::coordinate($location->latitude),
                GoogleBusinessProfileComparisonRules::coordinate($mirror['latitude'] ?? null),
                fn ($p, $g) => $p === $g,
            ),
            $this->row(
                'Longitude',
                GoogleBusinessProfileComparisonRules::coordinate($location->longitude),
                GoogleBusinessProfileComparisonRules::coordinate($mirror['longitude'] ?? null),
                fn ($p, $g) => $p === $g,
            ),

            new GoogleComparisonRow(
                field: 'Open status',
                platformValue: null,
                googleValue: $this->openStatusLabel($mirror['open_status'] ?? $binding->open_status),
                status: GoogleComparisonStatus::NotComparable,
                reason: 'Display only; the platform has no equivalent field.',
            ),
        ];
    }

    /**
     * Contract §22.2 — status resolution IN THIS EXACT ORDER. Rule 2 is
     * the one implementers get wrong: two empties are NOT agreement, they
     * are two absences, and the row must say so.
     */
    private function row(string $field, mixed $platform, mixed $google, callable $matches): GoogleComparisonRow
    {
        $platformValue = $this->presentable($platform);
        $googleValue = $this->presentable($google);

        // 2 and 3 — platform empty.
        if ($platformValue === null) {
            return new GoogleComparisonRow($field, null, $googleValue, GoogleComparisonStatus::NotSetOnPlatform);
        }

        // 4 — platform present, Google empty.
        if ($googleValue === null) {
            return new GoogleComparisonRow($field, $platformValue, null, GoogleComparisonStatus::NotSetOnGoogle);
        }

        // 5 / 6.
        return new GoogleComparisonRow(
            $field,
            $platformValue,
            $googleValue,
            $matches($platformValue, $googleValue) ? GoogleComparisonStatus::Match : GoogleComparisonStatus::Mismatch,
        );
    }

    /**
     * Contract §22.4 — the service model is compared on the STRUCTURAL
     * test only, never on the service areas themselves.
     *
     * @param  array<string, mixed>  $mirror
     */
    private function serviceModelRow(BusinessLocation $location, array $mirror): GoogleComparisonRow
    {
        $googleType = $mirror['service_area_type'] ?? null;
        $googleLabel = match ($googleType) {
            'CUSTOMER_LOCATION_ONLY' => 'Service area (no public address)',
            'CUSTOMER_AND_BUSINESS_LOCATION' => 'Hybrid (address plus service area)',
            null => 'Storefront (no service area)',
            default => 'Unspecified',
        };

        $platformLabel = match ($location->service_mode) {
            BusinessServiceMode::Storefront => 'Storefront',
            BusinessServiceMode::ServiceArea => 'Service area',
            BusinessServiceMode::Hybrid => 'Hybrid',
            BusinessServiceMode::Online => 'Online',
            default => null,
        };

        if ($location->service_mode === BusinessServiceMode::Online) {
            return new GoogleComparisonRow(
                field: 'Service model',
                platformValue: $platformLabel,
                googleValue: $googleLabel,
                status: GoogleComparisonStatus::NotComparable,
                reason: 'Google has no equivalent of an online-only service model.',
            );
        }

        $expected = match ($location->service_mode) {
            BusinessServiceMode::Storefront => null,
            BusinessServiceMode::ServiceArea => 'CUSTOMER_LOCATION_ONLY',
            BusinessServiceMode::Hybrid => 'CUSTOMER_AND_BUSINESS_LOCATION',
            default => null,
        };

        return new GoogleComparisonRow(
            field: 'Service model',
            platformValue: $platformLabel,
            googleValue: $googleLabel,
            status: $googleType === $expected ? GoogleComparisonStatus::Match : GoogleComparisonStatus::Mismatch,
        );
    }

    /**
     * Contract §13.3 — the shape shown when the mirror is absent or
     * expired. Platform values are still displayed (they are ours); every
     * Google column is Not comparable with an explicit reason.
     *
     * @return array<int, GoogleComparisonRow>
     */
    private function unavailableRows(Business $business, BusinessLocation $location): array
    {
        $reason = 'Refresh required — Google data is not available or has passed its retention window.';

        $fields = [
            ['Business name', $business->name],
            ['Phone', $business->phone],
            ['Website', $business->website_url],
            ['Primary category', $business->industry],
            ['Additional categories', null],
            ['Street address', null],
            ['City', null],
            ['Country', null],
            ['Service model', null],
            ['Service radius', $location->service_radius_km !== null ? $location->service_radius_km . ' km' : null],
            ['Service areas', $this->joinNames($location->service_area_cities ?? [])],
            ['Opening hours', null],
            ['Latitude', GoogleBusinessProfileComparisonRules::coordinate($location->latitude)],
            ['Longitude', GoogleBusinessProfileComparisonRules::coordinate($location->longitude)],
            ['Open status', null],
        ];

        return array_map(
            fn (array $pair) => new GoogleComparisonRow(
                field: $pair[0],
                platformValue: $this->presentable($pair[1]),
                googleValue: null,
                status: GoogleComparisonStatus::NotComparable,
                reason: $reason,
            ),
            $fields,
        );
    }

    /**
     * Coerces a platform attribute into a displayable string, or null.
     *
     * `businesses.industry` is cast to App\Enums\Business\BusinessIndustry
     * and `business_locations.service_mode` to BusinessServiceMode, so a
     * BackedEnum must be unwrapped here rather than assumed to be a
     * string. Anything that is neither scalar nor a BackedEnum becomes
     * null: the comparison never renders an object's default cast.
     */
    private function presentable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        $string = is_scalar($value) ? trim((string) $value) : '';

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<int, mixed>  $names
     */
    private function joinNames(array $names): ?string
    {
        $clean = array_values(array_filter(array_map(
            fn ($name) => is_string($name) && trim($name) !== '' ? trim($name) : null,
            $names,
        )));

        return $clean === [] ? null : implode(', ', $clean);
    }

    private function openStatusLabel(?string $status): ?string
    {
        return match ($status) {
            'OPEN' => 'Open',
            'CLOSED_PERMANENTLY' => 'Permanently closed',
            'CLOSED_TEMPORARILY' => 'Temporarily closed',
            default => null,
        };
    }
}
