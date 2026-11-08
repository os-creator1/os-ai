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

    /**
     * A shorter title that fits once the Business/site name is composed in (the length searchers actually see), cut at a word boundary.
     * Null when even a single word does not fit, so the owner writes it themselves.
     */
    public static function shortened(string $source, string $siteName, int $recommendedMax): ?string
    {
        $max = min(self::EDITOR_MAX, max(20, $recommendedMax));
        $words = preg_split('/\s+/u', trim($source), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($count = count($words); $count >= 1; $count--) {
            $candidate = implode(' ', array_slice($words, 0, $count));

            if (mb_strlen(SeoMetadataRules::effectiveTitle($candidate, $candidate, $siteName)) <= $max) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A variant that is not already used by another page (compared with the audit's own normalisation), or null when none of the
     * honest variants is free — the owner then writes a title that is specific to this page.
     *
     * @param  array<int, string>  $takenNormalised
     */
    public static function distinct(string $pageTitle, string $businessName, bool $isHome, int $recommendedMax, array $takenNormalised): ?string
    {
        foreach ([self::for($pageTitle, $businessName, $isHome, $recommendedMax), trim($pageTitle)] as $candidate) {
            if ($candidate !== null && $candidate !== '' && ! in_array(SeoMetadataRules::normalize($candidate), $takenNormalised, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
