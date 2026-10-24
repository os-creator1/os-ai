<?php

namespace App\Library\Messaging\Registration;

/**
 * Deterministic, code-only catalog of niche-aware registration defaults —
 * no runtime generation, no model call. Every string is a starting point
 * the customer edits; none of it is ever submitted without the customer
 * saving and submitting it themselves.
 *
 * The three plain-language choices map onto the use-case keys the
 * registration form and TelnyxProvisioningAdapter already carry
 * (customer_care / marketing / mixed); whether a carrier accepts the
 * resulting campaign is the carrier's decision and is not assumed here.
 */
final class RegistrationTemplateCatalog
{
    public const CHOICE_CARE = 'care';
    public const CHOICE_MARKETING = 'marketing';
    public const CHOICE_BOTH = 'both';

    /** @var array<string, array{label: string, use_case: string}> */
    public const CHOICES = [
        self::CHOICE_CARE => ['label' => 'Service and customer-care messages', 'use_case' => 'customer_care'],
        self::CHOICE_MARKETING => ['label' => 'Promotional and marketing messages', 'use_case' => 'marketing'],
        self::CHOICE_BOTH => ['label' => 'Both', 'use_case' => 'mixed'],
    ];

    /** Checklist key => label. The customer confirms each against their own website. */
    public const CONSENT_ITEMS = [
        'public_opt_in_url' => 'A public page where customers opt in to texts (its URL is named in the message-flow description)',
        'separate_optional_consent' => 'SMS consent is separate from other agreements and optional (not a condition of purchase)',
        'frequency_rate_disclosure' => 'Message frequency and "message and data rates may apply" are disclosed at opt-in',
        'stop_help' => 'Opt-in states that customers can reply STOP to opt out and HELP for help',
        'privacy_policy' => 'The Privacy Policy is public and covers how mobile numbers and SMS consent are used',
        'sms_terms' => 'The SMS Terms are public and linked at opt-in',
        'consent_recording' => 'Consent is recorded (who agreed, when, and to what wording)',
    ];

    /**
     * {brand} is replaced with the Business's registered brand name.
     *
     * @var array<string, array{noun: string, care: array<string, mixed>, marketing: array<string, mixed>}>
     */
    private const NICHES = [
        'photo_booth_service' => [
            'noun' => 'photo booth rental',
            'care' => [
                'topics' => ['New inquiry responses', 'Quote follow-ups', 'Booking confirmations', 'Event reminders'],
                'description' => '{brand} sends customer-care texts to people who asked about photo booth rental for their event: replies to new inquiries, quote follow-ups, booking confirmations and event-day reminders.',
                'sample_1' => '{brand}: Thanks for your photo booth inquiry! We have your event details and will send your quote shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Your photo booth is booked for your event. Reply to this text with any questions before the big day. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Photo booth package promotions', 'Seasonal specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about photo booth packages, seasonal event specials and discounts.',
                'sample_1' => '{brand}: Planning an event? Ask about our current photo booth package promotions. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Our seasonal photo booth specials are here. Reply YES and we will text you the details. Reply STOP to opt out.',
            ],
        ],
        'event_services' => [
            'noun' => 'event services',
            'care' => [
                'topics' => ['New inquiry responses', 'Quote follow-ups', 'Booking confirmations', 'Event reminders'],
                'description' => '{brand} sends customer-care texts about event service inquiries, quotes, booking confirmations and event reminders.',
                'sample_1' => '{brand}: Thanks for your event inquiry! We will follow up with a quote shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Your event booking is confirmed. Reply to this text with any questions. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Package promotions', 'Seasonal specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about event service packages, seasonal specials and discounts.',
                'sample_1' => '{brand}: Ask about our current event package promotions. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Our seasonal event specials are here. Reply YES for details. Reply STOP to opt out.',
            ],
        ],
        'photographer' => [
            'noun' => 'photography',
            'care' => [
                'topics' => ['New inquiry responses', 'Session confirmations', 'Session reminders', 'Gallery-ready notices'],
                'description' => '{brand} sends customer-care texts about photography inquiries, session confirmations, session reminders and gallery delivery.',
                'sample_1' => '{brand}: Thanks for reaching out about a photo session! We will reply with availability shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Reminder that your photo session is coming up. Reply to this text if you need to reschedule. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Session promotions', 'Seasonal mini-sessions', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about photo sessions, seasonal mini-sessions and discounts.',
                'sample_1' => '{brand}: Mini-session specials are open. Reply YES for details. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Ask about our current photo session specials. Reply STOP to opt out.',
            ],
        ],
        'wedding_vendor' => [
            'noun' => 'wedding services',
            'care' => [
                'topics' => ['New inquiry responses', 'Quote follow-ups', 'Booking confirmations', 'Wedding-date reminders'],
                'description' => '{brand} sends customer-care texts about wedding service inquiries, quotes, booking confirmations and wedding-date reminders.',
                'sample_1' => '{brand}: Congratulations, and thanks for your wedding inquiry! We will send your quote shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Your wedding booking is confirmed. Reply to this text with any questions. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Package promotions', 'Seasonal specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about wedding packages, seasonal specials and discounts.',
                'sample_1' => '{brand}: Ask about our current wedding package promotions. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Our seasonal wedding specials are here. Reply YES for details. Reply STOP to opt out.',
            ],
        ],
        'home_services' => [
            'noun' => 'home services',
            'care' => [
                'topics' => ['New inquiry responses', 'Estimate follow-ups', 'Appointment confirmations', 'Arrival reminders'],
                'description' => '{brand} sends customer-care texts about home service requests, estimates, appointment confirmations and arrival reminders.',
                'sample_1' => '{brand}: Thanks for your service request! We will follow up with an estimate shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Your appointment is confirmed. Reply to this text to reschedule. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Seasonal service promotions', 'Maintenance specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about seasonal home service promotions, maintenance specials and discounts.',
                'sample_1' => '{brand}: Ask about our current seasonal service specials. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Schedule maintenance now and ask about our current discounts. Reply STOP to opt out.',
            ],
        ],
        'professional_services' => [
            'noun' => 'professional services',
            'care' => [
                'topics' => ['New inquiry responses', 'Proposal follow-ups', 'Appointment confirmations', 'Appointment reminders'],
                'description' => '{brand} sends customer-care texts about service inquiries, proposals, appointment confirmations and reminders.',
                'sample_1' => '{brand}: Thanks for contacting us! We will follow up on your inquiry shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Reminder of your upcoming appointment. Reply to this text to reschedule. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Service promotions', 'Seasonal specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about service promotions, seasonal specials and discounts.',
                'sample_1' => '{brand}: Ask about our current service promotions. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Our seasonal specials are here. Reply YES for details. Reply STOP to opt out.',
            ],
        ],
        'other' => [
            'noun' => 'services',
            'care' => [
                'topics' => ['New inquiry responses', 'Quote follow-ups', 'Booking confirmations', 'Appointment reminders'],
                'description' => '{brand} sends customer-care texts about customer inquiries, quotes, booking confirmations and reminders.',
                'sample_1' => '{brand}: Thanks for your inquiry! We will follow up shortly. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Your booking is confirmed. Reply to this text with any questions. Reply STOP to opt out.',
            ],
            'marketing' => [
                'topics' => ['Promotions', 'Seasonal specials', 'Discounts'],
                'description' => '{brand} sends promotional texts to opted-in contacts about promotions, seasonal specials and discounts.',
                'sample_1' => '{brand}: Ask about our current promotions. Msg frequency varies. Msg&data rates may apply. Reply STOP to opt out, HELP for help.',
                'sample_2' => '{brand}: Our seasonal specials are here. Reply YES for details. Reply STOP to opt out.',
            ],
        ],
    ];

    /** @var list<string> */
    private const CARE_USE_CASES = ['customer_care', 'account_notifications', 'appointment_reminders', 'two_factor'];

    public static function choiceForUseCase(?string $useCase): ?string
    {
        foreach (self::CHOICES as $choice => $definition) {
            if ($definition['use_case'] === $useCase) {
                return $choice;
            }
        }

        return null;
    }

    public static function useCaseForChoice(string $choice): ?string
    {
        return self::CHOICES[$choice]['use_case'] ?? null;
    }

    /**
     * Template defaults for a niche and a stored use-case key; null when
     * the use case is not one of the three plain choices (nothing is
     * suggested for it — and never a marketing default for a niche).
     *
     * @return array{topics: list<string>, campaign_description: string, sample_message_1: string, sample_message_2: string, opt_in_method: string}|null
     */
    public static function forUseCase(?string $industry, ?string $useCase, string $brand, ?string $websiteUrl): ?array
    {
        $choice = self::choiceForUseCase($useCase);

        if ($choice === null) {
            return null;
        }

        $niche = self::NICHES[$industry ?? ''] ?? self::NICHES['other'];
        $care = $niche['care'];
        $marketing = $niche['marketing'];

        [$topics, $description, $sample1, $sample2] = match ($choice) {
            self::CHOICE_CARE => [$care['topics'], $care['description'], $care['sample_1'], $care['sample_2']],
            self::CHOICE_MARKETING => [$marketing['topics'], $marketing['description'], $marketing['sample_1'], $marketing['sample_2']],
            default => [
                array_merge($care['topics'], $marketing['topics']),
                $care['description'] . ' It also sends promotional texts to opted-in contacts about packages, seasonal specials and discounts.',
                $care['sample_1'],
                $marketing['sample_1'],
            ],
        };

        return [
            'topics' => $topics,
            'campaign_description' => strtr($description, ['{brand}' => $brand]),
            'sample_message_1' => strtr($sample1, ['{brand}' => $brand]),
            'sample_message_2' => strtr($sample2, ['{brand}' => $brand]),
            'opt_in_method' => self::messageFlow($choice, $brand, $websiteUrl),
        ];
    }

    /**
     * Draft wording only. It describes the flow the customer must have in
     * place; it does not assert that their website already does — the
     * consent checklist makes the customer confirm that separately.
     */
    private static function messageFlow(string $choice, string $brand, ?string $websiteUrl): string
    {
        $where = $websiteUrl !== null && $websiteUrl !== ''
            ? 'on ' . $websiteUrl . ' (the exact opt-in page URL: ' . rtrim($websiteUrl, '/') . '/...)'
            : 'on our website (opt-in page URL: add it here)';

        $what = $choice === self::CHOICE_CARE
            ? 'service and customer-care texts such as replies to inquiries, quotes, booking confirmations and reminders'
            : ($choice === self::CHOICE_MARKETING
                ? 'promotional texts such as package promotions, seasonal specials and discounts'
                : 'service and customer-care texts and, separately, promotional texts');

        return 'Customers enter their mobile number ' . $where . ' and choose to receive ' . $what . ' from ' . $brand
            . ' by checking an optional SMS consent box that is separate from any other agreement. The consent wording states message frequency, that message and data rates may apply, that they can reply STOP to opt out and HELP for help, and links our Privacy Policy and SMS Terms. Consent is not a condition of purchase.';
    }

    public static function isCareOnly(?string $useCase): bool
    {
        return in_array($useCase, self::CARE_USE_CASES, true);
    }
}
