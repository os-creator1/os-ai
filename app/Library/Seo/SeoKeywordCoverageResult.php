<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoKeywordCoverageStatus;

/**
 * Contract 18 §5.2.3 — where a keyword's phrase appears in the PUBLISHED
 * Website. Counts are PAGES, never occurrences, and never a score. `pagesTotal`
 * is every published page; the title / description / body counts only include
 * pages search engines may list (a page hidden from search never counts).
 */
final class SeoKeywordCoverageResult
{
    public function __construct(
        public readonly SeoKeywordCoverageStatus $status,
        public readonly int $pagesTotal,
        public readonly int $titlePages,
        public readonly int $descriptionPages,
        public readonly int $bodyPages,
    ) {
    }
}
