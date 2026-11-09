<?php

namespace App\Library\Messaging\Registration;

/**
 * Provider-accepted value sets and conversions for the carrier forms,
 * taken from Telnyx's API reference (toll-free verification: messageVolume,
 * useCase, full state name; 10DLC brand: vertical, 2-letter state code).
 * Nothing here is guessed at submission time: an unmapped value is refused
 * before any funding or provider call.
 */
final class CarrierRegistrationOptions
{
    /** Toll-free verification `messageVolume` values offered to customers. */
    public const MESSAGE_VOLUMES = [
        '10' => 'Up to 10 per month',
        '100' => 'Up to 100 per month',
        '1,000' => 'Up to 1,000 per month',
        '10,000' => 'Up to 10,000 per month',
        '100,000' => 'Up to 100,000 per month',
    ];

    /** 10DLC brand `vertical` enum (the customer chooses; it is never inferred silently). */
    public const BRAND_VERTICALS = [
        'AGRICULTURE' => 'Agriculture', 'COMMUNICATION' => 'Communication', 'CONSTRUCTION' => 'Construction / home services',
        'EDUCATION' => 'Education', 'ENERGY' => 'Energy', 'ENTERTAINMENT' => 'Entertainment / events', 'FINANCIAL' => 'Financial',
        'GAMBLING' => 'Gambling', 'GOVERNMENT' => 'Government', 'HEALTHCARE' => 'Healthcare', 'HOSPITALITY' => 'Hospitality',
        'HUMAN_RESOURCES' => 'Human resources', 'INSURANCE' => 'Insurance', 'LEGAL' => 'Legal', 'MANUFACTURING' => 'Manufacturing',
        'NGO' => 'Non-profit', 'POLITICAL' => 'Political', 'POSTAL' => 'Postal', 'PROFESSIONAL' => 'Professional services',
        'REAL_ESTATE' => 'Real estate', 'RETAIL' => 'Retail', 'TECHNOLOGY' => 'Technology', 'TRANSPORTATION' => 'Transportation',
    ];

    /** Business industry => suggested vertical (prefill only; the customer confirms). */
    public const INDUSTRY_SUGGESTION = [
        'photo_booth_service' => 'ENTERTAINMENT', 'event_services' => 'ENTERTAINMENT', 'wedding_vendor' => 'ENTERTAINMENT',
        'photographer' => 'PROFESSIONAL', 'professional_services' => 'PROFESSIONAL', 'home_services' => 'CONSTRUCTION',
    ];

    /**
     * Low Volume Mixed: the customer's REAL messaging purposes (each maps to a
     * TCR sub-use-case). Nothing is added for the customer — a purpose is only
     * registered when they tick it.
     */
    public const LOW_VOLUME_PURPOSES = [
        'care' => ['label' => 'Service and customer care (inquiries, quotes, booking confirmations, reminders)', 'sub_usecase' => 'CUSTOMER_CARE'],
        'marketing' => ['label' => 'Promotions and marketing (specials, discounts, offers)', 'sub_usecase' => 'MARKETING'],
    ];

    /** Internal use case => toll-free verification `useCase` enum value. */
    public const TOLL_FREE_USE_CASES = [
        'customer_care' => 'Conversational / Alerts',
        'marketing' => 'General Marketing',
        'mixed' => 'Mixed',
        'account_notifications' => 'App Notifications',
        'appointment_reminders' => 'Appointments',
        'two_factor' => '2FA',
    ];

    private const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
        'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan',
        'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
        'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
        'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** Two-letter code for a US state typed as a code or a full name; null when not a US state. */
    public static function stateCode(?string $input): ?string
    {
        $input = trim((string) $input);

        if (isset(self::STATES[strtoupper($input)])) {
            return strtoupper($input);
        }

        $code = array_search(strtolower($input), array_map('strtolower', self::STATES), true);

        return $code === false ? null : (string) $code;
    }

    /** Full state name (toll-free verification requires it, not the code). */
    public static function stateName(?string $input): ?string
    {
        $code = self::stateCode($input);

        return $code === null ? null : self::STATES[$code];
    }
}
