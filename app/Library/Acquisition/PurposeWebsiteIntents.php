<?php

namespace App\Library\Acquisition;

use App\Models\AcquisitionPurpose;
use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Acquisition Purpose V1 — what each of a Business's goals needs from its
 * website, read from the goals themselves (their `website_intent`, copied from
 * the niche Blueprint at install). The one seam through which BOTH website modes
 * learn the funnel intents:
 *
 *   - a hosted Website: the intents' content prompts guide generation
 *     (WebsiteBlueprintDefaults), so a tutoring site is nudged toward a student
 *     page and a separate teacher page;
 *   - an external website: the intents become ACQUISITION RECOMMENDATIONS
 *     ("Teacher Recruitment has no dedicated landing page"), kept apart from the
 *     technical audit on purpose — a missing marketing page is an opportunity,
 *     never a fabricated technical defect.
 *
 * Read-only, Business-scoped, no network.
 */
final class PurposeWebsiteIntents
{
    private const MAX_PROMPTS = 6;

    /** @return Collection<int, AcquisitionPurpose> active goals that carry a website intent, in display order */
    public function forBusiness(Business $business): Collection
    {
        return AcquisitionPurpose::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (AcquisitionPurpose $p): bool => is_array($p->website_intent) && ($p->website_intent['pages'] ?? []) !== [])
            ->values();
    }

    /**
     * Goals whose landing destination has not been chosen: an acquisition
     * recommendation, not an error.
     *
     * @return Collection<int, AcquisitionPurpose>
     */
    public function withoutDestination(Business $business): Collection
    {
        return $this->forBusiness($business)->reject(fn (AcquisitionPurpose $p): bool => $p->hasDestination())->values();
    }

    /**
     * Guidance lines for hosted-site generation, one funnel at a time so the two
     * audiences are never blended.
     *
     * @return list<string>
     */
    public function generationPrompts(Business $business): array
    {
        $prompts = [];

        foreach ($this->forBusiness($business) as $purpose) {
            $intent = $purpose->website_intent;
            $pageTitles = array_map(fn (array $page): string => (string) ($page['title'] ?? ''), (array) ($intent['pages'] ?? []));
            $prompts[] = mb_substr('Plan a dedicated page for "'.implode('", "', array_filter($pageTitles)).'" aimed at: '.($intent['audience'] ?? 'its audience').'. Its single call to action is: '.($intent['cta'] ?? 'the inquiry form').'. Keep it separate from any other audience.', 0, 500);

            foreach ((array) ($intent['content_prompts'] ?? []) as $prompt) {
                if (is_string($prompt) && trim($prompt) !== '') {
                    $prompts[] = mb_substr(trim($prompt), 0, 500);
                }
            }
        }

        return array_slice($prompts, 0, self::MAX_PROMPTS);
    }
}
