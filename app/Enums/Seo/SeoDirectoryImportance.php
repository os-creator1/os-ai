<?php

namespace App\Enums\Seo;

/**
 * Citations V1 — owner-facing guidance on how much a directory matters.
 *
 * Product guidance only: it orders the page and the "what to do next" list.
 * It is NOT an authority score and promises no ranking effect.
 */
enum SeoDirectoryImportance: string
{
    case Essential = 'essential';
    case Recommended = 'recommended';
    case Optional = 'optional';

    public function label(): string
    {
        return match ($this) {
            self::Essential => 'Essential',
            self::Recommended => 'Recommended',
            self::Optional => 'Optional',
        };
    }

    /** x-badge variant. */
    public function variant(): string
    {
        return match ($this) {
            self::Essential => 'accent',
            self::Recommended => 'neutral',
            self::Optional => 'neutral',
        };
    }

    /** Lower sorts first; drives page grouping and next-step priority. */
    public function rank(): int
    {
        return match ($this) {
            self::Essential => 0,
            self::Recommended => 1,
            self::Optional => 2,
        };
    }
}
