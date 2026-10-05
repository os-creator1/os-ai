<?php

namespace App\Library\Website\Setup;

use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;

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

        if ($module === 'business_location' && ($step['input_type'] ?? null) === 'string_list') {
            $cities = $business->primaryLocation()->first()?->service_area_cities;

            return is_array($cities) ? ServiceAreaList::normalize($cities) : null;
        }

        if ($module === 'knowledge_profile' && $field === 'primary_conversion_goal' && ($step['input_type'] ?? null) === 'select') {
            $goal = BusinessKnowledgeProfile::where('business_id', $business->id)->value('primary_conversion_goal');
            $options = array_keys((array) ($step['options'] ?? []));

            return is_string($goal) && in_array($goal, $options, true) ? $goal : null;
        }

        return null;
    }

    private static function hasAnswer(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
