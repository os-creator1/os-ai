<?php

namespace App\Library\AgencyProspecting;

/**
 * Runtime pass — deterministic, pre-AI opt-out/negative detection (task
 * requirement: this runs BEFORE any AI call, never depends on one).
 * Bounded phrase matching on word/phrase boundaries only — a hard-stop
 * phrase must appear as a standalone token/phrase in the message, never
 * as a substring accident (e.g. "unstoppable" must never match "stop").
 */
final class AgencyProspectStopDetector
{
    private const HARD_STOP_PHRASES = [
        'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REMOVE',
        'OPT OUT', "DON'T CONTACT", 'DO NOT CONTACT',
        'WRONG NUMBER', 'NOT INTERESTED',
    ];

    private const SOFT_NEGATIVE_PHRASES = [
        'NO THANKS', 'NO THANK YOU', 'NOT NOW',
    ];

    public static function isHardStop(string $body): bool
    {
        return self::matchesAnyPhrase($body, self::HARD_STOP_PHRASES);
    }

    public static function isSoftNegative(string $body): bool
    {
        return self::matchesAnyPhrase($body, self::SOFT_NEGATIVE_PHRASES);
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private static function matchesAnyPhrase(string $body, array $phrases): bool
    {
        $normalized = self::normalize($body);

        if ($normalized === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            $pattern = '/\b' . preg_quote(self::normalize($phrase), '/') . '\b/u';

            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uppercases, then collapses every non-letter/digit/apostrophe
     * character (including hyphens, so "OPT-OUT" and "OPT OUT" both
     * normalize identically) to a single space, and trims. Apostrophes
     * are preserved so "DON'T CONTACT" survives intact.
     */
    private static function normalize(string $text): string
    {
        $upper = mb_strtoupper(trim($text));
        $collapsedPunctuation = preg_replace("/[^\p{L}\p{N}']+/u", ' ', $upper) ?? '';

        return trim(preg_replace('/\s+/', ' ', $collapsedPunctuation) ?? '');
    }
}
