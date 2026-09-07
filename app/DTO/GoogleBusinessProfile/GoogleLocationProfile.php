<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfile\GoogleProviderValueNormalizer as N;

/**
 * GBP Slice A contract §21.2 — the BOUNDED profile mirror.
 *
 * The key set below is exhaustive and closed: toMirrorArray() emits
 * exactly the contract's keys and no others. Deliberately absent:
 * address_line_1 and every street-level address component, profile
 * description, hours of any kind, attributes, serviceItems, reviews,
 * ratings, media, and metrics. A key not listed in the contract must not
 * be written.
 */
final readonly class GoogleLocationProfile
{
    private const OPEN_STATUSES = [
        'OPEN_FOR_BUSINESS_UNSPECIFIED',
        'OPEN',
        'CLOSED_PERMANENTLY',
        'CLOSED_TEMPORARILY',
    ];

    private const SERVICE_AREA_TYPES = [
        'BUSINESS_TYPE_UNSPECIFIED',
        'CUSTOMER_LOCATION_ONLY',
        'CUSTOMER_AND_BUSINESS_LOCATION',
    ];

    /**
     * @param  array<int, string>  $additionalCategoryIds
     * @param  array<int, string>  $additionalCategoryNames
     */
    public function __construct(
        public string $resourceName,
        public ?string $title,
        public ?string $phonePrimary,
        public ?string $websiteUri,
        public ?string $storeCode,
        public ?string $primaryCategoryId,
        public ?string $primaryCategoryName,
        public array $additionalCategoryIds,
        public array $additionalCategoryNames,
        public ?string $serviceAreaType,
        public ?int $serviceAreaPlaceCount,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $mapsUri,
        public ?string $newReviewUri,
        public ?string $locality,
        public ?string $regionCode,
        public ?bool $hasVoiceOfMerchant,
        public ?bool $hasPendingEdits,
        public ?string $openStatus,
        public ?string $duplicateOfResourceName,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromProviderArray(array $raw, bool $addressPermitted): ?self
    {
        $resourceName = N::locationResourceName($raw['name'] ?? null);

        if ($resourceName === null) {
            return null;
        }

        // Contract §20.3 — at most 10 Google categories retained
        // (primary + up to 9 additional).
        $additionalIds = [];
        $additionalNames = [];

        foreach (N::listOf(N::dig($raw, ['categories', 'additionalCategories']), 9) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = N::string($category['name'] ?? null, 191);

            if ($id === null) {
                continue;
            }

            $additionalIds[] = $id;
            $additionalNames[] = N::string($category['displayName'] ?? null, 191) ?? $id;
        }

        $placeInfos = N::listOf(N::dig($raw, ['serviceArea', 'places', 'placeInfos']), 20);

        return new self(
            resourceName: $resourceName,
            title: N::string($raw['title'] ?? null, 191),
            phonePrimary: N::string(N::dig($raw, ['phoneNumbers', 'primaryPhone']), 64),
            websiteUri: N::httpsUrl($raw['websiteUri'] ?? null),
            storeCode: N::string($raw['storeCode'] ?? null, 64),
            primaryCategoryId: N::string(N::dig($raw, ['categories', 'primaryCategory', 'name']), 191),
            primaryCategoryName: N::string(N::dig($raw, ['categories', 'primaryCategory', 'displayName']), 191),
            additionalCategoryIds: $additionalIds,
            additionalCategoryNames: $additionalNames,
            serviceAreaType: N::enumValue(N::dig($raw, ['serviceArea', 'businessType']), self::SERVICE_AREA_TYPES),
            // Count only — the place names themselves are Google Content
            // we have no comparison for (contract §22.4) and no reason to
            // store.
            serviceAreaPlaceCount: $placeInfos === [] ? null : count($placeInfos),
            latitude: N::float(N::dig($raw, ['latlng', 'latitude'])),
            longitude: N::float(N::dig($raw, ['latlng', 'longitude'])),
            mapsUri: N::httpsUrl(N::dig($raw, ['metadata', 'mapsUri'])),
            newReviewUri: N::httpsUrl(N::dig($raw, ['metadata', 'newReviewUri'])),
            // Contract §23.3 — locality and regionCode only, and only when
            // §23.2 permitted the field. Every other address component is
            // dropped unconditionally by never being read.
            locality: $addressPermitted ? N::string(N::dig($raw, ['storefrontAddress', 'locality']), 120) : null,
            regionCode: $addressPermitted ? N::regionCode(N::dig($raw, ['storefrontAddress', 'regionCode'])) : null,
            hasVoiceOfMerchant: N::boolean(N::dig($raw, ['metadata', 'hasVoiceOfMerchant'])),
            hasPendingEdits: N::boolean(N::dig($raw, ['metadata', 'hasPendingEdits'])),
            openStatus: N::enumValue(N::dig($raw, ['openInfo', 'status']), self::OPEN_STATUSES),
            duplicateOfResourceName: N::locationResourceName(N::dig($raw, ['metadata', 'duplicateLocation'])),
        );
    }

    /**
     * Contract §21.2 — the exhaustive, closed mirror key set. Anything not
     * listed here is not written to business_google_locations.profile_mirror.
     *
     * @return array<string, mixed>
     */
    public function toMirrorArray(): array
    {
        return [
            'title' => $this->title,
            'phone_primary' => $this->phonePrimary,
            'website_uri' => $this->websiteUri,
            'store_code' => $this->storeCode,
            'primary_category_id' => $this->primaryCategoryId,
            'primary_category_name' => $this->primaryCategoryName,
            'additional_category_ids' => $this->additionalCategoryIds,
            'additional_category_names' => $this->additionalCategoryNames,
            'service_area_type' => $this->serviceAreaType,
            'service_area_place_count' => $this->serviceAreaPlaceCount,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'maps_uri' => $this->mapsUri,
            'new_review_uri' => $this->newReviewUri,
            'locality' => $this->locality,
            'region_code' => $this->regionCode,
        ];
    }
}
