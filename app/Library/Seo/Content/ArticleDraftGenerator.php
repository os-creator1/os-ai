<?php

namespace App\Library\Seo\Content;

use App\Exceptions\Seo\ArticleDraftException;
use App\Exceptions\Seo\ArticleException;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;
use App\Models\WebsiteArticle;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — AI-assisted article drafting. This is the ONLY place the content engine calls an AI, and it
 * runs only when an owner explicitly asks for a draft: never on a page render, a list, the sidebar or a Growth read.
 *
 * What it guarantees:
 *  - ONE AI call per draft, through the Website's existing client (WebsiteGeneration budget, ledger and refusals);
 *  - the prompt carries only the Business's own facts (ArticleGroundingFacts) and the internal links it may use;
 *  - a topic that is the same search as one of the Business's money pages is refused BEFORE any AI call;
 *  - the result is only ever a DRAFT, created through ArticleManager. Nothing here publishes or schedules;
 *  - the output is normalised deterministically: no H1, no images, no external links, and no internal link that
 *    is not on the allowed list (it falls back to plain text).
 *
 * Unsupported claims are not "fixed" by guessing. The draft is saved as written, ArticleClaimGuard flags every
 * invented figure live in the editor, and ArticleManager refuses to publish while a hard claim remains.
 */
final class ArticleDraftGenerator
{
    private const MAX_OUTPUT_TOKENS = 3500;
    private const MIN_WORDS = 120;

    public function __construct(
        private readonly WebsiteAiGenerationClient $client,
        private readonly ArticleGroundingFacts $facts,
        private readonly ArticleDraftPromptBuilder $prompts,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleManager $articles,
    ) {
    }

    /**
     * @param  array<string, mixed>  $opportunity  see ArticleOpportunityEngine (title, primary_topic, search_intent, supports_page_uid, internal_links, key)
     */
    public function generate(Business $business, int $actorId, array $opportunity): WebsiteArticle
    {
        $title = trim((string) ($opportunity['title'] ?? ''));

        if ($title === '') {
            throw new ArticleDraftException(ArticleDraftException::UNUSABLE, 'Choose a topic to write about first.');
        }

        // Deterministic protection BEFORE spending anything.
        $conflict = $this->guard->check($business, [$title, (string) ($opportunity['primary_topic'] ?? '')]);

        if ($conflict->conflictsWithPage()) {
            throw new ArticleDraftException(ArticleDraftException::CANNIBALIZES, $conflict->strongFindings()[0]['reason']);
        }

        $facts = $this->facts->forBusiness($business);
        $messages = $this->prompts->messages($facts, $opportunity);

        // A fresh ledger identity per explicit request: a retry after a failure must be able to run again.
        // Double submits are stopped upstream (the opportunity's article_uid + the route throttle).
        $raw = $this->client->complete($messages, $business, $actorId, self::MAX_OUTPUT_TOKENS);

        if ($raw === null) {
            throw $this->client->lastCallWasBudgetExhausted()
                ? new ArticleDraftException(ArticleDraftException::BUDGET, 'Your included AI usage for this period is used up. You can write this article yourself now, or try again after it resets.')
                : new ArticleDraftException(ArticleDraftException::UNAVAILABLE, 'AI drafting is not available right now. You can write this article yourself, or try again later.');
        }

        $parsed = $this->parse($raw);

        if ($parsed === null) {
            throw new ArticleDraftException(ArticleDraftException::UNUSABLE, 'The draft that came back could not be used. Please try again, or write this article yourself.');
        }

        $allowed = [];
        foreach ((array) ($opportunity['internal_links'] ?? []) as $link) {
            $allowed[$link['type'] . ':' . strtolower($link['uid'])] = true;
        }

        $body = $this->normalize($parsed['body_markdown'], $allowed);

        if (ArticleMarkdown::wordCount($body) < self::MIN_WORDS) {
            throw new ArticleDraftException(ArticleDraftException::UNUSABLE, 'The draft that came back was too short to use. Please try again, or write this article yourself.');
        }

        $seoTitle = trim((string) ($parsed['title'] ?? ''));

        try {
            return $this->articles->create($actorId, $business, [
                'title' => $title,
                'seo_title' => $seoTitle !== '' && $seoTitle !== $title && mb_strlen($seoTitle) <= 70 ? $seoTitle : null,
                'excerpt' => Str::limit(trim((string) ($parsed['excerpt'] ?? '')), 400, ''),
                'meta_description' => $this->metaDescription((string) ($parsed['meta_description'] ?? '')),
                'body' => $body,
                'primary_topic' => (string) ($opportunity['primary_topic'] ?? $title),
                'search_intent' => $opportunity['search_intent'] ?? null,
                'supports_page_uid' => $opportunity['supports_page_uid'] ?? null,
                'opportunity_key' => $opportunity['key'] ?? null,
                'source' => 'ai_draft',
                'ai_generated' => true,
            ]);
        } catch (ArticleException $e) {
            throw new ArticleDraftException(ArticleDraftException::NO_WEBSITE, $e->customerMessage());
        }
    }

    /** @return array{title?: string, excerpt?: string, meta_description?: string, body_markdown: string}|null */
    public function parse(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['body_markdown']) || ! is_string($decoded['body_markdown']) || trim($decoded['body_markdown']) === '') {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, true>  $allowed  "page:<uid>" / "article:<uid>" the article may link to
     */
    public function normalize(string $markdown, array $allowed): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // The page owns the single H1.
        $markdown = (string) preg_replace('/^#\s+/m', '## ', $markdown);

        // No images, and no external links: V1 articles link only inside the Business's own site.
        $markdown = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $markdown);
        $markdown = (string) preg_replace('/\[([^\]]+)\]\(https?:\/\/[^)]*\)/i', '$1', $markdown);
        $markdown = (string) preg_replace('/<[^>]+>/', '', $markdown);

        // An internal reference is kept only if it is on the allowed list, and only the first time.
        $used = [];
        $markdown = (string) preg_replace_callback('/\[([^\]]+)\]\((page|article):([0-9a-fA-F-]{36})\)/', function (array $m) use ($allowed, &$used) {
            $ref = $m[2] . ':' . strtolower($m[3]);

            if (! isset($allowed[$ref]) || isset($used[$ref])) {
                return $m[1];
            }

            $used[$ref] = true;

            return $m[0];
        }, $markdown);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown)) . "\n";
    }

    public function metaDescription(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) <= 160 ? $text : rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 158), ' ') ?: 158), " ,;:") . '…';
    }
}
