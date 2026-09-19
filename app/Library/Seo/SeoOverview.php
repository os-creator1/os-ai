<?php

namespace App\Library\Seo;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Enums\Seo\SeoIndexabilityState;

/**
 * Contract 18 §9.3 — what the SEO Overview shows, as plain read-only facts.
 *
 * Only sections that are BUILT and that this actor may see are populated;
 * an absent section is null (or empty), never a placeholder. Nothing here is
 * a score, a grade or a percentage.
 */
final class SeoOverview
{
    /**
     * @param  array<int, SeoReadinessItem>  $readiness
     * @param  array{pages: int, with_meta_description: int, with_seo_title: int, marked_noindex: int}|null  $content  null when there is no published Website
     * @param  array<int, GoogleLocationStatus>|null  $google  null when the actor may not see GBP; otherwise one per ACCESSIBLE Location
     */
    public function __construct(
        public readonly array $readiness,
        public readonly ?array $content,
        public readonly SeoIndexabilityState $indexability,
        public readonly ?array $google,
    ) {
    }
}
