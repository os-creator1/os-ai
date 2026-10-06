<?php

namespace App\Library\Website\Setup;

/**
 * Website V1 final — niche-first defaults for the FAQ step. A newcomer rarely
 * knows what to write, so each niche offers the QUESTIONS its customers
 * usually ask as one-tap suggestions. Only questions are suggested: the
 * answers are the owner's own (an FAQ page states the business's real
 * policies, so nothing here ever writes an answer or invents a claim).
 */
final class NicheFaqSuggestions
{
    private const BY_NICHE = [
        'photo_booth_service' => [
            'How far in advance should I book?',
            'How long does setup take?',
            'Is an attendant included?',
            'How much space does the booth need?',
            'Can the backdrop and prints be customized?',
            'How many hours should I book?',
            'Do you travel outside your usual service area?',
            'How do deposits and payments work?',
            'What happens if I need to reschedule?',
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function for(?string $nicheKey): array
    {
        return self::BY_NICHE[(string) $nicheKey] ?? [];
    }
}
