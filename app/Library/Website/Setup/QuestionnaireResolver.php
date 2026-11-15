<?php

namespace App\Library\Website\Setup;

use App\Enums\Business\BusinessIndustry;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessVertical;
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

    /**
     * A Business's niche: its Knowledge Profile's vertical when that vertical is an ACTIVE catalog row that has its own
     * published setup questionnaire (e.g. dog_boarding — the BusinessIndustry enum is locked, so a vertical-bound niche
     * cannot be an industry), otherwise its canonical industry exactly as before. A Business with no such vertical
     * resolves to the same niche it always did.
     */
    public function nicheKeyFor(Business $business): ?string
    {
        $vertical = $this->verticalNicheFor($business);

        if ($vertical !== null) {
            return $vertical;
        }

        $industry = $business->industry;

        if ($industry instanceof BusinessIndustry) {
            return $industry->value;
        }

        return is_string($industry) && trim($industry) !== '' ? $industry : null;
    }

    private function verticalNicheFor(Business $business): ?string
    {
        $key = BusinessKnowledgeProfile::where('business_id', $business->id)->value('vertical_key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        if (! BusinessVertical::where('key', $key)->where('is_active', true)->exists()) {
            return null;
        }

        $hasDefinition = QuestionnaireDefinition::where('scope', self::SCOPE)->where('niche_key', $key)->exists();

        return $hasDefinition ? $key : null;
    }
}
