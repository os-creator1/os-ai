<?php

namespace App\Library\Seo;

/**
 * Contract 18 §8.6 — the ONE rule for "which review link does a Location
 * have": the manual link the Business pasted, else the Google review link the
 * fresh GBP read model reports, else none.
 *
 * Both the Reviews page (SeoReviewsPageReader) and the Growth Center
 * (GrowthReputationFactReader) read it, so a Location the page shows as
 * "Review link ready" can never be a Growth "no review link" finding and vice
 * versa. A stored value only counts as a link when it passes SeoLinkSafety
 * (https, no userinfo, valid host) — exactly what the page would render.
 *
 * Pure: no query, no state, no provider call.
 */
final class SeoReviewLinkResolver
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_GOOGLE = 'google';

    /**
     * @return array{manual: ?string, effective: ?string, source: ?string} `manual` is the safe manual link (the
     *         one the editor shows); `effective` is the link the Location can actually hand out.
     */
    public static function resolve(?string $manualUrl, ?string $googleUrl): array
    {
        $manual = SeoLinkSafety::safeHttpsUrl($manualUrl);
        $google = SeoLinkSafety::safeHttpsUrl($googleUrl);

        return [
            'manual' => $manual,
            'effective' => $manual ?? $google,
            'source' => $manual !== null ? self::SOURCE_MANUAL : ($google !== null ? self::SOURCE_GOOGLE : null),
        ];
    }
}
