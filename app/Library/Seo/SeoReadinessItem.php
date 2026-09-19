<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoReadinessState;

/**
 * Contract 18 §5.2 — one line of the Core SEO readiness checklist.
 *
 * A plain fact and, where it can be fixed on an existing screen, the name of
 * that screen (`fix`). Never a score, grade or percentage. `label` and
 * `detail` are fixed registry copy; nothing customer-supplied is echoed.
 */
final class SeoReadinessItem
{
    public const FIX_BUSINESS_SETTINGS = 'business_settings';
    public const FIX_WEBSITE = 'website';
    public const FIX_SEO_KEYWORDS = 'seo_keywords';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly SeoReadinessState $state,
        public readonly ?string $detail,
        public readonly ?string $fix,
    ) {
    }
}
