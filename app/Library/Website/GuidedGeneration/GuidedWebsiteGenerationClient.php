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

    /** At most this many already-used SEO titles are listed in a later batch's prompt. */
    public const MAX_USED_TITLES = 30;

    /** A used title is listed only if its JSON form is at most this long (a 70-character title plus quotes and a few escapes), so the preflight worst case is exact. */
    private const MAX_USED_TITLE_JSON = 96;

    /** @var ?array<string, mixed> */
    private ?array $lastDiagnostic = null;

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
     * @param  ?array  $sitePlan  the COMPLETE site plan when $plan is only one batch of it (bounded multi-batch generation): its
     *         page paths are the valid internal link targets, so a page may link to one written in another batch. Null = $plan is the whole site.
     * @param  ?array  $contentFacts  SeoContentFacts::build() — the bounded primary market/service/services/areas the pages must reflect; built here when null
     * @param  array<int, string>  $usedSeoTitles  SEO titles already written for earlier batches, so this batch avoids duplicating them
     * @param  array<int, string>  $rejections  rejectionNotes() of the previous try of this same batch (the corrective retry); empty on a first request
     * @return ?array<int, array{page_key: string, title: string, seo_title: ?string, meta_description: ?string, sections: array}> null on any refusal/failure/malformed output
     */
    public function generate(Business $business, array $plan, ?int $actorUserId = null, ?string $idempotencyKey = null, ?array $sitePlan = null, array $usedSeoTitles = [], ?array $contentFacts = null, array $rejections = []): ?array
    {
        $this->lastDiagnostic = null;
        $messages = $this->buildMessages($business, $plan, $sitePlan, self::promptSafeTitles($usedSeoTitles), null, $contentFacts, $rejections);

        $raw = $this->client->complete($messages, $business, $actorUserId, self::MAX_OUTPUT_TOKENS, $idempotencyKey);
        if ($raw === null) {
            $this->lastDiagnostic = [
                'stage' => GenerationBatchDiagnostics::STAGE_PROVIDER_NO_CONTENT,
                'refusal' => $this->client->lastRefusalReason()?->value,
            ];

            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // A response cut off by the output-token ceiling is the typical cause: valid-looking JSON that stops mid-page.
            $this->lastDiagnostic = [
                'stage' => GenerationBatchDiagnostics::STAGE_RESPONSE_NOT_JSON,
                'json_error' => json_last_error_msg(),
                'response_chars' => strlen($raw),
                'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            ];

            return null;
        }

        if (! is_array($decoded['pages'] ?? null)) {
            $this->lastDiagnostic = [
                'stage' => GenerationBatchDiagnostics::STAGE_RESPONSE_MISSING_PAGES,
                'response_chars' => strlen($raw),
            ];

            return null;
        }

        return $decoded['pages'];
    }

    /**
     * Titles that may be listed in a later batch's prompt: strings whose JSON form is bounded, at most MAX_USED_TITLES of them. A
     * title left out here is still caught by the cross-batch and final validation.
     *
     * @param  array<int, mixed>  $titles
     * @return array<int, string>
     */
    public static function promptSafeTitles(array $titles): array
    {
        $safe = array_values(array_filter($titles, fn ($title) => is_string($title) && strlen((string) json_encode($title)) <= self::MAX_USED_TITLE_JSON));

        return array_slice($safe, 0, self::MAX_USED_TITLES);
    }

    /**
     * The estimated input tokens of the request generate() would send for $batchPlan as one batch of $sitePlan, built by the very
     * same prompt code and measured by the AI gateway's own estimator. $priorPages is how many pages were written before this batch;
     * their SEO titles are assumed to be the longest a prompt may carry, so the result is an upper bound for any real titles.
     *
     * @param  ?array<string, mixed>  $facts  canonicalFacts() when the caller already holds them (avoids repeated queries)
     */
    public function estimateInputTokens(Business $business, array $batchPlan, array $sitePlan, int $priorPages = 0, ?array $facts = null, ?array $contentFacts = null): int
    {
        $titles = $priorPages > 0 ? array_fill(0, min($priorPages, self::MAX_USED_TITLES), str_repeat('x', self::MAX_USED_TITLE_JSON - 2)) : [];

        return app(\App\Library\Ai\AiModelRouter::class)->estimateInputTokens($this->buildMessages($business, $batchPlan, $sitePlan, $titles, $facts, $contentFacts));
    }

    /**
     * Why the last generate() call returned null, as sanitized scalars only (never response text): a fixed `stage`
     * plus counts / a fixed json error string / a refusal enum value. Null after a call that produced a pages array.
     *
     * @return ?array<string, mixed>
     */
    public function lastGenerationDiagnostic(): ?array
    {
        return $this->lastDiagnostic;
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
     * @param  array  $plan  WebsitePageStrategy::buildPlan()'s output (one batch of it in multi-batch generation)
     * @return array<int, array{role: string, content: string}>
     */
    /**
     * The bounded confirmed SEO facts for a Business (primary market, primary service, services, event types, areas, differentiators).
     *
     * @param  ?array<string, mixed>  $canonicalFacts
     * @return array<string, mixed>
     */
    public function contentFacts(Business $business, ?array $canonicalFacts = null): array
    {
        return SeoContentFacts::build($business, app(\App\Library\Website\WebsitePageStrategy::class), $canonicalFacts ?? $this->canonicalFacts($business));
    }

    private function buildMessages(Business $business, array $plan, ?array $sitePlan = null, array $usedSeoTitles = [], ?array $facts = null, ?array $contentFacts = null, array $rejections = []): array
    {

        // Link targets always come from the COMPLETE site plan; with no site plan the request is the whole site (unchanged prompt).
        $isBatch = $sitePlan !== null && count($sitePlan) > count($plan);
        $linkPlan = $sitePlan ?? $plan;

        // §7.4/§8.3 — the AI never sees a page's image_slots (it never
        // authors an image reference) and never sees the template's
        // presentation theme (visual choice is never AI's to make).
        // Only page_key/page_type/allowed_section_types/entity survive
        // into the prompt.
        $planForPrompt = array_map(fn ($page) => [
            'page_key' => $page['page_key'],
            'page_type' => $page['page_type'],
            'is_home' => $page['is_home'],
            // The page's real address: the only value an internal link to this page may use.
            'path' => $page['is_home'] ? '/' : '/' . ltrim((string) $page['slug'], '/'),
            'allowed_section_types' => $page['allowed_section_types'],
            'entity' => $page['entity'],
        ], $plan);

        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();

        $instructions = [
            'You are writing factual, bounded website copy for a real local business.',
            'You may only use the confirmed facts provided below — never invent a fact, statistic, award, review, price, or claim.',
            $isBatch
                ? 'The "plan" array below is ONE BATCH of a larger website whose other pages are written in separate requests. You must write content for EXACTLY these page_key values — one output page per plan entry, never fewer, never more, never a page_key that is not in this batch. "site_pages" lists every page of the whole website (for link targets only): never write content for a page that is not in the plan.'
                : 'The "plan" array below is the COMPLETE, FINAL list of pages this website will have. You must write content for EXACTLY these page_key values — one output page per plan entry, never fewer, never more, never a page_key that is not in the plan.',
            'Each page you output must use only the section types listed in its own plan entry\'s allowed_section_types.',
            'You may use an image_text section to pair written content with a photo, but never choose or name the photo itself: leave its "image" field as null (or omit it) and the system will attach a real photo automatically. Never set background_image on a hero, and never set "image" on anything else, to any value at all.',
            'Never write any of these prohibited phrases: ' . implode('; ', $profile?->prohibited_claims ?? []),
            'For each page, draft a title, seo_title (max 70 characters, descriptive and distinct, never boilerplate or keyword-stuffed) and meta_description (max 160 characters, a genuine one-sentence summary of that specific page) — never copy the same title, seo_title, or meta_description across two pages.',
            'Internal links (a button url, or a hero primary_cta/secondary_cta url) must be EXACTLY one of these page paths, copied character for character: ' . implode(' ', \App\Library\Website\GuidedGeneration\InternalLinkResolver::paths($linkPlan)) . ' (the home page is "/"). Never invent or guess a path such as /contact, /booking, /services or /about unless it is in that list; each page\'s own path is also given as "path" in the plan. Use an https:// URL only for an external link.',
            'Respond with a single JSON object: {"pages": [{"page_key": string, "title": string, "seo_title": string|null, "meta_description": string|null, "sections": [...]}]}.',
        ];

        // The exact section wire format and per-type data schema WebsiteSectionValidator enforces.
        // Without it the model returns flat or free-form sections and every page fails validation.
        array_splice($instructions, -1, 0, self::sectionFormatInstructions($plan));

        // A short, plain About page reads better and converts better than a long story.
        if (collect($plan)->contains(fn ($page) => ($page['page_type'] ?? null) === 'about')) {
            array_splice($instructions, -1, 0, ['Keep the About page concise: at most two short paragraphs (about 120 words in total) in the owner\'s own voice, never padded.']);
        }

        // Only when the plan contains event-type pages (kept out of every other request so the prompt envelope is unchanged).
        if (collect($plan)->contains(fn ($page) => ($page['page_type'] ?? null) === 'event_detail')) {
            array_splice($instructions, -1, 0, [
                'A plan entry of page_type "event_detail" is a page for one kind of event (its entity name and description). Write it from that description and the services listed only: what the owner offers for that kind of event. Never invent venues, past events, clients, reviews, prices, guarantees or how many events were done, and never write the same copy as another event page.',
            ]);
        }

        // Only when the plan actually contains service-area pages (kept out
        // of every other request so the prompt envelope is unchanged).
        if (collect($plan)->contains(fn ($page) => is_array($page['entity'] ?? null) && isset($page['entity']['area']))) {
            array_splice($instructions, -1, 0, [
                'A plan entry whose entity has an "area" is a service-area page. Write it only for people in THAT area: name the area in the heading and body, and make every area page read genuinely differently from the others (different structure and wording, never the same copy with the area name swapped).',
                'On a service-area page use only the given facts (the "area", its "local_facts" (confirmed venues and travel/setup details), its "nearby_areas", the "services", "business_home_city"). Never invent landmarks, neighborhoods, distances, travel times, local statistics, customers or reviews.',
            ]);
        }

        // Niche Blueprint defaults (Website seam): present only for a Business that has them, so the
        // prompt is unchanged for everyone else. They are suggestions and never facts.
        $nicheDefaults = app(\App\Library\Website\WebsiteBlueprintDefaults::class)->generationHints($business, $plan);

        if ($nicheDefaults !== null) {
            array_splice($instructions, -1, 0, [
                'A "niche_defaults" object may be provided: suggestions about emphasis and which sections suit this kind of business. They never override confirmed_facts and never add facts; use suggested_sections only where that page\'s allowed_section_types permit it.',
            ]);
        }

        $facts = $facts ?? $this->canonicalFacts($business);
        $siteContext = SeoContentFacts::promptContext($contentFacts ?? $this->contentFacts($business, $facts));
        $pageTypes = collect($plan)->pluck('page_type')->all();

        // Content-quality guidance, added only for the page types this request contains (keeps every other request small).
        $quality = ['Every heading must say something specific and useful; never use generic headings such as "Our Amazing Services", "An Unforgettable Experience", "Discover the Magic" or "Capture Every Moment", and never repeat a heading on one page.'];

        if (in_array('home', $pageTypes, true)) {
            $quality[] = 'HOME PAGE: the hero heading (the page H1) must say, in natural language, what the business does and where: the primary_service and primary_market from "site_context" (for example "<primary service> Rental in <city>, <region>"). Put any brand tagline in the hero subheading, never as the H1. After the hero include informative sections: what the business offers and what customers actually receive; a "services" section naming the real services from site_context (never an invented one); real differentiators from confirmed_facts only (never invent any); where it works (areas_served from site_context); and a button to the contact/booking page path. Use a "testimonials" section only when real testimonials are in confirmed_facts.';
        }

        if (in_array('service_detail', $pageTypes, true) || in_array('event_detail', $pageTypes, true)) {
            $quality[] = 'SERVICE/EVENT PAGES: the hero heading must name that exact service or event type (its entity "name") with the natural search words someone would use, and the page should connect it to the primary_market from site_context. Include at least two informative sections: what it is and who or which events it suits, and what is included, taken from that page\'s entity description and confirmed_facts only (never invent features, prices, guarantees, awards or past customers). Finish with one button to the contact/booking page path. Write each page so it clearly differs from the others: different structure, different details.';
        }

        if (collect($plan)->contains(fn ($page) => is_array($page['entity'] ?? null) && isset($page['entity']['area']))) {
            $quality[] = 'AREA PAGES: use the confirmed "local_facts" of that area: name the venues given and state the travel/setup information given, and nothing else about the place. End with one button to the contact/booking page path.';
        }

        array_splice($instructions, -1, 0, $quality);

        $userPayload = [
            'business_name' => $business->name,

            'plan' => $planForPrompt,
            'confirmed_facts' => $facts,
        ];

        if ($siteContext !== []) {
            $userPayload['site_context'] = $siteContext;
        }

        if ($nicheDefaults !== null) {
            $userPayload['niche_defaults'] = $nicheDefaults;
        }

        if ($isBatch) {
            // Whole-site context for link targets and distinct metadata. Paths, types and titles only — bounded, never entity facts.
            $userPayload['site_pages'] = array_map(fn ($page) => [
                'page_key' => $page['page_key'],
                'page_type' => $page['page_type'],
                'path' => $page['is_home'] ? '/' : '/' . ltrim((string) $page['slug'], '/'),
                'title' => $page['title'],
            ], array_values($linkPlan));

            if ($usedSeoTitles !== []) {
                $userPayload['seo_titles_already_used'] = array_slice(array_values($usedSeoTitles), 0, self::MAX_USED_TITLES);
                $instructions[count($instructions) - 1] = 'Never reuse a seo_title listed in "seo_titles_already_used".' . "\n" . $instructions[count($instructions) - 1];
            }
        }

        // The corrective retry: the previous answer for THIS batch was rejected, and the model is told exactly why (bounded, sanitized
        // reasons from our own validator) instead of being asked the identical question again.
        if ($rejections !== []) {
            $instructions[count($instructions) - 1] = 'YOUR PREVIOUS ANSWER FOR THIS REQUEST WAS REJECTED. Fix every point below and answer again for ALL pages of the plan:' . "\n- " . implode("\n- ", $rejections) . "\n" . $instructions[count($instructions) - 1];
        }

        return [
            ['role' => 'system', 'content' => implode("\n", $instructions)],
            ['role' => 'user', 'content' => json_encode($userPayload)],
        ];
    }

    /** Most reasons / characters a retry prompt may carry: a retry must stay inside the batch's input-token headroom. */
    public const MAX_REJECTION_NOTES = 6;

    public const MAX_REJECTION_CHARS = 200;

    /**
     * The reasons the validator rejected a batch, as short plain lines for the corrective retry's prompt. Only our own validator's
     * messages, stripped to printable ASCII and bounded (the model's output is never echoed back wholesale), plus an explicit
     * "return exactly these pages" line whenever pages were missing.
     *
     * @param  array<string, array<int, string>>  $errors  ValidationException::errors()
     * @param  array<int, string>  $batchKeys  the page_key values this batch must contain
     * @return array<int, string>
     */
    public static function rejectionNotes(array $errors, array $batchKeys): array
    {
        $notes = [];

        if (isset($errors['plan.missing'])) {
            $notes[] = 'You must return exactly ' . count($batchKeys) . ' pages, one for each of these page_key values: ' . implode(', ', array_map(fn ($key) => preg_replace('/[^a-z0-9_.:-]/i', '', (string) $key), $batchKeys)) . '.';
        }

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $clean = trim(preg_replace('/[^\x20-\x7E]/', '', (string) $message) ?? '');

                if ($clean !== '' && $field !== 'plan.missing') {
                    $notes[] = mb_substr($clean, 0, self::MAX_REJECTION_CHARS);
                }
            }
        }

        return array_slice(array_values(array_unique($notes)), 0, self::MAX_REJECTION_NOTES);
    }

    /**
     * Prompt lines describing the exact section JSON the output validator accepts, limited to the
     * section types the plan actually allows. Mirrors WebsiteSectionValidator's rules; the guided
     * validator still rejects anything that does not match.
     *
     * @return array<int, string>
     */
    public static function sectionFormatInstructions(array $plan): array
    {
        $schemas = [
            'hero' => '{"type":"hero","data":{"heading":string(max 120),"subheading":string|null(max 240),"primary_cta":{"label":string(max 40),"url":string}|null,"secondary_cta":{"label":string(max 40),"url":string}|null}}',
            'text' => '{"type":"text","data":{"heading":string|null(max 120),"body":string(required, max 5000)}}',
            'image_text' => '{"type":"image_text","data":{"heading":string|null(max 120),"body":string(required, max 3000),"image":null,"image_position":"left"|"right"}}',
            'services' => '{"type":"services","data":{"heading":string|null,"items":[{"name":string(required, max 120),"description":string|null(max 500),"price_label":null}]}} (1-12 items; use only services from confirmed_facts, and leave price_label null unless a price is a confirmed fact)',
            'testimonials' => '{"type":"testimonials","data":{"heading":string|null,"items":[{"quote":string,"author_name":string,"author_title":string|null}]}} (only real reviews supplied in confirmed_facts; otherwise do not use this type)',
            'faq' => '{"type":"faq","data":{"heading":string|null,"items":[{"question":string(max 200),"answer":string(max 1000)}]}} (1-20 items)',
            'cta' => '{"type":"cta","data":{"heading":string(required, max 120),"body":string|null(max 300),"buttons":[{"label":string(max 40),"url":string}]}} (1-2 buttons)',
            'contact_details' => '{"type":"contact_details","data":{"show_phone":boolean,"show_email":boolean,"show_address":boolean}}',
        ];

        $allowed = collect($plan)->pluck('allowed_section_types')->flatten()->unique()->all();
        $lines = [
            'Every entry of "sections" must be an object of exactly this shape: {"type": <section type>, "data": {...}}. Put every field of a section inside its "data" object — never beside "type" — and use only the field names below. A section without "type" and a "data" object is rejected.',
            'Every page needs exactly one "hero" section; place it first.',
            'Button/CTA urls must be one of the exact page paths listed in these instructions, an https:// URL, a tel: link or a mailto: link. Never invent another URL.',
            'Section schemas (use only the types in that page\'s allowed_section_types; omit optional fields or use null):',
        ];

        foreach ($schemas as $type => $schema) {
            if (in_array($type, $allowed, true)) {
                $lines[] = '- ' . $schema;
            }
        }

        return $lines;
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
            // The same privacy decision the published site makes (active location, a storefront or hybrid
            // service model, the public-address permission): an address the site would withhold must not
            // reach the AI either, or it can be pasted into body copy the privacy gate never redacts.
            if (app(\App\Library\Website\Seo\WebsiteAddressPrivacyGate::class)->currentlyPermitsAddress($business) && trim((string) $primaryLocation->address_line_1) !== '') {
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
