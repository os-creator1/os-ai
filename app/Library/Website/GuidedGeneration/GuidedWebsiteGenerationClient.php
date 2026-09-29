<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\WebsiteTemplate;

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
 * Sensitive-fact exclusion (contract §5.2): only a `customer_confirmed`
 * fact within its own freshness window is ever included in the prompt
 * at all — an unverified or stale sensitive fact is never sent, even
 * labeled as unverified.
 */
class GuidedWebsiteGenerationClient
{
    private const SENSITIVE_FIELDS = [
        BusinessKnowledgeProfileFieldKey::Credentials,
        BusinessKnowledgeProfileFieldKey::YearsOperating,
        BusinessKnowledgeProfileFieldKey::WarrantiesGuarantees,
        BusinessKnowledgeProfileFieldKey::Testimonials,
        BusinessKnowledgeProfileFieldKey::PricingMethod,
        BusinessKnowledgeProfileFieldKey::FinancingAvailable,
        BusinessKnowledgeProfileFieldKey::Offers,
        BusinessKnowledgeProfileFieldKey::Hours,
    ];

    public function __construct(
        private readonly WebsiteAiGenerationClient $client,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    /**
     * @return ?array<int, array{page_type: string, title: string, slug: ?string, seo_title: ?string, meta_description: ?string, sections: array}> null on any refusal/failure/malformed output
     */
    public function generate(Business $business, WebsiteTemplate $template, ?int $actorUserId = null): ?array
    {
        $messages = $this->buildMessages($business, $template);

        $raw = $this->client->complete($messages, $business, $actorUserId);
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
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Business $business, WebsiteTemplate $template): array
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile === null ? [] : $this->profiles->completenessCheck($business)->presentFieldKeys;

        // §7.4/§8.3 — the AI never sees image_slots (it never authors an
        // image reference) and never sees a template's presentation
        // theme (visual choice is never AI's to make).
        $manifest = collect($template->page_manifest['pages'] ?? [])->map(fn ($page) => [
            'page_type' => $page['page_type'],
            'is_home' => $page['is_home'],
            'allowed_section_types' => $page['allowed_section_types'],
        ])->all();

        $facts = [];
        foreach (BusinessKnowledgeProfileFieldKey::cases() as $key) {
            if (! in_array($key->value, $confirmed, true)) {
                continue;
            }

            if (in_array($key, self::SENSITIVE_FIELDS, true) && ! in_array($key->value, $confirmed, true)) {
                continue;
            }

            $facts[$key->value] = $profile?->{$key->value} ?? null;
        }

        $instructions = [
            'You are writing factual, bounded website copy for a real local business.',
            'You may only use the confirmed facts provided below — never invent a fact, statistic, award, review, price, or claim.',
            'Every page you output must use only the page_type and section types allowed for it in the manifest.',
            'Never include an image, background_image, or asset field of any kind.',
            'Never write any of these prohibited phrases: ' . implode('; ', $profile?->prohibited_claims ?? []),
            'For each page, also draft seo_title (max 70 characters, descriptive and distinct, never boilerplate or keyword-stuffed) and meta_description (max 160 characters, a genuine one-sentence summary of that specific page) — never copy the same seo_title or meta_description across two pages.',
            'Respond with a single JSON object: {"pages": [{"page_type": string, "title": string, "slug": string|null, "seo_title": string|null, "meta_description": string|null, "sections": [...]}]}.',
        ];

        return [
            ['role' => 'system', 'content' => implode("\n", $instructions)],
            ['role' => 'user', 'content' => json_encode([
                'business_name' => $business->name,
                'template_manifest' => $manifest,
                'confirmed_facts' => $facts,
            ])],
        ];
    }
}
