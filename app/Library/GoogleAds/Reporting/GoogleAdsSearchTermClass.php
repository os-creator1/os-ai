<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Contract §12 — deterministic search-term classification.
 * Excluded / Ignored take precedence: a term Google already excludes (or the
 * owner ignored) is never presented as potential waste.
 */
enum GoogleAdsSearchTermClass: string
{
    case PotentialWaste = 'potential_waste';
    case Converting = 'converting';
    case Unreviewed = 'unreviewed';
    case Excluded = 'excluded';
    case Ignored = 'ignored';
}
