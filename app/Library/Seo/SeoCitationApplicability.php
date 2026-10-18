<?php

declare(strict_types=1);

namespace App\Library\Seo;

use App\Enums\Seo\SeoDirectoryImportance;
use App\Models\BusinessLocation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;

/**
 * The ONE rule for which citation directories are OFFERED (new, actionable) at a
 * Location. The Citations page and the Growth Center both read it, so a directory
 * the owner is never asked to list at a Location can never become a Growth finding.
 *
 * Pure: no query, no state.
 */
final class SeoCitationApplicability
{
    /**
     * Country applicability, decided per LOCATION (a Business may have Locations in different
     * countries): a platform directory with a NULL country_scope applies everywhere; one with an
     * ISO-2 scope applies only where it equals the Location's country_code (case-insensitive). A
     * Location with no country code cannot prove a match, so a scoped directory does not apply
     * (fail closed). Business custom directories are never filtered by country.
     */
    public static function appliesToCountry(SeoCitationDirectory $directory, BusinessLocation $location): bool
    {
        if ($directory->isCustom() || $directory->country_scope === null || trim((string) $directory->country_scope) === '') {
            return true;
        }

        return strtoupper(trim((string) $directory->country_scope)) === strtoupper(trim((string) $location->country_code));
    }

    /**
     * A directory's EFFECTIVE importance: the niche's override, else the directory's own
     * default, else Recommended — the rule the page row (SeoCitationRow::importance()) applies.
     */
    public static function importanceFor(SeoCitationDirectory $directory, ?SeoNicheCitationRecommendation $recommendation): SeoDirectoryImportance
    {
        return $recommendation?->importance ?? $directory->importance ?? SeoDirectoryImportance::Recommended;
    }

    /**
     * The ONE ordering of offered directories: effective importance, then the niche's own order
     * (else the directory's), then the catalog order. The Citations page sorts by it and the
     * Growth Center picks its priority directories in it, so the directories Growth asks about
     * are the ones the page lists first.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function orderKey(SeoCitationDirectory $directory, ?SeoNicheCitationRecommendation $recommendation): array
    {
        return [
            self::importanceFor($directory, $recommendation)->rank(),
            (int) ($recommendation?->sort_order ?? $directory->sort_order),
            (int) $directory->sort_order,
            (int) $directory->id,
        ];
    }

    /**
     * OFFERED = new, actionable. A platform directory must be active, core or recommended by the
     * niche, AND apply to THIS Location's country (a recommendation never overrides that). A custom
     * directory is governed only by its own Business/Location scope.
     */
    public static function isOffered(SeoCitationDirectory $directory, ?SeoNicheCitationRecommendation $recommendation, BusinessLocation $location): bool
    {
        return (bool) $directory->is_active
            && ($directory->isCustom()
                || (($directory->is_platform_core || $recommendation !== null) && self::appliesToCountry($directory, $location)));
    }
}
