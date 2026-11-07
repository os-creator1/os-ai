<?php

namespace App\Library\Seo;

/**
 * A proposed SEO title for a page whose title is blank. Deterministic and offline: built only from the page's own title and the Business
 * name, with no provider, no AI call and no metering. It is a starting point the owner edits and explicitly approves; it never claims to
 * be optimised for anything.
 */
final class SeoTitleProposal
{
    /** Longest title the Website editor accepts (WebsiteDraftPageService: max:70). */
    private const EDITOR_MAX = 70;

    public static function for(string $pageTitle, string $businessName, bool $isHome, int $recommendedMax): ?string
    {
        $pageTitle = trim($pageTitle);
        $businessName = trim($businessName);
        $max = min(self::EDITOR_MAX, max(20, $recommendedMax));

        $candidate = match (true) {
            $isHome && $businessName !== '' => $businessName,
            $pageTitle === '' => $businessName,
            $businessName === '' => $pageTitle,
            default => $pageTitle . ' | ' . $businessName,
        };

        if ($candidate === '') {
            return null;
        }

        if (mb_strlen($candidate) > $max) {
            $candidate = $pageTitle !== '' && ! $isHome ? $pageTitle : $candidate;
            $candidate = rtrim(mb_substr($candidate, 0, $max));
        }

        return $candidate === '' ? null : $candidate;
    }
}
