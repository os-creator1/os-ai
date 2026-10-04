<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessKnowledgeProfileFieldState;

/**
 * Website Guided Generation contract §8.3, completed by this lane. A
 * thin, guided-generation-specific PROMPT BUILDER only — it never makes
 * its own provider call. The actual completion, budget check,
 * entitlement, and fail-closed behavior are 100% owned by the
 * already-existing, already-tested App\Library\Website\
 * WebsiteAiGenerationClient (same AiUsageCategory::WebsiteGeneration
 * budget pool a Website already draws from for its Slice-A AI draft —
 * this is deliberately not a second, competing AI seam). Mirrors that
 * class's own fail-closed contract: any failure, refusal, or malformed
 * JSON returns null, never throws.
 *
 * Acceptance-correction Blocker 2/3: the AI is sent the DETERMINISTIC
 * generation plan (WebsitePageStrategy::buildPlan()) — the exact real
 * page instances it must write for, each carrying its own real
 * entity facts (service name/description/price, location city/service
 * area) straight from the owning domain's canonical tables — never a
 * generic page-type manifest it could reinterpret, and never facts
 * duplicated onto BusinessKnowledgeProfile merely to make them visible
 * here. Confirmed Knowledge-Profile-owned facts (differentiators,
 * credentials, testimonials, etc.) are still sent, but only a
 * `customer_confirmed` fact within its own freshness window is ever
 * included — an unverified or stale sensitive fact is never sent, even
 * labeled as unverified (contract §5.2).
 */
class GuidedWebsiteGenerationClient
{
    /**
     * Independent-review correction round 3 (item 3) — stated explicitly
     * here rather than left to whatever `website_generation` route's own
     * configured max happens to be, so a future change to that shared
     * route's ceiling can never silently change what THIS call site asks
     * for without a reviewed change to this constant too. Currently
     * equal to the route's own cap (config('ai.routes.website_generation
     * .max_output_tokens')) — the one call site that genuinely needs the
     * route's full envelope.
     */
    public const MAX_OUTPUT_TOKENS = 8_000;

    private const SENSITIVE_FIELDS = [
        BusinessKnowledgeProfileFieldKey::Credentials,
        BusinessKnowledgeProfileFieldKey::YearsOperating,
        BusinessKnowledgeProfileFieldKey::WarrantiesGuarantees,
        BusinessKnowledgeProfileFieldKey::Testimonials,
        BusinessKnowledgeProfileFieldKey::PricingMethod,
        BusinessKnowledgeProfileFieldKey::FinancingAvailable,
        BusinessKnowledgeProfileFieldKey::Offers,
    ];

    public function __construct(
        private readonly WebsiteAiGenerationClient $client,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    /**
     * @param  array  $plan  WebsitePageStrategy::buildPlan()'s output, already passed through WebsitePageStrategy::withoutAiUnfillableSections()
     * @param  ?string  $idempotencyKey  a stable, durable identity for this attempt (GuidedGenerationCommitService's own material-hash idempotency key) — passed straight through onto the AiRequest/ledger entry instead of a meaningless-for-dedup fresh uuid
     * @return ?array<int, array{page_key: string, title: string, seo_title: ?string, meta_description: ?string, sections: array}> null on any refusal/failure/malformed output
     */
    public function generate(Business $business, array $plan, ?int $actorUserId = null, ?string $idempotencyKey = null): ?array
    {
        $messages = $this->buildMessages($business, $plan);

        $raw = $this->client->complete($messages, $business, $actorUserId, self::MAX_OUTPUT_TOKENS, $idempotencyKey);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded['pages'] ?? null) ? $decoded['pages'] : null;
    }

    public function lastCallWasBudgetExhausted(): bool
    {
        return $this->client->lastCallWasBudgetExhausted();
    }

    /**
     * Was the last call refused because AI is switched off for this
     * environment (a different fact from a bad response or an exhausted
     * allowance — and one the owner can do nothing about by editing their
     * answers, so the screen says so plainly instead of "invalid batch").
     */
    public function lastCallWasUnavailable(): bool
    {
        return $this->client->lastRefusalReason() === \App\Library\Ai\Enums\AiRefusalReason::AiDisabled;
    }

    /**
     * @param  array  $plan  WebsitePageStrategy::buildPlan()'s output
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Business $business, array $plan): array
    {
        // §7.4/§8.3 — the AI never sees a page's image_slots (it never
        // authors an image reference) and never sees the template's
        // presentation theme (visual choice is never AI's to make).
        // Only page_key/page_type/allowed_section_types/entity survive
        // into the prompt.
        $planForPrompt = array_map(fn ($page) => [
            'page_key' => $page['page_key'],
            'page_type' => $page['page_type'],
            'is_home' => $page['is_home'],
            'allowed_section_types' => $page['allowed_section_types'],
            'entity' => $page['entity'],
        ], $plan);

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

        $instructions = [
            'You are writing factual, bounded website copy for a real local business.',
            'You may only use the confirmed facts provided below — never invent a fact, statistic, award, review, price, or claim.',
            'The "plan" array below is the COMPLETE, FINAL list of pages this website will have. You must write content for EXACTLY these page_key values — one output page per plan entry, never fewer, never more, never a page_key that is not in the plan.',
            'Each page you output must use only the section types listed in its own plan entry\'s allowed_section_types.',
            'You may use an image_text section to pair written content with a photo, but never choose or name the photo itself: leave its "image" field as null (or omit it) and the system will attach a real photo automatically. Never set background_image on a hero, and never set "image" on anything else, to any value at all.',
            'Never write any of these prohibited phrases: ' . implode('; ', $profile?->prohibited_claims ?? []),
            'For each page, draft a title, seo_title (max 70 characters, descriptive and distinct, never boilerplate or keyword-stuffed) and meta_description (max 160 characters, a genuine one-sentence summary of that specific page) — never copy the same title, seo_title, or meta_description across two pages.',
            'Respond with a single JSON object: {"pages": [{"page_key": string, "title": string, "seo_title": string|null, "meta_description": string|null, "sections": [...]}]}.',
        ];

        // A short, plain About page reads better and converts better than a long story.
        if (collect($plan)->contains(fn ($page) => ($page['page_type'] ?? null) === 'about')) {
            array_splice($instructions, -1, 0, ['Keep the About page concise: at most two short paragraphs (about 120 words in total) in the owner\'s own voice, never padded.']);
        }

        // Only when the plan actually contains service-area pages (kept out
        // of every other request so the prompt envelope is unchanged).
        if (collect($plan)->contains(fn ($page) => is_array($page['entity'] ?? null) && isset($page['entity']['area']))) {
            array_splice($instructions, -1, 0, [
                'A plan entry whose entity has an "area" is a service-area page. Write it only for people in THAT area: name the area in the heading and body, and make every area page read genuinely differently from the others (different structure and wording, never the same copy with the area name swapped).',
                'On a service-area page use only the given facts (the "area", its "nearby_areas", the "services", "business_home_city"). Never invent landmarks, neighborhoods, distances, travel times, local statistics, customers or reviews.',
            ]);
        }

        return [
            ['role' => 'system', 'content' => implode("\n", $instructions)],
            ['role' => 'user', 'content' => json_encode([
                'business_name' => $business->name,
                'plan' => $planForPrompt,
                'confirmed_facts' => $this->canonicalFacts($business),
            ])],
        ];
    }

    /**
     * Business-wide facts (never a specific page's own entity data,
     * which travels on that plan entry instead) drawn from each
     * domain's own canonical authority — never duplicated onto
     * BusinessKnowledgeProfile merely to make them visible to AI
     * (acceptance-correction Blocker 3). Public: also used by
     * GuidedGenerationCommitService to derive its deterministic
     * idempotency key from the exact same material facts a generation
     * attempt is actually built from (Blocker 6).
     */
    public function canonicalFacts(Business $business): array
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

        $facts = array_filter([
            'name' => $business->name,
            'phone' => $business->phone,
            'email' => $business->email,
            'description' => trim((string) $business->description) !== '' ? trim($business->description) : null,
        ], fn ($value) => $value !== null && $value !== '');

        $primaryLocation = $business->primaryLocation()->first();
        if ($primaryLocation !== null) {
            if ((bool) $primaryLocation->public_address && trim((string) $primaryLocation->address_line_1) !== '') {
                $facts['address'] = array_filter([
                    'address_line_1' => $primaryLocation->address_line_1,
                    'city' => $primaryLocation->city,
                    'region' => $primaryLocation->region,
                ]);
            }

            // Acceptance-correction Blocker 3 (IMPORTANT HOURS BUG): hours
            // are a business_locations fact, never a BusinessKnowledgeProfile
            // column — reading `$profile->hours` here (as the old
            // implementation did) always returns null even when hours are
            // genuinely confirmed. Read the real, confirmed fact straight
            // from the primary BusinessLocation instead, exactly the same
            // confirmation status BusinessKnowledgeProfileManager itself
            // requires before treating any location's hours as real.
            if ($primaryLocation->hours_verification_status === BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED
                && $primaryLocation->hours_verified_at !== null
                && ! empty($primaryLocation->hours)) {
                $facts['hours'] = $primaryLocation->hours;
            }
        }

        if ($profile !== null) {
            $confirmed = $this->profiles->completenessCheck($business)->presentFieldKeys;

            foreach (BusinessKnowledgeProfileFieldKey::cases() as $key) {
                if ($key === BusinessKnowledgeProfileFieldKey::Hours) {
                    // Handled above from the canonical BusinessLocation
                    // record — this enum case has no matching Profile
                    // column at all.
                    continue;
                }

                if (! in_array($key->value, $confirmed, true)) {
                    continue;
                }

                // Sensitive fields are already gated by $confirmed above
                // (only customer_confirmed, fresh facts ever appear
                // there) — this second check just documents which keys
                // are treated as sensitive for readers of this class.
                if (in_array($key, self::SENSITIVE_FIELDS, true) && ! in_array($key->value, $confirmed, true)) {
                    continue;
                }

                $value = $profile->{$key->value} ?? null;
                if ($value !== null && $value !== '' && $value !== []) {
                    $facts[$key->value] = $value;
                }
            }
        }

        return $facts;
    }
}
