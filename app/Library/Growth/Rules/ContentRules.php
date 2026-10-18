<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * SEO Content (blog) rules. Facts only come from GrowthContentFactReader (the content engine's deterministic
 * readers); this class decides nothing about content itself and invents no number.
 *
 *   content.topics_not_covered:v1   informational topics worth an article that nothing covers yet
 *   content.article_stale:v1        article(s) due for review (old, package changed, supported page changed)
 *
 * Rank movement is NOT judged here: the canonical SEO rank rules (seo.meaningful_rank_drop,
 * seo.rank_just_outside_top_10) already own it. The content reader still exposes article-level rank facts for the
 * Content Plan, but Growth Center has exactly one set of rank rules.
 */
final class ContentRules extends AbstractGrowthRule
{
    private function __construct(private readonly string $kind)
    {
    }

    public static function topicsNotCovered(): self
    {
        return new self('topics');
    }

    public static function stale(): self
    {
        return new self('stale');
    }

    public function definition(): GrowthRuleDefinition
    {
        $base = [
            'worker' => OpportunityWorkerKey::Seo,
            'category' => GrowthCategory::Seo,
            'sourceModule' => 'seo',
            'domain' => 'content',
            'scope' => 'business',
            'target' => 'seo.content',
            'safetyClass' => GrowthActionSafetyClass::ReadOnly,
            'minSample' => 1,
            'goalKeys' => [BusinessGoal::LocalSeo->value],
        ];

        return match ($this->kind) {
            'topics' => new GrowthRuleDefinition(...$base, key: 'content.topics_not_covered:v1',
                title: 'Questions your customers ask have no article yet',
                summary: 'These topics would support your service pages, and nothing on your website answers them yet.',
                factKey: 'content_topics_not_covered',
                evidenceSummary: 'Informational topics suggested from your services, packages and areas that no article covers.',
                actionKey: 'growth_write_article', actionLabel: 'See topics', weight: 1,
                why: 'People often look for cost, ideas and planning advice before they enquire. An article that answers well gives them a reason to visit your site.',
                expected: 'A useful article can make your site easier to find for that question. It does not guarantee a ranking or a booking.'),
            default => new GrowthRuleDefinition(...$base, key: 'content.article_stale:v1',
                title: 'An article needs a review',
                summary: 'An article has not been updated in a long time, or mentions something on your site that has changed.',
                factKey: 'content_article_stale',
                evidenceSummary: 'Published articles that are old, mention a changed package, or support a page that changed.',
                actionKey: 'growth_review_article', actionLabel: 'See what needs review', weight: 1,
                why: 'Out-of-date prices or details in an article can mislead the people reading it.',
                expected: 'Keeping an article accurate helps the people who read it. It is not a ranking promise.'),
        };
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $bucket = $this->bucket($facts);

        if ($bucket['count'] < 1 || ($this->kind === 'topics' && ! $facts->set('content')->get('website_published'))) {
            return [];
        }

        return [0 => [
            'impact' => 2, 'urgency' => 1, 'effort' => 3,
            // A topic or review list is a deterministic inference, not a measurement.
            'confidence' => $this->kind === 'topics' ? 0.8 : 1.0,
            // Titles are owner-typed text; the engine's evidence validator rejects markup, so a title carrying it
            // is left out (the count still includes it).
            'evidence' => ['count' => $bucket['count'], 'titles' => array_values(array_filter($bucket['titles'], fn (string $t) => $t === strip_tags($t)))],
        ]];
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $content = $facts->set('content');

        return $this->kind === 'topics'
            ? ($content->get('website_published') ? 1 : 0)
            : (int) $content->get('article_count', 0);
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        return $this->kind === 'topics'
            ? GrowthMoney::plural($count, 'topic worth an article has', 'topics worth an article have') . ' no article yet.'
            : GrowthMoney::plural($count, 'article needs', 'articles need') . ' a review.';
    }

    /** @return array{count: int, titles: array<int, string>} */
    private function bucket(GrowthFactSnapshot $facts): array
    {
        $key = $this->kind === 'topics' ? 'topics_not_covered' : 'stale';
        $value = $facts->set('content')->get($key, ['count' => 0, 'titles' => []]);

        return ['count' => (int) ($value['count'] ?? 0), 'titles' => (array) ($value['titles'] ?? [])];
    }
}
