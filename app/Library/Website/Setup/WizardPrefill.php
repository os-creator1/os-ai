<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Catalog\CatalogFeatureList;
use App\Models\Business;
use App\Models\BusinessBackdrop;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessService;

/**
 * Website V1 final — "never ask twice". A question whose answer already
 * exists in the Business OS is shown with that value filled in, so the owner
 * only confirms it: the business name, phone, email and description they gave
 * when they created the business, the service areas already saved on their
 * primary location, and the main goal already saved on their knowledge
 * profile.
 *
 * Display only: nothing is written until the owner submits the screen, and an
 * answer they have already given always wins. Services are deliberately NOT
 * prefilled (the applier keys its rows by questionnaire item, so an echo of an
 * existing service would be saved a second time).
 */
final class WizardPrefill
{
    /** Business columns a `business`-module step may be prefilled from. */
    private const BUSINESS_FIELDS = ['name', 'phone', 'email', 'description'];

    /**
     * @param  array<int, array<string, mixed>>  $steps  the steps of the screen being shown
     * @param  array<string, mixed>  $answers  the owner's saved answers
     * @return array<string, mixed> step key => value, only for steps with no saved answer
     */
    public static function forSteps(Business $business, array $steps, array $answers): array
    {
        $defaults = [];

        foreach ($steps as $step) {
            $key = (string) ($step['key'] ?? '');

            if ($key === '' || self::hasAnswer($answers[$key] ?? null)) {
                continue;
            }

            $value = self::valueFor($business, $step);

            if (self::hasAnswer($value)) {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private static function valueFor(Business $business, array $step): mixed
    {
        $module = $step['target_module'] ?? null;
        $field = $step['target_field'] ?? null;

        if ($module === 'business' && in_array($field, self::BUSINESS_FIELDS, true)) {
            $value = trim((string) $business->{$field});

            return $value === '' ? null : $value;
        }

        if ($module === 'business_location' && ($step['input_type'] ?? null) === 'location') {
            return self::primaryLocationPlace($business);
        }

        if ($module === 'business_location' && ($step['input_type'] ?? null) === 'string_list') {
            $cities = $business->primaryLocation()->first()?->service_area_cities;

            return is_array($cities) ? ServiceAreaList::normalize($cities) : null;
        }

        if ($module === 'business_service' && ($step['input_type'] ?? null) === 'repeatable_group' && isset($step['row_fields'])) {
            return self::existingServiceRows($business, $step);
        }

        // "Do you offer different backdrops?" is already answered when the Business has real, available backdrops.
        if ($module === 'answers' && ($step['key'] ?? null) === 'offers_backdrops' && ($step['input_type'] ?? null) === 'boolean') {
            return BusinessBackdrop::where('business_id', $business->id)->where('availability', true)->exists() ? true : null;
        }

        if ($module === 'backdrop' && ($step['input_type'] ?? null) === 'repeatable_group' && isset($step['categories'])) {
            return self::existingBackdropRows($business);
        }

        if ($module === 'knowledge_profile' && $field === 'differentiators' && ($step['input_type'] ?? null) === 'multi_select') {
            return self::confirmedAdvantages($business, $step);
        }

        if ($module === 'knowledge_profile' && $field === 'primary_conversion_goal' && ($step['input_type'] ?? null) === 'select') {
            $goal = BusinessKnowledgeProfile::where('business_id', $business->id)->value('primary_conversion_goal');
            $options = array_keys((array) ($step['options'] ?? []));

            return is_string($goal) && in_array($goal, $options, true) ? $goal : null;
        }

        return null;
    }

    /**
     * The Business's own base place, from its canonical primary location
     * (falling back to the Business country alone) — what the wizard shows
     * as "where are you based?" so the owner only confirms or corrects it.
     *
     * @return array{city: ?string, region: ?string, country_code: ?string}|null
     */
    public static function primaryLocationPlace(Business $business): ?array
    {
        $location = $business->primaryLocation()->first();

        $place = [
            'city' => self::clean($location?->city),
            'region' => self::clean($location?->region),
            'country_code' => self::clean($location?->country_code ?: $business->country_code),
        ];

        return array_filter($place) === [] ? null : $place;
    }

    /**
     * The Business's real, active services of this step's kind, as editable
     * cards. Each is "adopted" by its own id (`svc:<uid>`) — never matched by
     * name — so confirming the card updates THAT record instead of creating a
     * second one, and its photos stay attached to it.
     *
     * @param  array<string, mixed>  $step
     * @return array<int, array<string, mixed>>|null
     */
    private static function existingServiceRows(Business $business, array $step): ?array
    {
        $kind = $step['item_kind'] ?? BusinessService::KIND_SERVICE;
        $presetsByLabel = [];
        foreach ((array) ($step['presets'] ?? []) as $preset) {
            $presetsByLabel[mb_strtolower((string) $preset['label'])] = $preset['key'];
        }

        $services = BusinessService::where('business_id', $business->id)
            ->where('status', BusinessServiceStatus::Active->value)
            ->where('kind', $kind)
            ->with('images')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $rows = $services->map(function (BusinessService $service) use ($presetsByLabel) {
            [$prose, $features] = CatalogFeatureList::split($service->description);
            $images = $service->images->values();

            return [
                'key' => $service->source_questionnaire_item_key ?: 'svc:' . $service->uid,
                'name' => $service->name,
                'description' => $prose,
                'features' => $features,
                'starting_price' => $service->starting_price !== null ? (float) $service->starting_price : null,
                'currency_code' => $service->currency_code,
                'visible' => (bool) $service->website_visible,
                'preset' => $presetsByLabel[mb_strtolower($service->name)] ?? null,
                'image_path' => $images->first()?->path,
                'extra_image_paths' => $images->slice(1)->pluck('path')->values()->all(),
            ];
        })->all();

        return $rows === [] ? null : $rows;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function existingBackdropRows(Business $business): ?array
    {
        $rows = BusinessBackdrop::where('business_id', $business->id)
            ->where('availability', true)
            ->with('images')
            ->orderBy('position')->orderBy('id')
            ->get()
            ->map(function (BusinessBackdrop $backdrop) {
                $image = $backdrop->images->sortBy('position')->first();

                return [
                    'key' => $backdrop->source_questionnaire_item_key ?: 'bd:' . $backdrop->uid,
                    'name' => $backdrop->name,
                    'description' => $backdrop->description,
                    'category' => $backdrop->category,
                    'availability' => true,
                    'image_path' => $image?->path,
                    'alt_text' => $image?->alt_text,
                ];
            })->all();

        return $rows === [] ? null : $rows;
    }

    /**
     * Facts the owner already confirmed on the knowledge profile, as the
     * selected option keys. A fact that matches no offered option is kept out
     * of the preselection (never silently dropped from the profile — only the
     * apply step could change it).
     *
     * @param  array<string, mixed>  $step
     * @return array<int, string>|null
     */
    private static function confirmedAdvantages(Business $business, array $step): ?array
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = array_map(fn ($fact) => mb_strtolower(trim((string) $fact)), (array) ($profile?->differentiators ?? []));
        $selected = [];

        foreach ((array) ($step['options'] ?? []) as $value => $label) {
            if (in_array(mb_strtolower((string) $label), $confirmed, true)) {
                $selected[] = (string) $value;
            }
        }

        return $selected === [] ? null : $selected;
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function hasAnswer(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
