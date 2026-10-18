<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Library\Seo\Content\ArticleGroundingFacts;
use App\Library\Seo\Content\ConfirmedBusinessClaims;
use App\Models\Business;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessKnowledgeProfile;
use App\Models\SeoKeyword;
use App\Models\WebsiteArticle;

/**
 * Content Autopilot — the ONE canonical Content Fact Pack: everything an article may be written from, and nothing else.
 *
 * It composes, never duplicates, the sources that already exist:
 *   - ArticleGroundingFacts (name, services, real packages and prices, service areas, site summary, FAQs, niche FAQ topics);
 *   - the Business Knowledge Profile (differentiators, customers' problems, brand voice, prohibited claims, and the
 *     claim-bearing fields years operating / credentials — those two ONLY when the owner has confirmed them);
 *   - the Content Profile (common questions, emphasis, topics to avoid — ContentProfile);
 *   - the stored Google Business Profile mirror (categories only, only while fresh — never refreshed from here);
 *   - tracked keyword phrases and the Business's existing articles (so a brief knows what is already covered).
 *
 * Read-only and deterministic: no AI, no provider call, no rank job. Reviews and testimonials are deliberately NOT part of
 * the pack in V1 (no canonical review-text source; the claim guard also forbids ratings). A guarantee is never offered as
 * a fact either — the claim guard hard-blocks the word, so offering it would only invite a rejected draft.
 *
 * `fact_hash` is a stable digest of the citable facts: an unchanged hash means previously built briefs and classifications
 * are still valid and need no new AI call.
 */
final class ContentFactPack
{
    private const MAX_EXISTING_ARTICLES = 40;
    private const MAX_PHRASES = 10;

    public function __construct(
        private readonly ArticleGroundingFacts $grounding,
        private readonly ContentProfile $profile,
        private readonly ConfirmedBusinessClaims $claims,
    ) {
    }

    public function forBusiness(Business $business): array
    {
        $knowledge = BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first();
        $content = $this->profile->get($business);
        $years = $this->claims->years($business);
        $credentials = $this->claims->credentials($business);

        $pack = $this->grounding->forBusiness($business);

        $pack += [
            'differentiators' => array_values(array_filter((array) ($knowledge?->differentiators ?? []), 'is_string')),
            'ideal_customers' => $this->nullable($knowledge?->ideal_customers),
            'customer_problems' => array_values(array_filter((array) ($knowledge?->customer_problems ?? []), 'is_string')),
            'brand_voice' => $this->nullable($knowledge?->brand_voice),
            'proof_points' => array_filter(['years_operating' => $years, 'credentials' => $credentials ?: null]),
            // What the claim guard may let through because the owner has confirmed it.
            'allowed_claims' => array_filter(['years' => $years !== null ? [$years] : null]),
            'prohibited_claims' => array_values(array_filter((array) ($knowledge?->prohibited_claims ?? []), 'is_string')),
            'avoid_topics' => $content['avoid_topics'],
            'common_questions' => $content['common_questions'],
            'emphasis' => $content['emphasis'],
            'google_categories' => $this->googleCategories($business),
            'tracked_phrases' => $this->trackedPhrases($business),
            'existing_articles' => $this->existingArticles($business),
        ];

        $pack['fact_hash'] = $this->hash($pack);

        return $pack;
    }

    /**
     * How many citable facts each group holds — the scorer's "unique Business information" input and the basis for
     * deciding whether to ask the owner for one more fact.
     *
     * @return array<string, int>
     */
    public function groups(array $pack): array
    {
        return [
            'differentiators' => count($pack['differentiators'] ?? []),
            'customer_problems' => count($pack['customer_problems'] ?? []),
            'proof_points' => count($pack['proof_points'] ?? []),
            'priced_packages' => count(array_filter((array) ($pack['packages'] ?? []), fn ($p) => isset($p['price']))),
            'faqs' => count($pack['faqs'] ?? []),
            'common_questions' => count($pack['common_questions'] ?? []),
            'services' => count($pack['services'] ?? []),
            'service_areas' => count($pack['service_areas'] ?? []),
        ];
    }

    /** @return string[] */
    private function googleCategories(Business $business): array
    {
        $names = [];

        foreach (BusinessGoogleLocation::query()->where('business_id', $business->id)->get() as $binding) {
            if (! $binding->mirrorIsFresh()) {
                continue;
            }

            $mirror = (array) $binding->profile_mirror;
            $names[] = (string) ($mirror['primary_category_name'] ?? '');

            foreach ((array) ($mirror['additional_category_names'] ?? []) as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $names), fn (string $n) => $n !== '')));
    }

    /** @return string[] */
    private function trackedPhrases(Business $business): array
    {
        return SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('id')
            ->limit(self::MAX_PHRASES)
            ->pluck('phrase')
            ->map(fn ($p) => trim((string) $p))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array{uid: string, title: string, topic: ?string, status: string}> */
    private function existingArticles(Business $business): array
    {
        return WebsiteArticle::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->orderByDesc('id')
            ->limit(self::MAX_EXISTING_ARTICLES)
            ->get(['uid', 'title', 'primary_topic', 'status'])
            ->map(fn (WebsiteArticle $a) => [
                'uid' => (string) $a->uid,
                'title' => (string) $a->title,
                'topic' => $a->primary_topic,
                'status' => $a->status->value,
            ])
            ->all();
    }

    private function hash(array $pack): string
    {
        // Existing articles change on every publish but are not facts about the Business: keep them out of the digest so a
        // new article does not invalidate every cached brief for unrelated topics.
        unset($pack['existing_articles']);

        return BlueprintChecksum::of($pack);
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
