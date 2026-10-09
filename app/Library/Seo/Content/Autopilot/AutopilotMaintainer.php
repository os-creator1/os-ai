<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Jobs\Seo\WriteAutopilotArticleJob;
use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiBusinessCategoryCeiling;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleFreshness;
use App\Library\Seo\Content\ArticleInternalLinkSuggester;
use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Seo\Content\ArticleRankSignals;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\WebsiteArticle;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - weekly maintenance of the articles that already exist. Reads ONLY what the platform already holds
 * (cached rank observations, the catalog, the published site) - no Search Console, no new rank or provider job - and starts
 * from "leave it alone": most reviews change nothing and cost nothing.
 *
 * One outcome per article per review, in this order:
 *   rewrite    an Autopilot article whose package or price changed (or that is stale AND losing ground) is rewritten ONCE
 *              from the same fact-grounded brief - the only AI spend here, capped per Business per month and per article, and
 *              gated by the Business's budget. It waits as the article's pending draft;
 *   propose    two articles that answer the same question (consolidate), or one that is long out of date with its page gone
 *              (archive): a SUGGESTION for the owner only. Nothing is ever merged or archived automatically;
 *   links      an Autopilot article that links to little gets a short "Related reading" list of real, published pages and
 *              articles - deterministic and free, again as a pending draft;
 *   leave      nothing to do: recorded, so it is not looked at again for `maintenance_review_days`.
 *
 * Only Autopilot's own articles are ever edited. An article the owner wrote is never rewritten or re-linked - at most it is the
 * subject of a suggestion. A pending draft is applied through the existing "Publish update" path, and only when every rule that
 * lets a new article publish itself also allows it (AutopilotPublisher::applyUpdateIfAllowed); otherwise it waits for the owner.
 */
final class AutopilotMaintainer
{
    public const OUTCOME_REWRITE = 'rewrite';
    public const OUTCOME_CONSOLIDATE = 'consolidate';
    public const OUTCOME_ARCHIVE = 'archive';
    public const OUTCOME_LINKS = 'links';
    public const OUTCOME_LEAVE = 'leave';

    private const MIN_LINKED = 3;
    private const MAX_NEW_LINKS = 3;
    private const BODY_CHARS = 9000;

    public function __construct(
        private readonly AutopilotRunner $runner,
        private readonly ArticleFreshness $freshness,
        private readonly ArticleRankSignals $rank,
        private readonly ArticleInternalLinkSuggester $links,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleManager $articles,
        private readonly ArticleBriefBuilder $briefs,
        private readonly ContentFactPack $facts,
        private readonly AutopilotPublisher $publisher,
        private readonly AiBusinessCategoryCeiling $ceiling,
        private readonly AiBudgetPolicyResolver $policies,
        private readonly AiModelRouter $router,
    ) {
    }

    /**
     * @return array{ran: bool, skipped: ?string, actions: list<string>}
     */
    public function run(Business $business, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        if (($why = $this->runner->gate($business)) !== null) {
            return ['ran' => false, 'skipped' => $why, 'actions' => []];
        }

        $actions = [];
        $actions = array_merge($actions, $this->applyWaitingUpdates($business));

        $drift = collect($this->freshness->forBusiness($business, $now))->keyBy('article_uid');
        $rank = collect($this->rank->forBusiness($business))->keyBy('article_uid');
        $recent = $this->coolingDown($business, $now);
        $pack = null;
        $reviewed = 0;

        $articles = WebsiteArticle::query()->where('business_id', $business->id)->published()->orderBy('id')->limit(500)->get();

        foreach ($articles as $article) {
            if ($reviewed >= max(1, (int) config('seo.content_autopilot.maintenance_max_reviews_per_run', 25))) {
                break;
            }

            if (isset($recent['article:' . $article->uid])) {
                continue;
            }

            $reviewed++;
            $pack ??= $this->facts->forBusiness($business);
            $outcome = $this->review($business, $article, $drift->get((string) $article->uid), $rank->get((string) $article->uid), $pack, $now);
            $actions[] = $outcome . ':' . $article->uid;
        }

        return ['ran' => true, 'skipped' => null, 'actions' => $actions];
    }

    /** @return list<string> */
    private function applyWaitingUpdates(Business $business): array
    {
        $actions = [];

        Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_MAINTAIN)->where('decision', Decision::DECISION_UPDATE)
            ->where('state', Decision::STATE_AWAITING_APPROVAL)->whereNotNull('article_id')->get()
            ->each(function (Decision $decision) use (&$actions) {
                $article = $decision->article;

                // The owner already applied or discarded it themselves: nothing left to do.
                if ($article === null || ! $article->hasPendingDraft()) {
                    $decision->forceFill(['state' => Decision::STATE_RESOLVED, 'resolved_at' => now()])->save();

                    return;
                }

                $wait = $this->publisher->applyUpdateIfAllowed($decision);
                $actions[] = $wait === null ? 'applied:' . $decision->uid : 'waiting:' . $decision->uid . ':' . $wait;
            });

        return $actions;
    }

    private function review(Business $business, WebsiteArticle $article, ?array $drift, ?array $rankSignal, array $pack, CarbonInterface $now): string
    {
        $own = $article->source === 'autopilot';

        $rewriteWanted = $own && $this->wantsRewrite($article, $drift, $rankSignal);

        if ($rewriteWanted && $this->mayRewrite($business, $article, $now)) {
            return $this->startRewrite($business, $article, $drift, $rankSignal, $pack, $now);
        }

        if (($duplicate = $this->duplicateOf($business, $article)) !== null) {
            return $this->propose($business, $article, self::OUTCOME_CONSOLIDATE, 'This article and "' . $duplicate . '" answer the same question. Consider keeping one of them.', $now);
        }

        if ($this->isArchiveCandidate($article, $drift, $now)) {
            return $this->propose($business, $article, self::OUTCOME_ARCHIVE, 'This article is more than ' . (int) config('seo.content_autopilot.maintenance_archive_after_days', 540) . ' days old and the page it supports has gone or is hidden. Consider archiving it.', $now);
        }

        if ($own && ! $article->hasPendingDraft() && ($added = $this->addRelatedLinks($business, $article, $now)) !== null) {
            return $added;
        }

        // A rewrite that is wanted but not allowed yet (this month's cap, the budget, a recent rewrite) is NOT recorded as
        // "reviewed": the article is looked at again next week instead of being parked for two months.
        if ($rewriteWanted) {
            return 'wait';
        }

        $this->record($business, $article, Decision::DECISION_NONE, Decision::STATE_RESOLVED, 'reviewed_unchanged', $now);

        return self::OUTCOME_LEAVE;
    }

    // -------------------------------------------------------------- rewrite

    private function wantsRewrite(WebsiteArticle $article, ?array $drift, ?array $rankSignal): bool
    {
        // Facts the article states (a package or price) changed - or it is stale AND losing ground. Age alone never rewrites.
        return in_array(ArticleFreshness::REASON_CATALOG_CHANGED, (array) ($drift['reasons'] ?? []), true)
            || ($rankSignal['kind'] ?? null) === ArticleRankSignals::KIND_STALE_AND_DECLINED;
    }

    private function mayRewrite(Business $business, WebsiteArticle $article, CarbonInterface $now): bool
    {
        if ($article->hasPendingDraft()) {
            return false;
        }

        $key = 'article:' . $article->uid;
        $recentRewrite = Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_MAINTAIN)->where('decision', Decision::DECISION_UPDATE)
            ->where('opportunity_key', $key)->where('evaluated_at', '>', $now->copy()->subDays((int) config('seo.content_autopilot.maintenance_rewrite_days', 90)))->exists();

        $thisMonth = Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_MAINTAIN)->where('decision', Decision::DECISION_UPDATE)
            ->where('period_key', $now->copy()->utc()->format('Y-m'))->whereNotIn('state', [Decision::STATE_HELD, Decision::STATE_REJECTED])->count();

        if ($recentRewrite || $thisMonth >= max(0, (int) config('seo.content_autopilot.maintenance_max_rewrites_per_month', 1))) {
            return false;
        }

        // The Business's remaining budget must cover one rewrite BEFORE we start - otherwise it is simply not started.
        $worstCase = (int) $this->router->config(AiModelRoute::ContentWriter)['max_request_cost_microusd'];

        return $this->ceiling->allows($business, AiUsageCategory::ContentAutopilot, $this->policies->resolveFor($business->workspace)->periodKey, $worstCase);
    }

    private function startRewrite(Business $business, WebsiteArticle $article, ?array $drift, ?array $rankSignal, array $pack, CarbonInterface $now): string
    {
        $live = $article->effective();
        $pages = $this->inventory->pages($business);
        $supports = $live->supports_page_uid !== null ? $this->inventory->find($business, (string) $live->supports_page_uid) : null;
        $reasons = array_merge((array) ($drift['messages'] ?? []), $rankSignal !== null ? [$rankSignal['message']] : []);

        $scored = [
            'title' => (string) $live->title,
            'intent' => (string) ($live->search_intent ?: 'guide'),
            'opportunity' => [
                'primary_topic' => (string) ($live->primary_topic ?: $live->title),
                'supports_page_uid' => $supports['uid'] ?? null,
                'supports_page_title' => $supports['title'] ?? null,
                'internal_links' => $this->links->suggest($business, $article, null, null),
            ],
        ];

        $brief = $this->briefs->build($business, $scored, $pack);
        $brief['rewrite'] = ['reasons' => array_values(array_filter($reasons)), 'current' => mb_substr((string) $live->body, 0, self::BODY_CHARS)];
        unset($brief['brief_hash']);
        $brief['brief_hash'] = \App\Library\NicheBlueprint\Workspace\BlueprintChecksum::of($brief);

        $decision = Decision::query()->create([
            'business_id' => $business->id, 'kind' => Decision::KIND_MAINTAIN, 'decision' => Decision::DECISION_UPDATE, 'state' => Decision::STATE_BRIEFED,
            'opportunity_key' => 'article:' . $article->uid, 'reason_code' => 'rewrite_' . ($drift !== null ? 'facts_changed' : 'stale_declining'),
            'brief' => $brief, 'brief_hash' => $brief['brief_hash'], 'fact_hash' => $pack['fact_hash'], 'article_id' => $article->id,
            'period_key' => $now->copy()->utc()->format('Y-m'), 'evaluated_at' => $now,
        ]);

        WriteAutopilotArticleJob::dispatch($decision->id);

        return self::OUTCOME_REWRITE;
    }

    // -------------------------------------------------------------- proposals

    /** The title of ANOTHER live article that is a strong duplicate of this one, if any (only the older of a pair proposes). */
    private function duplicateOf(Business $business, WebsiteArticle $article): ?string
    {
        $findings = $this->guard->check($business, array_filter([(string) $article->title, (string) $article->primary_topic]), (int) $article->id)->strongFindings();

        foreach ($findings as $finding) {
            if ($finding['type'] !== 'article') {
                continue;
            }

            $other = WebsiteArticle::query()->where('business_id', $business->id)->where('uid', $finding['uid'])->published()->first();

            if ($other !== null && $other->id < $article->id) {
                return (string) $other->title;
            }
        }

        return null;
    }

    private function isArchiveCandidate(WebsiteArticle $article, ?array $drift, CarbonInterface $now): bool
    {
        $old = $article->dateModified()->lessThan($now->copy()->subDays((int) config('seo.content_autopilot.maintenance_archive_after_days', 540)));

        return $old && in_array(ArticleFreshness::REASON_SUPPORTED_PAGE_CHANGED, (array) ($drift['reasons'] ?? []), true);
    }

    private function propose(Business $business, WebsiteArticle $article, string $kind, string $message, CarbonInterface $now): string
    {
        $open = Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_MAINTAIN)->where('decision', 'propose')
            ->where('opportunity_key', 'article:' . $article->uid)->where('state', Decision::STATE_AWAITING_APPROVAL)->exists();

        if (! $open) {
            $this->record($business, $article, 'propose', Decision::STATE_AWAITING_APPROVAL, $kind, $now, ['message' => $message]);
        }

        return $kind;
    }

    // -------------------------------------------------------------- links

    private function addRelatedLinks(Business $business, WebsiteArticle $article, CarbonInterface $now): ?string
    {
        $body = (string) $article->body;
        $have = array_map(fn (array $r) => $r['type'] . ':' . $r['uid'], ArticleMarkdown::internalRefs($body));

        if (count(array_unique($have)) >= self::MIN_LINKED) {
            return null;
        }

        $missing = array_slice(array_values(array_filter(
            $this->links->suggest($business, $article, null, null),
            fn (array $l) => ! in_array($l['type'] . ':' . strtolower((string) $l['uid']), $have, true),
        )), 0, self::MAX_NEW_LINKS);

        if ($missing === []) {
            return null;
        }

        $list = implode("\n", array_map(fn (array $l) => '- [' . $l['anchor'] . '](' . $l['type'] . ':' . $l['uid'] . ')', $missing));
        $this->articles->update((int) $business->customer_id, $business, $article, ['body' => rtrim($body) . "\n\n## Related reading\n\n" . $list . "\n"]);

        $this->record($business, $article, Decision::DECISION_UPDATE, Decision::STATE_AWAITING_APPROVAL, 'links_added', $now, null, ['hard' => [], 'soft' => [], 'repaired' => false, 'ready' => true]);

        return self::OUTCOME_LINKS;
    }

    // -------------------------------------------------------------- records

    /** Keys reviewed recently enough that they are not looked at again yet, by outcome. */
    private function coolingDown(Business $business, CarbonInterface $now): array
    {
        $days = [
            Decision::DECISION_NONE => (int) config('seo.content_autopilot.maintenance_review_days', 60),
            Decision::DECISION_UPDATE => (int) config('seo.content_autopilot.maintenance_rewrite_days', 90),
            'propose' => (int) config('seo.content_autopilot.maintenance_proposal_days', 180),
        ];

        $cooling = [];

        Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_MAINTAIN)->where('opportunity_key', 'like', 'article:%')
            ->where('evaluated_at', '>', $now->copy()->subDays(max($days)))->get(['opportunity_key', 'decision', 'evaluated_at'])
            ->each(function (Decision $d) use ($days, $now, &$cooling) {
                if ($d->evaluated_at->greaterThan($now->copy()->subDays($days[$d->decision] ?? 60))) {
                    $cooling[$d->opportunity_key] = true;
                }
            });

        return $cooling;
    }

    private function record(Business $business, WebsiteArticle $article, string $decision, string $state, string $reason, CarbonInterface $now, ?array $needs = null, ?array $validation = null): Decision
    {
        return Decision::query()->create([
            'business_id' => $business->id, 'kind' => Decision::KIND_MAINTAIN, 'decision' => $decision, 'state' => $state,
            'opportunity_key' => 'article:' . $article->uid, 'reason_code' => $reason, 'needs_input' => $needs, 'validation' => $validation,
            'article_id' => $article->id, 'period_key' => $now->copy()->utc()->format('Y-m'), 'evaluated_at' => $now,
            'resolved_at' => $state === Decision::STATE_RESOLVED ? $now : null,
        ]);
    }
}
