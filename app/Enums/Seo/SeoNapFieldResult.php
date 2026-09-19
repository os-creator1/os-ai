<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.5 — the per-field result of the read-time NAP comparison.
 * NEVER persisted, never a column, never a status change.
 *
 *  - Consistent    both values present and equal after normalization.
 *  - Mismatch      both values present and different after normalization.
 *  - NotComparable there is no canonical value to compare against (Business
 *                  phone unset, or the street address is withheld because the
 *                  Location is not permitted to expose one).
 *  - Unchecked     a canonical value exists but the user has not recorded
 *                  what the directory shows.
 */
enum SeoNapFieldResult: string
{
    case Consistent = 'consistent';
    case Mismatch = 'mismatch';
    case NotComparable = 'not_comparable';
    case Unchecked = 'unchecked';

    public function label(): string
    {
        return match ($this) {
            self::Consistent => 'Consistent',
            self::Mismatch => 'Mismatch',
            self::NotComparable => 'Not comparable',
            self::Unchecked => 'Not checked',
        };
    }
}
