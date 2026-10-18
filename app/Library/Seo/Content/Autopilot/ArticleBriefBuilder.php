<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Library\Seo\Content\ArticleTopicSignature;

/**
 * Content Autopilot - the structured brief an article is written FROM. Built deterministically (no AI) from one scored
 * opportunity and the Content Fact Pack: only the facts that bear on THIS topic go in, so the prompt stays small and the
 * writer has nothing to invent from. The writer never sees the whole Business, and never sees a fact that is not here.
 *
 *   topic / intent / journey stage    what is being answered, and for whom;
 *   supports                          the commercial page this article exists to help (and the call to action it ends on);
 *   facts                             the ONLY Business facts the article may state;
 *   questions                         what the reader wants answered, in order;
 *   links                             the internal references the writer may use (page:uid / article:uid);
 *   must_not                          everything it must not invent or say - fixed rules, the niche's phrases and the
 *                                     owner's prohibited claims and avoided topics;
 *   niche                             risk tier and preferred terms from the Blueprint's content policy.
 *
 * `brief_hash` identifies the brief by content, so an unchanged topic with unchanged facts is never re-briefed.
 */
final class ArticleBriefBuilder
{
    public const VERSION = 1;

    private const MAX_SERVICES = 5;
    private const MAX_FAQS = 3;
    private const MAX_QUESTIONS = 6;
    private const MAX_PACKAGES = 6;

    private const NEVER = [
        'any price, fee or discount that is not in "facts.packages" with a price',
        'years in business, founding dates, awards, rankings or certifications that are not in "facts.proof_points"',
        'statistics, percentages, customer counts, event counts or review scores',
        'guarantees, warranties, "best", "#1", "leading" or other superlatives',
        'celebrity or client names, press mentions, testimonials or quotations',
        'locations, services or products that are not in "facts"',
        'competitor names or comparisons that name another business',
        'medical, legal or financial advice',
    ];

    private const DEFAULT_QUESTIONS = [
        'cost' => ['What affects the price?', 'What is included in each option?', 'How do I get an exact quote?'],
        'how_to' => ['What are the steps, in order?', 'What mistakes are easy to make?', 'When is it worth getting help?'],
        'comparison' => ['How do the options differ?', 'Which suits which situation?', 'What should I ask before choosing?'],
        'ideas' => ['What ideas work well?', 'How do I choose between them?', 'What should I plan ahead?'],
        'planning' => ['What do I need to arrange first?', 'What does the timeline look like?', 'What details are easy to forget?'],
        'guide' => ['What is it, in plain terms?', 'Who is it for?', 'What should I know before deciding?'],
    ];

    private const TARGET_WORDS = ['cost' => 700, 'how_to' => 900, 'comparison' => 800, 'ideas' => 700, 'planning' => 800, 'guide' => 800];

    public function __construct(private readonly ContentPolicy $policy)
    {
    }

    /**
     * @param  array<string, mixed>  $scored  one row of AutopilotScorer::rank()
     * @param  array<string, mixed>  $pack  ContentFactPack::forBusiness()
     * @return array<string, mixed>
     */
    public function build(\App\Models\Business $business, array $scored, array $pack): array
    {
        $opportunity = $scored['opportunity'];
        $intent = (string) $scored['intent'];
        $topicTokens = $this->tokens((string) $scored['title'] . ' ' . (string) ($opportunity['primary_topic'] ?? ''));
        $niche = $this->policy->forBusiness($business);

        $brief = [
            'version' => self::VERSION,
            'topic' => (string) $scored['title'],
            'primary_topic' => (string) ($opportunity['primary_topic'] ?? $scored['title']),
            'intent' => $intent,
            'stage' => $opportunity['stage'] ?? null,
            'audience' => $pack['ideal_customers'] ?? null,
            'supports' => [
                'uid' => $opportunity['supports_page_uid'] ?? null,
                'title' => $opportunity['supports_page_title'] ?? null,
            ],
            'cta' => $this->cta($opportunity),
            'facts' => $this->facts($pack, $intent, $topicTokens, (string) ($opportunity['supports_page_title'] ?? '')),
            'questions' => $this->questions($pack, $intent, $topicTokens),
            'links' => array_values(array_map(
                fn (array $l) => ['type' => $l['type'], 'uid' => $l['uid'], 'title' => $l['title'], 'anchor' => $l['anchor'] ?? $l['title']],
                (array) ($opportunity['internal_links'] ?? []),
            )),
            'must_not' => [
                'invent' => self::NEVER,
                'phrases' => $this->policy->prohibitedPhrases($business),
                'topics' => array_values((array) $pack['avoid_topics']),
            ],
            'niche' => [
                'risk_tier' => $niche['risk_tier'],
                'preferred_terms' => $niche['preferred_terms'],
                'brand_voice' => $pack['brand_voice'] ?? null,
            ],
            'length' => ['target_words' => self::TARGET_WORDS[$intent] ?? 800, 'min_words' => 350],
            'fact_hash' => $pack['fact_hash'],
        ];

        $brief['brief_hash'] = BlueprintChecksum::of($brief);

        return $brief;
    }

    /** @return array{kind: string, uid: ?string, title: ?string} where the article should send the reader */
    private function cta(array $opportunity): array
    {
        $uid = $opportunity['supports_page_uid'] ?? null;

        return $uid !== null
            ? ['kind' => 'page', 'uid' => $uid, 'title' => $opportunity['supports_page_title'] ?? null]
            : ['kind' => 'none', 'uid' => null, 'title' => null];
    }

    /** @return array<string, mixed> only the facts that bear on this topic */
    private function facts(array $pack, string $intent, array $topicTokens, string $supportsTitle): array
    {
        $pricing = $intent === 'cost' || stripos($supportsTitle, 'package') !== false || stripos($supportsTitle, 'pricing') !== false;

        $packages = array_map(
            fn (array $p) => $pricing ? $p : array_diff_key($p, ['price' => true, 'price_note' => true]),
            array_slice((array) $pack['packages'], 0, self::MAX_PACKAGES),
        );

        $areas = array_values(array_filter((array) $pack['service_areas'], fn ($city) => $this->overlap($this->tokens((string) $city), $topicTokens) >= 1.0));
        $faqs = array_values(array_filter((array) $pack['faqs'], fn ($f) => $this->overlap($this->tokens((string) $f['question']), $topicTokens) >= 0.5));

        return array_filter([
            'business_name' => $pack['business_name'],
            'services' => array_slice((array) $pack['services'], 0, self::MAX_SERVICES),
            'packages' => $packages,
            'service_areas' => $areas !== [] ? $areas : array_slice((array) $pack['service_areas'], 0, 3),
            'differentiators' => $pack['differentiators'],
            'proof_points' => $pack['proof_points'],
            'customer_problems' => array_slice((array) $pack['customer_problems'], 0, 4),
            'faqs' => array_slice($faqs, 0, self::MAX_FAQS),
            'emphasis' => $pack['emphasis'],
        ], fn ($v) => $v !== [] && $v !== null && $v !== '');
    }

    /** @return list<string> */
    private function questions(array $pack, string $intent, array $topicTokens): array
    {
        $own = [];

        foreach ([...(array) $pack['common_questions'], ...array_column((array) $pack['faqs'], 'question')] as $question) {
            if ($this->overlap($this->tokens((string) $question), $topicTokens) >= 0.5) {
                $own[] = trim((string) $question);
            }
        }

        $all = array_merge(array_slice(array_values(array_unique($own)), 0, 3), self::DEFAULT_QUESTIONS[$intent] ?? self::DEFAULT_QUESTIONS['guide']);

        return array_slice(array_values(array_unique($all)), 0, self::MAX_QUESTIONS);
    }

    /** @return list<string> the SUBJECT words of a phrase (question words like "how" or "cost" would match everything) */
    private function tokens(string $phrase): array
    {
        return array_values(array_unique(ArticleTopicSignature::of($phrase)['core']));
    }

    private function overlap(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / min(count($a), count($b));
    }
}
