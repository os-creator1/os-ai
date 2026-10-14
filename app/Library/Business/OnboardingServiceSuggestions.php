<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessIndustry;

/**
 * Selectable service ideas shown on the onboarding Services step. They are
 * only ever suggestions: nothing here is added to a Business unless the
 * customer ticks it, and OnboardingManager knows nothing about any niche.
 */
final class OnboardingServiceSuggestions
{
    private const BY_INDUSTRY = [
        'photo_booth_service' => [
            'Open-air photo booth',
            '360 photo booth',
            'Glam photo booth',
            'Mirror photo booth',
            'Print photo booth',
            'Audio guestbook',
        ],
    ];

    /** @return array<int, string> */
    public static function for(?BusinessIndustry $industry): array
    {
        return $industry === null ? [] : (self::BY_INDUSTRY[$industry->value] ?? []);
    }
}
