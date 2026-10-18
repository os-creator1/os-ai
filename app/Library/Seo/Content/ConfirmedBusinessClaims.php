<?php

namespace App\Library\Seo\Content;

use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessKnowledgeProfileFieldState;

/**
 * The claim-bearing Business Knowledge Profile facts an article may state — and only once the owner has CONFIRMED them
 * (field state `customer_confirmed`). An unverified onboarding or imported value is never stated as a fact.
 *
 * One authority for two readers: ArticleClaimGuard (so "15 years of experience" is not a hard finding when 15 years is the
 * owner's confirmed fact, for manual and Autopilot articles alike) and ContentFactPack (what a brief may cite).
 */
class ConfirmedBusinessClaims
{
    /** @return array{years: ?int, credentials: string[]} */
    public function forBusiness(Business $business): array
    {
        return $this->load($business);
    }

    public function years(Business $business): ?int
    {
        return $this->forBusiness($business)['years'];
    }

    /** @return string[] */
    public function credentials(Business $business): array
    {
        return $this->forBusiness($business)['credentials'];
    }

    private function load(Business $business): array
    {
        $confirmed = BusinessKnowledgeProfileFieldState::query()
            ->where('business_id', $business->id)
            ->whereIn('field_key', ['years_operating', 'credentials'])
            ->where('verification_status', BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED)
            ->pluck('field_key')
            ->all();

        if ($confirmed === []) {
            return ['years' => null, 'credentials' => []];
        }

        $profile = BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first();

        $years = in_array('years_operating', $confirmed, true) && (int) ($profile?->years_operating ?? 0) > 0
            ? (int) $profile->years_operating
            : null;

        $credentials = [];

        if (in_array('credentials', $confirmed, true)) {
            foreach ((array) ($profile?->credentials ?? []) as $credential) {
                if (is_array($credential) && ($credential['verified'] ?? false) === true && is_string($credential['label'] ?? null) && trim($credential['label']) !== '') {
                    $credentials[] = trim($credential['label']);
                }
            }
        }

        return ['years' => $years, 'credentials' => $credentials];
    }
}
