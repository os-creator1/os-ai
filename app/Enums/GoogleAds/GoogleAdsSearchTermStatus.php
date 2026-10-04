<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §2 — SearchTermView.status: whether the term is already added as
 * a keyword and/or excluded as a negative. `None` also stands for any value
 * Google adds that we do not recognise.
 */
enum GoogleAdsSearchTermStatus: string
{
    case Added = 'ADDED';
    case Excluded = 'EXCLUDED';
    case AddedExcluded = 'ADDED_EXCLUDED';
    case None = 'NONE';

    public static function fromProvider(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtoupper($value)) ?? self::None) : self::None;
    }
}
