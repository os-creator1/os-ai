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
 * readers); this class decides nothing about content itself and invents no number. Wording never claims a cause:
 * an article's position is an observation, so updating or supporting it "may help" — never "will raise it".
 *
 *   content.topics_not_covered:v1   informational topics worth an article that nothing covers yet
 *   content.article_near_page_one:v1 published article(s) whose tracked search sits around positions 8-20
 *   content.article_rank_declined:v1 article(s) whose tracked position got materially worse
 *   content.article_stale:v1         article(s) due for review (old, package changed, supported page changed)
 *
 * "An article is performing well" is the positive statement of the near-page-one rule, not a recommendation.
 */
final class ContentRules extends AbstractGrowthRule
{
    private const KINDS = ['topics', 'near', 'declined', 'stale'];

    private function __construct(private readonly string $kind)
    {
    }

    public static function topicsNotCovered(): self
    {
        return new self('topics');
    }

    public static function nearPageOne(): self
    {
        return new self('near');
    }

    public static function rankDeclined(): self
    {
        return new self('declined');
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
            'near' => new GrowthRuleDefinition(...$base, key: 'content.article_near_page_one:v1',
                title: 'An article is close to the first page of search results',
                summary: 'Your latest rank check has an article just below the first page for the search you track.',
                factKey: 'content_near_page_one',
                evidenceSummary: 'Published articles whose tracked search is currently around positions 8 to 20.',
                actionKey: 'growth_improve_article', actionLabel: 'Open articles', weight: 2,
                why: 'An article near the first page is one that more readers might reach with a little more help.',
                expected: 'Updating or supporting this content may help it be found more easily. Rankings are not guaranteed.'),
            'declined' => new GrowthRuleDefinition(...$base, key: 'content.article_rank_declined:v1',
                title: 'An article has slipped in search results',
                summary: 'Between your last two rank checks an article moved down for the search you track.',
                factKey: 'content_rank_declined',
                evidenceSummary: 'Published articles whose tracked position got materially worse between two checks.',
                actionKey: 'growth_review_article', actionLabel: 'Review article', weight: 2,
                why: 'A drop can have many causes. Reviewing the article for anything out of date is a sensible first step.',
                expected: 'Reviewing and updating this content may help. We cannot say what caused the change or promise it will recover.'),
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

        $impact = match ($this->kind) {
            'declined' => 3,
            'near' => 3,
            default => 2,
        };

        return [0 => [
            'impact' => $impact, 'urgency' => $this->kind === 'declined' ? 2 : 1, 'effort' => 3,
            // Rank figures are observations; a topic or review list is a deterministic inference.
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

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return $this->kind === 'near' ? ['top' => (int) ($facts->set('content')->get('performing_well')['count'] ?? 0)] : null;
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);

        return match ($this->kind) {
            'topics' => GrowthMoney::plural($count, 'topic worth an article has', 'topics worth an article have') . ' no article yet.',
            'near' => GrowthMoney::plural($count, 'article is', 'articles are') . ' close to the first page of search results.',
            'declined' => GrowthMoney::plural($count, 'article has', 'articles have') . ' slipped in search results.',
            default => GrowthMoney::plural($count, 'article needs', 'articles need') . ' a review.',
        };
    }

    public function positiveStatement(array $positive): ?string
    {
        $top = (int) ($positive['top'] ?? 0);

        return $this->kind === 'near' && $top >= 1
            ? GrowthMoney::plural($top, 'article ranks', 'articles rank') . ' in the top five for the search you track.'
            : null;
    }

    /** @return array{count: int, titles: array<int, string>} */
    private function bucket(GrowthFactSnapshot $facts): array
    {
        $key = ['topics' => 'topics_not_covered', 'near' => 'near_page_one', 'declined' => 'rank_declined', 'stale' => 'stale'][$this->kind];
        $value = $facts->set('content')->get($key, ['count' => 0, 'titles' => []]);

        return ['count' => (int) ($value['count'] ?? 0), 'titles' => (array) ($value['titles'] ?? [])];
    }
}
