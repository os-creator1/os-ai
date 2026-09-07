<?php

namespace App\Library\GoogleBusinessProfile;

use App\Enums\Business\BusinessServiceMode;
use App\Models\Business;
use App\Models\BusinessLocation;

/**
 * GBP Slice A contract §20 / §23.2 — the single source of the read mask,
 * and ENFORCEMENT POINT 1 of the private-address invariant.
 *
 * Google makes `readMask` REQUIRED on accounts.locations.list. That turns
 * the strongest available privacy control into the default one: when the
 * platform must not see a street address, we simply never ask Google for
 * it. An address that is never requested cannot be leaked by a DTO, a
 * view, a log or an audit row — and it makes the undocumented question of
 * whether Google redacts a suppressed address for an authorized manager
 * irrelevant (contract §23.3).
 *
 * THE DEFAULT WHEN IN DOUBT IS OMISSION.
 */
final class GoogleBusinessProfileReadMask
{
    /**
     * Contract §20.1 — exactly these fields, and nothing else. No
     * `profile`, no `regularHours`, no `specialHours`, no `moreHours`, no
     * `serviceItems`, no `labels`, no `adWordsLocationExtensions`, no
     * `relationshipData`. A field not needed by §21 or §22 is not
     * requested.
     *
     * @var array<int, string>
     */
    private const BASE_FIELDS = [
        'name',
        'title',
        'storeCode',
        'phoneNumbers',
        'websiteUri',
        'categories',
        'latlng',
        'openInfo',
        'metadata',
        'serviceArea',
    ];

    private const ADDRESS_FIELD = 'storefrontAddress';

    /**
     * The mask for reading ONE bound location.
     *
     * @return array<int, string>
     */
    public function forLocation(BusinessLocation $location): array
    {
        return $this->build($this->addressPermittedForLocation($location));
    }

    /**
     * The mask for ENUMERATION, where no BusinessLocation has been chosen
     * yet. Contract §23.2: the mask omits storefrontAddress UNLESS EVERY
     * BusinessLocation in the Business permits it.
     *
     * @return array<int, string>
     */
    public function forEnumeration(Business $business): array
    {
        return $this->build($this->addressPermittedForEnumeration($business));
    }

    /**
     * Contract §23.2 — the four conditions under which a location's
     * street address must never be requested. Any one of them is enough.
     */
    public function addressPermittedForLocation(BusinessLocation $location): bool
    {
        if ($location->public_address !== true) {
            return false;
        }

        return match ($location->service_mode) {
            BusinessServiceMode::ServiceArea, BusinessServiceMode::Online => false,
            // Hybrid is treated as service-area unless consent is true —
            // which the public_address check above has already proven.
            BusinessServiceMode::Hybrid, BusinessServiceMode::Storefront => true,
            default => false,
        };
    }

    /**
     * Contract §23.2 — enumeration permits the address only when EVERY
     * location in the Business permits it. A Business with no locations
     * at all does not permit it either (fail closed).
     */
    public function addressPermittedForEnumeration(Business $business): bool
    {
        $locations = BusinessLocation::query()->where('business_id', $business->id)->get();

        if ($locations->isEmpty()) {
            return false;
        }

        foreach ($locations as $location) {
            if (! $this->addressPermittedForLocation($location)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function build(bool $addressPermitted): array
    {
        $fields = self::BASE_FIELDS;

        if ($addressPermitted) {
            $fields[] = self::ADDRESS_FIELD;
        }

        return $fields;
    }
}
