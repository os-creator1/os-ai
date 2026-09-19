<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §5.2.3 — whether a keyword's phrase appears in the PUBLISHED
 * Website content. Deliberately a coverage fact, never a ranking: it says
 * nothing about position, traffic or indexing.
 */
enum SeoKeywordCoverageStatus: string
{
    case Covered = 'covered';
    case NotCovered = 'not_covered';
    case NoPublishedWebsite = 'no_published_website';

    public function label(): string
    {
        return match ($this) {
            self::Covered => 'Found on your website',
            self::NotCovered => 'Not found on your website yet',
            self::NoPublishedWebsite => 'Publish your website to check',
        };
    }
}
