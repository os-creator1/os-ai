<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceStatus;
use App\Models\Business;
use App\Models\CustomerOnboarding;

/**
 * Builds the editable "business listing card" the customer confirms after
 * choosing a Google profile. Pure presentation logic: it reads the sealed
 * Google snapshot and what MotionGrove already holds, and decides, per field,
 * what the customer sees and WHY:
 *
 *   imported   - the value came from Google and is unchanged
 *   unverified - imported, but Google has not confirmed the profile itself
 *   suggested  - derived from something Google supplied, needs a confirmation
 *   saved      - MotionGrove already holds a confirmed value; it is kept, and
 *                Google's different value is only offered, never applied
 *   edited     - the customer changed it
 *   missing    - nothing known; blank, never invented
 *
 * It never defaults a value Google did not supply (no invented description,
 * service, picture, currency, timezone or country).
 */
final class GoogleOnboardingPreview
{
    public const STATUS_LABELS = [
        'imported' => 'Imported from Google',
        'unverified' => 'Imported · unverified',
        'suggested' => 'Suggested · please confirm',
        'saved' => 'Already saved',
        'edited' => 'Edited by you',
        'missing' => 'Missing',
    ];

    /** Google's business type -> MotionGrove service mode. An unknown type maps to nothing (the customer is asked). */
    private const MODE_BY_SERVICE_AREA_TYPE = [
        'CUSTOMER_LOCATION_ONLY' => 'service_area',
        'CUSTOMER_AND_BUSINESS_LOCATION' => 'hybrid',
    ];

    /**
     * @param  array<string, mixed>  $snapshot   what Google supplied (see GoogleOnboardingBridge)
     * @param  array<string, mixed>  $submitted  posted values when the card is re-shown after a validation error
     * @return array{fields: array<string, array<string, mixed>>, notes: array<int, string>, verified: bool, additionalCategories: array<int, string>, openStatus: ?string, missingEssentials: array<int, string>}
     */
    public function build(array $snapshot, Business $business, CustomerOnboarding $onboarding, array $submitted = []): array
    {
        $provisional = app(OnboardingManager::class)->isProvisionalBusiness($onboarding);
        $location = $business->locations()->where('is_primary', true)->first();
        $primaryService = $business->services()
            ->where('status', BusinessServiceStatus::Active)
            ->where('is_primary', true)
            ->orderBy('id')
            ->first();

        $verified = ($snapshot['verified'] ?? false) === true;
        $categoryName = $this->text($snapshot['category'] ?? null);
        $matchedIndustry = $this->industryFor($categoryName);

        $savedIndustry = $provisional ? null : $business->industry?->value;
        $savedOther = $provisional ? null : $this->text($business->industry_other);

        $imported = [
            'name' => $this->text($snapshot['title'] ?? null),
            'industry' => $matchedIndustry,
            'phone' => $this->text($snapshot['phone'] ?? null),
            'website_url' => $this->text($snapshot['website'] ?? null),
            'city' => $this->text($snapshot['locality'] ?? null),
            'service_mode' => self::MODE_BY_SERVICE_AREA_TYPE[$snapshot['service_area_type'] ?? ''] ?? null,
        ];

        $saved = [
            'name' => $provisional ? null : $this->text($business->name),
            'industry' => $savedIndustry,
            'phone' => $this->text($business->phone),
            'website_url' => $this->text($business->website_url),
            'city' => $this->text($location?->city),
            'service_mode' => $location?->service_mode?->value,
            'region' => $this->text($location?->region),
            'address_line_1' => $this->text($location?->address_line_1),
            'description' => $this->text($business->description),
            'main_service' => $this->text($primaryService?->name),
        ];

        $fields = [];

        foreach (['name', 'industry', 'phone', 'website_url', 'city', 'service_mode'] as $key) {
            $fields[$key] = $this->field($key, $imported[$key], $saved[$key], $submitted, $verified);
        }

        foreach (['region', 'address_line_1', 'description'] as $key) {
            $fields[$key] = $this->field($key, null, $saved[$key], $submitted, $verified);
        }

        // The main service is only ever SUGGESTED from the category — a category is not a reliable service list.
        $fields['main_service'] = $this->field('main_service', null, $saved['main_service'], $submitted, $verified);

        if ($fields['main_service']['status'] === 'missing' && $categoryName !== null && ! array_key_exists('main_service', $submitted)) {
            $fields['main_service']['value'] = $categoryName;
            $fields['main_service']['status'] = 'suggested';
            $fields['main_service']['imported'] = $categoryName;
        }

        // An industry Google's category cannot be matched to stays blank: the customer picks the closest one.
        $fields['industry']['category'] = $categoryName;
        $fields['industry']['other'] = array_key_exists('industry_other', $submitted)
            ? $this->text($submitted['industry_other'])
            : $savedOther;

        $notes = [];

        if (! $verified) {
            $notes[] = 'Google has not confirmed this profile as verified, so everything below is marked unverified until you check it.';
        }

        if (($snapshot['duplicate'] ?? false) === true) {
            $notes[] = 'Google lists this profile as a duplicate of another one. Make sure it is the profile you want.';
        }

        if (in_array($snapshot['open_status'] ?? null, ['CLOSED_PERMANENTLY', 'CLOSED_TEMPORARILY'], true)) {
            $notes[] = 'Google shows this business as closed. Check that this is the right profile.';
        }

        $country = strtoupper((string) ($snapshot['country'] ?? ''));

        if ($country !== '' && strtoupper((string) $business->country_code) !== $country) {
            $notes[] = "Google lists this business in {$country}, but your setup uses {$business->country_code}. Check your country below.";
        }

        return [
            'fields' => $fields,
            'notes' => $notes,
            'verified' => $verified,
            'additionalCategories' => array_values(array_filter((array) ($snapshot['additional_categories'] ?? []), 'is_string')),
            'openStatus' => $this->text($snapshot['open_status'] ?? null),
            'missingEssentials' => array_keys(array_filter(
                $fields,
                fn (array $field, string $key) => $field['essential'] && $field['status'] === 'missing',
                ARRAY_FILTER_USE_BOTH,
            )),
        ];
    }

    /** Keys the customer must have answered before setup can be completed (everything else is optional). */
    public static function isEssential(string $key): bool
    {
        return in_array($key, ['name', 'industry', 'service_mode', 'city', 'main_service'], true);
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function field(string $key, ?string $imported, ?string $saved, array $submitted, bool $verified): array
    {
        $posted = array_key_exists($key, $submitted) ? $this->text($submitted[$key]) : null;
        $reshown = array_key_exists($key, $submitted);

        // What the field holds: the customer's own input first, then what MotionGrove already holds, then Google's value.
        $value = $reshown ? $posted : ($saved ?? $imported);

        if ($value === null) {
            $status = 'missing';
        } elseif ($saved !== null && $value === $saved) {
            // Kept as it was. Google's different value is offered below, never applied.
            $status = ($imported !== null && $imported === $saved) ? ($verified ? 'imported' : 'unverified') : 'saved';
        } elseif ($imported !== null && $value === $imported) {
            $status = $verified ? 'imported' : 'unverified';
        } else {
            $status = 'edited';
        }

        return [
            'key' => $key,
            'value' => $value,
            'status' => $status,
            'imported' => $imported,
            'saved' => $saved,
            // Google differs from something already confirmed here: offer it, do not apply it.
            'offer' => ($saved !== null && $imported !== null && $saved !== $imported) ? $imported : null,
            'essential' => self::isEssential($key),
        ];
    }

    private function industryFor(?string $categoryName): ?string
    {
        if ($categoryName === null) {
            return null;
        }

        foreach (BusinessIndustry::cases() as $industry) {
            if ($industry !== BusinessIndustry::Other && mb_strtolower($industry->label()) === mb_strtolower($categoryName)) {
                return $industry->value;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
