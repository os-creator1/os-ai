<?php

namespace App\Enums\Seo;

/**
 * SEO Content Engine V1 — an article's lifecycle. Published history is never destroyed:
 * the only way out of Published is Archived, and Archived can be restored to Draft.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Draft, self::Scheduled, self::Published, self::Archived],
            self::Scheduled => [self::Draft, self::Scheduled, self::Published, self::Archived],
            self::Published => [self::Published, self::Archived],
            self::Archived => [self::Archived, self::Draft],
        };
    }
}
