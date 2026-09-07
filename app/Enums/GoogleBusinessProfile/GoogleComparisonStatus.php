<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §22.1 — exactly five statuses and no others. There
 * is deliberately no score, percentage, grade or severity ordering
 * (§22.1, §31).
 */
enum GoogleComparisonStatus: string
{
    case Match = 'match';
    case Mismatch = 'mismatch';
    case NotSetOnPlatform = 'not_set_on_platform';
    case NotSetOnGoogle = 'not_set_on_google';
    case NotComparable = 'not_comparable';

    public function label(): string
    {
        return match ($this) {
            self::Match => 'Match',
            self::Mismatch => 'Mismatch',
            self::NotSetOnPlatform => 'Not set on platform',
            self::NotSetOnGoogle => 'Not set on Google',
            self::NotComparable => 'Not comparable',
        };
    }
}
