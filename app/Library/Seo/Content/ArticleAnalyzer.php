<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Enums\Website\WebsiteStatus;
use App\Models\Business;
use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — the article's transparent checklist. There is deliberately NO numeric "SEO
 * score": every row is a plain yes/no check with the reason, shown as Good or Needs attention.
 *
 * Checks (each is `good` or `attention`):
 *   title            a title is present and not over-long
 *   description      a meta description is present, and a sensible length
 *   heading          the page has exactly one H1 (the article page owns it; the body must add none)
 *   topic            the target topic is actually in the title or the first part of the article
 *   links            at least one internal link that resolves to a live, indexable page or article
 *   image            a featured image is set and has alt text
 *   facts            no unsupported claim (invented price, years in business, awards, ratings, counts, guarantees)
 *   duplication      no strong overlap with a money page or another article
 *   schema           everything BlogPosting structured data needs is real data we hold
 *   indexability     whether search engines can find it, and the reason when they cannot
 *
 * `blockers()` is the subset that stops publishing: it is what ArticleManager enforces. Everything else
 * is advice. Pure reads: no AI, no provider, no writes.
 */
final class ArticleAnalyzer
{
    public const MIN_WORDS = 150;

    public function __construct(
        private readonly ArticleClaimGuard $claims,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleSiteInventory $inventory,
    ) {
    }

    /**
     * @return array{checks: array<int, array{key: string, label: string, status: string, detail: string}>, overall: string}
     */
    public function analyze(Business $business, WebsiteArticle $article): array
    {
        $checks = [];
        $add = function (string $key, string $label, bool $good, string $goodText, string $badText) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $good ? 'good' : 'attention', 'detail' => $good ? $goodText : $badText];
        };

        $title = trim((string) $article->title);
        $add('title', 'Title', $title !== '' && mb_strlen($title) <= 70, 'Clear title of a good length.', $title === '' ? 'Add a title.' : 'Shorten the title to about 60 characters so it is not cut off in search results.');

        $description = trim((string) $article->meta_description);
        $dl = mb_strlen($description);
        $add('description', 'Meta description', $dl >= 70 && $dl <= 170, 'Meta description is a good length.', $dl === 0 ? 'Add a meta description so search results show a useful summary.' : 'Aim for 70–160 characters in the meta description.');

        $body = (string) $article->body;
        $hasBodyH1 = collect(ArticleMarkdown::headings($body))->contains(fn ($h) => $h['level'] === 1);
        $add('heading', 'One H1', ! $hasBodyH1, 'The page has one H1 (the title).', 'The text has its own top-level heading. The title is already the H1, so use "##" headings inside the article.');

        $add('topic', 'Target topic', $this->topicRepresented($article), 'The target topic appears in the title or opening.', 'Mention the target topic in the title or the first paragraph so readers and search engines see what the article is about.');

        $resolvable = $this->resolvableLinkCount($business, $body);
        $add('links', 'Internal links', $resolvable >= 1, $resolvable . ' internal ' . ($resolvable === 1 ? 'link' : 'links') . ' to your site.', 'Link to at least one of your service, package or location pages.');

        $image = $article->featuredAsset;
        $imageGood = $image !== null && trim((string) $image->alt_text) !== '';
        $add('image', 'Featured image', $imageGood, 'Featured image with alt text.', $image === null ? 'Add a featured image you own.' : 'Add alt text to the featured image.');

        $hard = $this->claims->hardFindings($business, $body . "\n" . $title . "\n" . (string) $article->excerpt . "\n" . $description);
        $soft = array_filter($this->claims->scan($business, $body), fn ($f) => ! $f['hard']);
        $add('facts', 'Supported facts', $hard === [] && $soft === [] && ! $this->claims->hasQuotation($body), 'No unsupported claims found.', $hard !== [] ? $hard[0]['message'] : ($soft !== [] ? array_values($soft)[0]['message'] : 'Remove the quotation block unless it is a real, attributed quote you have permission to use.'));

        $overlap = $this->guard->check($business, array_filter([$title, $article->primary_topic]), (int) $article->id);
        $add('duplication', 'Overlap', ! $overlap->strong(), 'No strong overlap with your other pages or articles.', $overlap->strongFindings()[0]['reason'] ?? 'Strong topic overlap.');

        $schemaMissing = $this->schemaGaps($business, $article);
        $add('schema', 'Structured data', $schemaMissing === [], 'Article structured data has everything it needs.', 'Structured data is missing: ' . implode(', ', $schemaMissing) . '.');

        [$indexable, $indexReason] = $this->indexability($article);
        $add('indexability', 'Search visibility', $indexable, $indexReason, $indexReason);

        $overall = collect($checks)->contains(fn ($c) => $c['status'] === 'attention') ? 'attention' : 'good';

        return ['checks' => $checks, 'overall' => $overall];
    }

    /**
     * What stops this article being published or scheduled. Empty = ready.
     *
     * @return array<int, string>
     */
    public function blockers(Business $business, WebsiteArticle $article): array
    {
        $blockers = [];

        if (trim((string) $article->title) === '') {
            $blockers[] = 'Add a title.';
        }

        if (! ArticleSlugger::isValid((string) $article->slug)) {
            $blockers[] = 'The web address (slug) is not valid.';
        }

        if (ArticleMarkdown::wordCount($article->body) < self::MIN_WORDS) {
            $blockers[] = 'The article is too short to publish (at least ' . self::MIN_WORDS . ' words).';
        }

        foreach ($this->claims->hardFindings($business, (string) $article->body . "\n" . (string) $article->title . "\n" . (string) $article->excerpt . "\n" . (string) $article->meta_description) as $finding) {
            $blockers[] = $finding['message'];
        }

        return array_values(array_unique($blockers));
    }

    /** @return array{0: bool, 1: string} */
    public function indexability(WebsiteArticle $article): array
    {
        if ($article->status !== ArticleStatus::Published) {
            return [false, 'Not visible to search engines until it is published.'];
        }

        if ($article->noindex) {
            return [false, 'Hidden from search engines by your choice.'];
        }

        $website = $article->website;

        if ($website === null || $website->status !== WebsiteStatus::Published) {
            return [false, 'Your website is not published, so search engines cannot see this article.'];
        }

        if ($website->activePrimaryDomain() === null) {
            return [false, 'Search engines index articles on your live custom domain. Connect a domain to make it findable.'];
        }

        return [true, 'Search engines can find this article.'];
    }

    private function topicRepresented(WebsiteArticle $article): bool
    {
        $topic = trim((string) $article->primary_topic);

        if ($topic === '') {
            return false;
        }

        $core = ArticleTopicSignature::of($topic);
        $needed = array_merge($core['core'], $core['modifiers']);

        if ($needed === []) {
            return false;
        }

        $opening = mb_strtolower((string) $article->title . ' ' . mb_substr(ArticleMarkdown::plainText($article->body), 0, 600));
        $openingTokens = ArticleTopicSignature::of($opening);
        $haystack = array_merge($openingTokens['core'], $openingTokens['modifiers']);
        $present = count(array_intersect($needed, $haystack));

        return $present >= max(1, (int) ceil(count($needed) * 0.6));
    }

    private function resolvableLinkCount(Business $business, string $body): int
    {
        $pages = collect($this->inventory->pages($business))->keyBy('uid');
        $count = 0;

        foreach (ArticleMarkdown::internalRefs($body) as $ref) {
            if ($ref['type'] === 'page') {
                $page = $pages->get($ref['uid']);
                $count += $page !== null && $page['linkable'] ? 1 : 0;
            } else {
                $count += WebsiteArticle::query()->where('business_id', $business->id)->published()
                    ->where('uid', $ref['uid'])->where('noindex', false)->exists() ? 1 : 0;
            }
        }

        return $count;
    }

    /** @return array<int, string> */
    private function schemaGaps(Business $business, WebsiteArticle $article): array
    {
        $missing = [];

        if (trim((string) $article->title) === '') {
            $missing[] = 'headline';
        }

        if (trim((string) $business->name) === '') {
            $missing[] = 'publisher name';
        }

        if (trim((string) ($article->author_name ?: $business->name)) === '') {
            $missing[] = 'author';
        }

        return $missing;
    }
}
