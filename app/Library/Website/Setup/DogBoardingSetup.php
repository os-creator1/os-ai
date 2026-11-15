<?php

namespace App\Library\Website\Setup;

use App\Models\Business;
use App\Models\QuestionnaireResponse;
use Illuminate\Support\Str;

/**
 * Reads a Dog Boarding setup response: the OPERATOR's own policy and facility answers, in their own words.
 *
 * Nothing here writes a policy. A value is returned only when the owner typed it, so an unanswered question produces no
 * sentence anywhere on the site, and the site never states a vaccination rule, safety promise, capacity, price or
 * credential the owner did not enter.
 */
final class DogBoardingSetup
{
    public const NICHE = 'dog_boarding';

    /** Policy answers shown on the Boarding Policies page, in reading order: answer key => label. */
    public const POLICIES = [
        'vaccination_requirements' => 'Vaccinations',
        'age_requirements' => 'Age',
        'spay_neuter_requirements' => 'Spay and neuter',
        'temperament_assessment' => 'Temperament assessment and trial day',
        'feeding_medication' => 'Feeding and medication',
        'checkin_checkout' => 'Check-in and check-out',
        'emergency_contact' => 'Emergency contacts',
        'deposit_policy' => 'Deposits',
        'cancellation_policy' => 'Cancellations',
    ];

    /** Facility answers shown on the Dog Boarding service page and About page: answer key => label. */
    public const FACILITY = [
        'facility_description' => 'Our facility',
        'indoor_spaces' => 'Indoor spaces',
        'outdoor_spaces' => 'Outdoor spaces',
        'sleeping_arrangements' => 'Where dogs sleep',
        'daily_routine' => 'A typical day',
        'supervision_approach' => 'Supervision',
        'group_play_policy' => 'Group play',
    ];

    public static function appliesTo(?QuestionnaireResponse $response): bool
    {
        return $response !== null && $response->version->questionnaireDefinition?->niche_key === self::NICHE;
    }

    public static function appliesToBusiness(Business $business): bool
    {
        return app(QuestionnaireResolver::class)->nicheKeyFor($business) === self::NICHE;
    }

    /** The owner's trimmed text for an answer key, or null when blank. */
    public static function text(array $answers, string $key): ?string
    {
        $value = $answers[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : Str::limit($value, 2000, '');
    }

    /**
     * @param  array<string, string>  $map  answer key => label
     * @return list<array{key: string, label: string, text: string}>
     */
    public static function items(array $answers, array $map): array
    {
        $out = [];

        foreach ($map as $key => $label) {
            $text = self::text($answers, $key);

            if ($text !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'text' => $text];
            }
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, text: string}> */
    public static function policies(array $answers): array
    {
        return self::items($answers, self::POLICIES);
    }

    /** @return list<array{key: string, label: string, text: string}> */
    public static function facility(array $answers): array
    {
        return self::items($answers, self::FACILITY);
    }

    /**
     * The Boarding Policies page, as the planner's custom section: only what the owner wrote, each under its own heading.
     * Null when they wrote no policy at all, so no empty policies page is ever planned.
     *
     * @return ?array{title: string, layout: string, body: ?string, images: array<int, string>}
     */
    public static function policiesSection(array $answers): ?array
    {
        $policies = self::policies($answers);

        if ($policies === []) {
            return null;
        }

        return [
            'title' => 'Boarding Policies',
            'layout' => 'stacked',
            'body' => implode("\n\n", array_map(fn (array $p) => $p['label'] . ': ' . $p['text'], $policies)),
            'images' => [],
        ];
    }

    /** "Mon–Fri 7:00–10:00" style hours the owner typed; null when none. */
    public static function hoursLine(array $answers): ?string
    {
        $parts = [];

        if (($drop = self::text($answers, 'dropoff_hours')) !== null) {
            $parts[] = 'Drop-off: ' . $drop;
        }

        if (($pick = self::text($answers, 'pickup_hours')) !== null) {
            $parts[] = 'Pickup: ' . $pick;
        }

        return $parts === [] ? null : implode('. ', $parts) . '.';
    }

    /**
     * Default About text: a plain, factual starting point the owner edits. Uses only the name, the confirmed city, and the
     * services they entered. No years in business, awards, staff numbers, supervision or safety claims.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function aboutDefault(Business $business, array $answers, int $variant = 0): string
    {
        $name = trim((string) $business->name) ?: 'We';
        $city = trim((string) ($business->primaryLocation()->first()?->city ?? ''));
        $where = $city !== '' ? " in {$city}" : '';
        $services = collect((array) ($answers['dog_services'] ?? []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== '' && ($row['visible'] ?? true))
            ->map(fn ($row) => Str::lower(trim((string) $row['name'])))->values()->all();
        $offer = $services === [] ? 'dog boarding' : self::join($services);

        $lines = [
            "{$name} offers {$offer}{$where}. Tell us about your dog and the dates you have in mind, and we will let you know whether we can look after them.",
            "{$name} looks after dogs{$where} while their owners are away. We offer {$offer}; send a request and we will get back to you to confirm the details.",
            "At {$name}{$where}, you can arrange {$offer} for your dog. Every stay starts with a request that we review with you before anything is confirmed.",
            "{$name} is a local dog care business{$where} offering {$offer}. Get in touch to talk about your dog and what they need.",
        ];

        return $lines[$variant < 0 ? 0 : $variant % count($lines)];
    }

    /**
     * Default FAQ: every answer comes from the owner's own answers or describes the request process itself. A question
     * the owner gave no answer for is omitted rather than answered with an invented rule.
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, array{key: string, question: string, answer: string}>
     */
    public static function faqDefault(Business $business, array $answers): array
    {
        $items = [];
        $add = function (string $key, string $question, ?string $answer) use (&$items): void {
            if ($answer !== null && trim($answer) !== '') {
                $items[] = ['key' => $key, 'question' => $question, 'answer' => $answer];
            }
        };

        $add('faq_request', 'How do I request a stay?', 'Send us a stay request with your dog\'s details and the dates you have in mind. We review each request and contact you to confirm. A request is not a confirmed booking until we confirm it with you.');
        $add('faq_vaccines', 'What vaccinations do you require?', self::text($answers, 'vaccination_requirements'));
        $add('faq_age', 'Is there an age requirement?', self::text($answers, 'age_requirements'));
        $add('faq_fixed', 'Do dogs need to be spayed or neutered?', self::text($answers, 'spay_neuter_requirements'));
        $add('faq_assessment', 'Do you assess dogs before a stay?', self::text($answers, 'temperament_assessment'));
        $add('faq_hours', 'What are your drop-off and pickup times?', self::hoursLine($answers) ?? self::text($answers, 'checkin_checkout'));
        $add('faq_feeding', 'Can you feed my dog or give medication?', self::text($answers, 'feeding_medication'));
        $add('faq_visit', 'Can I visit before booking?', ($answers['offers_meet_greet'] ?? null) === true ? 'Yes. Ask for a meet and greet when you contact us and we will arrange a time with you.' : null);
        $add('faq_cancel', 'What is your cancellation policy?', self::text($answers, 'cancellation_policy'));
        $add('faq_breeds', 'Which breeds and sizes do you accept?', self::text($answers, 'accepted_breeds'));

        return $items;
    }

    /** @param list<string> $items */
    private static function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }
}
