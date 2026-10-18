<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleException;
use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiBusinessCategoryCeiling;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Seo\Content\ArticleDraftGenerator;
use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Seo\Content\ArticleSlugger;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\Website;
use App\Models\WebsiteArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Content Autopilot - the ONE step that spends money: turn a briefed decision into a Draft article.
 *
 *   briefed -> drafting -> (validated) -> awaiting_approval   the article exists, as an ordinary Draft
 *                       -> deferred_budget                    the Business's budget is used up: wait for the next period
 *                       -> rejected                           still failing hard validation after ONE repair: never published
 *                       -> held                               could not be written (no website, a paid call that lost its result)
 *                       -> briefed                            the provider was unavailable: try again later
 *
 * It only ever creates a Draft through ArticleManager (the one article system); it never publishes. Whether a validated
 * draft then publishes itself is the scheduler's decision (niche policy, trust ramp, cadence), not this class's.
 *
 * Spend discipline: the Business's remaining budget is checked BEFORE any call (a refusal costs nothing and defers); the
 * article is written once by the strong route and rewritten at most once, only for hard findings; every call has an
 * idempotency key derived from the decision, and a paid attempt is never repeated.
 */
final class AutopilotArticleWriter
{
    private const MAX_OUTPUT_TOKENS = 3500;
    private const PASSES = 2;

    public function __construct(
        private readonly ContentAutopilotAiClient $client,
        private readonly BriefPromptBuilder $prompts,
        private readonly AutopilotArticleValidator $validator,
        private readonly ArticleDraftGenerator $drafts,
        private readonly ArticleManager $articles,
        private readonly AiBusinessCategoryCeiling $ceiling,
        private readonly AiBudgetPolicyResolver $policies,
        private readonly AiModelRouter $router,
        private readonly AiUsageLedgerManager $ledger,
    ) {
    }

    public function write(Decision $decision): Decision
    {
        if (! $this->claim($decision)) {
            return $decision->fresh();
        }

        $decision = $decision->fresh();
        $business = Business::query()->find($decision->business_id);
        $website = $business === null ? null : Website::query()->where('business_id', $business->id)->first();

        if ($business === null || $website === null) {
            return $this->finish($decision, Decision::STATE_HELD, 'no_website');
        }

        // A maintenance decision rewrites an EXISTING article; it never creates one.
        $existing = $decision->kind === Decision::KIND_MAINTAIN ? WebsiteArticle::query()->where('business_id', $business->id)->find($decision->article_id) : null;

        if ($decision->kind === Decision::KIND_MAINTAIN && ($existing === null || $existing->status !== ArticleStatus::Published)) {
            return $this->finish($decision, Decision::STATE_HELD, 'article_unavailable');
        }

        if (! $this->canAffordOneArticle($business)) {
            return $this->finish($decision, Decision::STATE_DEFERRED_BUDGET, 'budget_ceiling');
        }

        $brief = (array) $decision->brief;
        $allowedLinks = $this->allowedLinks($brief);
        $prefix = 'content_autopilot:' . $decision->uid . ':draft:';
        $rejectedFor = [];
        $draft = null;
        $result = null;

        for ($pass = 1; $pass <= self::PASSES; $pass++) {
            $family = $this->ledger->idempotencyFamily($prefix);

            if ($family['in_flight']) {
                return $decision->fresh();
            }

            if ($pass === 1 && $family['paid']) {
                return $this->finish($decision, Decision::STATE_HELD, 'draft_paid_no_result');
            }

            $raw = $this->client->write($this->prompts->messages($brief, $rejectedFor), $business, $prefix . ($family['attempts'] + 1), self::MAX_OUTPUT_TOKENS, (int) $business->customer_id);
            $this->addCost($decision, $this->client->lastCostMicrousd());

            if ($raw === null) {
                return $this->client->lastCallWasBudgetRefusal()
                    ? $this->finish($decision, Decision::STATE_DEFERRED_BUDGET, 'budget_refused')
                    : $this->finish($decision, Decision::STATE_BRIEFED, 'ai_unavailable');
            }

            $parsed = $this->drafts->parse($raw);

            if ($parsed === null) {
                $rejectedFor = ['output that was not the requested JSON'];

                continue;
            }

            $body = $this->drafts->normalize((string) $parsed['body_markdown'], $allowedLinks);
            $candidate = $existing !== null ? $this->rewriteCandidate($existing, $parsed, $body) : $this->candidate($website, $brief, $parsed, $body);
            $result = $this->validator->validate($business, $candidate, $brief);

            if ($result['hard'] === []) {
                $draft = [$candidate, $parsed];

                break;
            }

            $rejectedFor = $result['hard'];
        }

        if ($draft === null) {
            return $this->finish($decision, Decision::STATE_REJECTED, 'validation_failed', ['hard' => $rejectedFor, 'soft' => [], 'repaired' => true, 'ready' => false]);
        }

        [$candidate, $parsed] = $draft;

        if ($existing !== null) {
            // The update waits as the article's ONE pending draft (the live page changes only on "Publish update").
            $this->articles->update((int) $business->customer_id, $business, $existing, [
                'excerpt' => $candidate->excerpt,
                'meta_description' => $candidate->meta_description,
                'body' => $candidate->body,
            ]);

            return $this->finish($decision, Decision::STATE_AWAITING_APPROVAL, $result['ready'] ? 'rewrite_ready' : 'rewrite_needs_review', [
                'hard' => [], 'soft' => $result['soft'], 'repaired' => $rejectedFor !== [], 'ready' => $result['ready'],
            ]);
        }

        try {
            $article = $this->articles->create((int) $business->customer_id, $business, [
                'title' => $candidate->title,
                'seo_title' => $candidate->seo_title,
                'excerpt' => $candidate->excerpt,
                'meta_description' => $candidate->meta_description,
                'body' => $candidate->body,
                'primary_topic' => $candidate->primary_topic,
                'search_intent' => $candidate->search_intent,
                'supports_page_uid' => $candidate->supports_page_uid,
                'opportunity_key' => $decision->opportunity_key,
                'source' => 'autopilot',
                'ai_generated' => true,
            ]);
        } catch (ArticleException) {
            return $this->finish($decision, Decision::STATE_HELD, 'no_website');
        }

        $decision->forceFill(['article_id' => $article->id])->save();

        return $this->finish($decision, Decision::STATE_AWAITING_APPROVAL, $result['ready'] ? 'drafted_ready' : 'drafted_needs_review', [
            'hard' => [], 'soft' => $result['soft'], 'repaired' => $rejectedFor !== [], 'ready' => $result['ready'],
        ]);
    }

    /** Move briefed / deferred decisions to `drafting` exactly once; anything else (a second worker, a finished one) is a no-op. */
    private function claim(Decision $decision): bool
    {
        return DB::transaction(function () use ($decision) {
            $locked = Decision::query()->whereKey($decision->id)->lockForUpdate()->first();

            // A create decision is done once it has its article; a maintenance rewrite always has one (the article it updates).
            if ($locked === null || ! in_array($locked->state, [Decision::STATE_BRIEFED, Decision::STATE_DEFERRED_BUDGET], true) || ($locked->kind === Decision::KIND_CREATE && $locked->article_id !== null)) {
                return false;
            }

            $locked->forceFill(['state' => Decision::STATE_DRAFTING])->save();

            return true;
        });
    }

    private function finish(Decision $decision, string $state, string $reason, ?array $validation = null): Decision
    {
        $decision->forceFill(array_filter([
            'state' => $state,
            'reason_code' => $reason,
            'validation' => $validation,
            'resolved_at' => in_array($state, [Decision::STATE_REJECTED, Decision::STATE_HELD], true) ? now() : null,
        ], fn ($v) => $v !== null) + ['state' => $state])->save();

        return $decision->fresh();
    }

    private function addCost(Decision $decision, int $microusd): void
    {
        if ($microusd > 0) {
            $decision->forceFill(['cost_microusd' => (int) $decision->cost_microusd + $microusd])->save();
        }
    }

    /** Would one article's worst case still fit under the Business's ceiling this period? Checked before any call. */
    private function canAffordOneArticle(Business $business): bool
    {
        $worstCase = (int) $this->router->config(AiModelRoute::ContentWriter)['max_request_cost_microusd'];
        $period = $this->policies->resolveFor($business->workspace)->periodKey;

        return $this->ceiling->allows($business, AiUsageCategory::ContentAutopilot, $period, $worstCase);
    }

    /** @return array<string, true> */
    private function allowedLinks(array $brief): array
    {
        $allowed = [];

        foreach ((array) ($brief['links'] ?? []) as $link) {
            $allowed[$link['type'] . ':' . strtolower((string) $link['uid'])] = true;
        }

        return $allowed;
    }

    /** The existing article as it would read after the rewrite (unsaved; same id, so the overlap check ignores the article itself). */
    private function rewriteCandidate(WebsiteArticle $existing, array $parsed, string $body): WebsiteArticle
    {
        $candidate = clone $existing->effective();
        $candidate->forceFill([
            'excerpt' => Str::limit(trim((string) ($parsed['excerpt'] ?? '')), 400, ''),
            'meta_description' => $this->drafts->metaDescription((string) ($parsed['meta_description'] ?? '')) ?? $existing->meta_description,
            'body' => $body,
        ]);

        return $candidate;
    }

    /** An UNSAVED article, shaped exactly as it would be stored, so the validator can judge it before anything is created. */
    private function candidate(Website $website, array $brief, array $parsed, string $body): WebsiteArticle
    {
        $title = (string) $brief['topic'];
        $seoTitle = trim((string) ($parsed['title'] ?? ''));

        $article = new WebsiteArticle();
        $article->forceFill([
            'business_id' => $website->business_id,
            'website_id' => $website->id,
            'title' => $title,
            'slug' => ArticleSlugger::unique($website->id, $title),
            'seo_title' => $seoTitle !== '' && $seoTitle !== $title && mb_strlen($seoTitle) <= 70 ? $seoTitle : null,
            'excerpt' => Str::limit(trim((string) ($parsed['excerpt'] ?? '')), 400, ''),
            'meta_description' => $this->drafts->metaDescription((string) ($parsed['meta_description'] ?? '')),
            'body' => $body,
            'primary_topic' => (string) $brief['primary_topic'],
            'search_intent' => (string) $brief['intent'],
            'supports_page_uid' => $brief['supports']['uid'] ?? null,
            'status' => ArticleStatus::Draft,
            'noindex' => false,
        ]);

        return $article;
    }
}
