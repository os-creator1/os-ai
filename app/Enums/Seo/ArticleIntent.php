<?php

namespace App\Enums\Seo;

/**
 * SEO Content Engine V1 — the primary search intent an article serves. Every value here is an
 * INFORMATIONAL or comparison intent: the high-intent "hire/book this service" intent belongs to the
 * Website's money pages, and an article never targets it (see ArticleCannibalizationGuard).
 */
enum ArticleIntent: string
{
    case Cost = 'cost';
    case HowTo = 'how_to';
    case Comparison = 'comparison';
    case Ideas = 'ideas';
    case Planning = 'planning';
    case Guide = 'guide';

    public function label(): string
    {
        return match ($this) {
            self::Cost => 'Cost & pricing',
            self::HowTo => 'How-to',
            self::Comparison => 'Comparison',
            self::Ideas => 'Ideas & inspiration',
            self::Planning => 'Planning & logistics',
            self::Guide => 'Guide',
        };
    }
}
