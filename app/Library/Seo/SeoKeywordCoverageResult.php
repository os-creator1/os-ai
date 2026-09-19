<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoKeywordCoverageStatus;

/**
 * Contract 18 §5.2.3 — where a keyword's phrase appears in the PUBLISHED
 * Website. Counts are PAGES, never occurrences, and never a score.
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
