<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessIndustry;
use App\Models\Business;
use App\Models\QuestionnaireDefinition;

/**
 * Independent-review correction round — the wizard/Studio controllers
 * previously hardcoded PhotoboothWebsiteSetupQuestionnaireSeeder::KEY
 * regardless of which Business they were serving, so a Business of any
 * OTHER industry would have received the Photobooth questionnaire too.
 * This is the one place a Business's own canonical `industry` is turned
 * into the niche-scoped `QuestionnaireDefinition` it should answer —
 * every caller (start, resume, edit, Studio) goes through this class
 * rather than naming a definition key itself.
 *
 * Photobooth remains the only seeded definition this release; a Business
 * of any other industry resolves to null (no published questionnaire for
 * that niche yet), and callers are required to fail gracefully rather
 * than fall back to a definition that does not belong to this Business's
 * niche.
 */
final class QuestionnaireResolver
{
    public const SCOPE = 'website_niche_setup';

    public function resolveForBusiness(Business $business): ?QuestionnaireDefinition
    {
        $nicheKey = $this->nicheKeyFor($business);

        if ($nicheKey === null) {
            return null;
        }

        $definition = QuestionnaireDefinition::where('scope', self::SCOPE)
            ->where('niche_key', $nicheKey)
            ->first();

        if ($definition === null || $definition->publishedVersion() === null) {
            return null;
        }

        return $definition;
    }

    public function nicheKeyFor(Business $business): ?string
    {
        $industry = $business->industry;

        if ($industry instanceof BusinessIndustry) {
            return $industry->value;
        }

        return is_string($industry) && trim($industry) !== '' ? $industry : null;
    }
}
