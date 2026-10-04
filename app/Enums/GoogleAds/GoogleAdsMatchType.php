<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §2 — keyword match type. There is no Unknown case: a keyword with
 * a match type we cannot name is skipped by the mapper, never guessed.
 */
enum GoogleAdsMatchType: string
{
    case Exact = 'EXACT';
    case Phrase = 'PHRASE';
    case Broad = 'BROAD';

    public static function fromProvider(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtoupper($value)) : null;
    }
}
