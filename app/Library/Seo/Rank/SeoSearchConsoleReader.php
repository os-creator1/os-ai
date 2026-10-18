<?php

namespace App\Library\Seo\Rank;

use App\Models\Business;
use App\Models\SeoKeyword;

/**
 * Seam for Google Search Console query metrics, shown as a SEPARATE, clearly
 * labelled source beside rank tracking — never blended into provider rank. No
 * Search Console integration exists in this codebase yet, so the bound
 * implementation returns null and the UI shows no Search Console block. A future
 * implementation returns real clicks / impressions / average position / as-of
 * date; its average position is a different fact from a provider rank and is
 * never averaged with, substituted for, or stored as one.
 */
interface SeoSearchConsoleReader
{
    /**
     * @return array{clicks: int, impressions: int, average_position: float|null, as_of: string}|null
     */
    public function forKeyword(Business $business, SeoKeyword $keyword): ?array;
}
