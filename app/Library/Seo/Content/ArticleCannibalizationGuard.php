<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Models\Business;
use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — cannibalization protection and duplicate-topic detection, deterministic.
 * Nothing here asks an AI "does this look duplicated": it compares ArticleTopicSignature values.
 *
 * Two different findings, because they need different responses:
 *
 *  1. MONEY-PAGE CONFLICT (type `page`, strength `strong`): a topic with NO informational modifier whose
 *     subject is entirely contained in a Home / service / location / packages page's own target phrase.
 *     "photo booth rental chicago" against a page already about "Photo Booth Rental Chicago" is the same
 *     search; an article that targets it would compete with the page that should win it. "How much does
 *     a photo booth rental cost in Chicago" carries a modifier (how much, cost) — a different, informational
 *     search the page does not answer — so it SUPPORTS the page and is allowed.
 *
 *  2. ARTICLE OVERLAP: another live (non-archived) article of the SAME Business with the same subject
 *     and the same modifiers is a duplicate (`strong`); the same subject with different modifiers is only
 *     `related` (it is a candidate for an internal link, not a warning).
 *
 * Reads the published snapshot through ArticleSiteInventory and the Business's own articles only — never
 * another Business's.
 */
final class ArticleCannibalizationGuard
{
    public function __construct(private readonly ArticleSiteInventory $inventory)
    {
    }

    /**
     * @param  array<int, string>  $phrases  the topic phrases to test (e.g. title and primary topic)
     */
    public function check(Business $business, array $phrases, ?int $ignoreArticleId = null, ?array $pages = null): CannibalizationResult
    {
        $candidates = [];

        foreach ($phrases as $phrase) {
            $signature = ArticleTopicSignature::of((string) $phrase);

            if ($signature['core'] !== []) {
                $candidates[] = $signature;
            }
        }

        if ($candidates === []) {
            return new CannibalizationResult([]);
        }

        $findings = [];

        foreach ($pages ?? $this->inventory->pages($business) as $page) {
            if (! $page['money']) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($candidate['modifiers'] !== [] || count($candidate['core']) < 2) {
                    continue;
                }

                foreach (ArticleSiteInventory::targetPhrases($page) as $target) {
                    // The page's whole subject, brand words included. Removing the Business name's words from
                    // it (as an earlier version did) broke detection for any Business whose name contains niche
                    // words ("Jazmin Photo Booth Co.": "photo" and "booth" were stripped from the page target,
                    // so "photo booth rental chicago" no longer looked contained in "Chicago Photo Booth Rental").
                    $targetCore = ArticleTopicSignature::of($target)['core'];

                    if ($targetCore !== [] && array_diff($candidate['core'], $targetCore) === []) {
                        $findings['page:' . $page['uid']] = [
                            'type' => 'page',
                            'uid' => $page['uid'],
                            'title' => $page['title'],
                            'strength' => 'strong',
                            'reason' => 'This topic is the same search as your "' . $page['title'] . '" page. An article should support that page with a different question (cost, ideas, how-to, comparison), not compete with it.',
                        ];

                        break 2;
                    }
                }
            }
        }

        $articles = WebsiteArticle::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->when($ignoreArticleId !== null, fn ($q) => $q->where('id', '!=', $ignoreArticleId))
            ->get(['id', 'uid', 'title', 'primary_topic']);

        foreach ($articles as $article) {
            foreach (array_filter([$article->title, $article->primary_topic]) as $existingPhrase) {
                $existing = ArticleTopicSignature::of((string) $existingPhrase);

                foreach ($candidates as $candidate) {
                    if ($existing['core'] === [] || $existing['core'] !== $candidate['core']) {
                        continue;
                    }

                    $same = $existing['modifiers'] === $candidate['modifiers'];

                    $findings['article:' . $article->uid] = [
                        'type' => 'article',
                        'uid' => (string) $article->uid,
                        'title' => (string) $article->title,
                        'strength' => $same ? 'strong' : 'related',
                        'reason' => $same
                            ? 'Your article "' . $article->title . '" already covers this topic.'
                            : 'Your article "' . $article->title . '" covers the same subject from a different angle. Link the two rather than repeating yourself.',
                    ];

                    continue 3;
                }
            }
        }

        return new CannibalizationResult(array_values($findings));
    }

}
