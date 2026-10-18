<?php

namespace App\Library\Seo\Rank;

use App\Models\Business;
use App\Models\SeoKeyword;

/** Search Console is not connected/built: no metrics, and rank tracking is unaffected. */
final class NullSeoSearchConsoleReader implements SeoSearchConsoleReader
{
    public function forKeyword(Business $business, SeoKeyword $keyword): ?array
    {
        return null;
    }
}
