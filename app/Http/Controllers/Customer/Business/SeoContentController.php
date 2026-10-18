<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Seo\ArticleStatus;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoContent;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\Content\ArticleContentPlan;
use App\Library\Seo\Content\ArticleFreshness;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Library\Seo\Content\ArticleRankSignals;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Models\Business;
use App\Library\Seo\SeoPhraseNormalizer;
use App\Models\SeoKeyword;
use App\Models\WebsiteArticle;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * SEO Content Engine V1 — the READ side of SEO → Content: Content Plan, Articles list, Opportunities.
 *
 * Every action runs Workspace → Business → userCanAccessBusiness() → active Business → entitlement →
 * `view_seo`, with 404 for every tenancy or entitlement failure (ResolvesSeoContent). The Content area
 * itself rides SeoBasicVisibility; Opportunities and the Content Plan clusters ride SeoModule, so a Core
 * Business gets a 404 on Opportunities and an honest Articles page (with a note) on the Content landing.
 *
 * Nothing here writes, and nothing here calls an AI or a provider: every list is computed
 * deterministically from the Business's own rows and its published Website snapshot.
 */
class SeoContentController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;
    use ResolvesSeoContent;

    public const PER_PAGE = 20;

    public function __construct(
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleOpportunityEngine $opportunities,
        private readonly ArticleContentPlan $plan,
        private readonly ArticleFreshness $freshness,
        private readonly ArticleRankSignals $rankSignals,
    ) {
    }

    /** `…/seo/content` — the Content Plan, or (no SeoModule) the Articles list with an honest note. */
    public function plan(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        if ($this->websiteOf($business) === null) {
            return $this->noWebsite($workspaceUid, $businessUid, $business);
        }

        if (! $this->hasContentModule($workspace, $business)) {
            return $this->articlesView($request, $workspaceUid, $businessUid, $business, false);
        }

        $opportunities = collect($this->opportunities->forBusiness($business))->keyBy('key');
        $plan = $this->plan->forBusiness($business);

        return view('customer.business.seo.content.plan', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'plan' => $plan,
            'opportunities' => $opportunities,
            'needsReview' => $this->freshness->forBusiness($business),
            'rankSignals' => $this->rankSignals->forBusiness($business),
        ]);
    }

    public function articles(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        if ($this->websiteOf($business) === null) {
            return $this->noWebsite($workspaceUid, $businessUid, $business);
        }

        return $this->articlesView($request, $workspaceUid, $businessUid, $business, $this->hasContentModule($workspace, $business));
    }

    public function opportunities(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        if ($this->websiteOf($business) === null) {
            return $this->noWebsite($workspaceUid, $businessUid, $business);
        }

        return view('customer.business.seo.content.opportunities', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'opportunities' => $this->opportunities->forBusiness($business),
        ]);
    }

    /**
     * The tracked search keyword each listed article supports, by article uid. The relation is the canonical one: "Track this
     * topic" creates a keyword from the article's primary topic, so an article supports the Business's active keyword whose
     * normalized phrase equals its normalized topic. No match (or no topic) means no entry - nothing is inferred beyond that.
     *
     * @param  \Illuminate\Support\Collection<int, WebsiteArticle>  $articles
     * @return array<string, string>
     */
    private function keywordsFor(Business $business, \Illuminate\Support\Collection $articles): array
    {
        $normalized = $articles
            ->mapWithKeys(fn (WebsiteArticle $a) => [$a->uid => SeoPhraseNormalizer::normalize(trim((string) $a->primary_topic))])
            ->filter(fn (string $n) => $n !== '');

        if ($normalized->isEmpty()) {
            return [];
        }

        $phrases = SeoKeyword::query()->active()->where('business_id', $business->id)
            ->whereIn('phrase_normalized', $normalized->unique()->values()->all())
            ->pluck('phrase', 'phrase_normalized');

        return $normalized->map(fn (string $n) => $phrases[$n] ?? null)->filter()->all();
    }

    private function articlesView(Request $request, string $workspaceUid, string $businessUid, Business $business, bool $withOpportunities): View
    {
        $status = ArticleStatus::tryFrom((string) $request->query('status', ''));
        $search = trim((string) $request->query('q', ''));

        $base = WebsiteArticle::query()->where('business_id', $business->id);

        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        $articles = (clone $base)
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $like = '%' . addcslashes(mb_substr($search, 0, 120), '%_\\') . '%';
                $q->where('title', 'like', $like)->orWhere('primary_topic', 'like', $like);
            }))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->appends($request->except(["page", "fragment"]));

        $recommended = $withOpportunities && (int) array_sum($counts) === 0
            ? array_slice(array_values(array_filter(
                $this->opportunities->forBusiness($business),
                fn (array $o) => ($o['status'] ?? null) === 'not_covered',
            )), 0, 3)
            : [];

        return view('customer.business.seo.content.articles', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'articles' => $articles,
            'counts' => $counts,
            'total' => (int) array_sum($counts),
            'status' => $status,
            'search' => $search,
            'pages' => collect($this->inventory->pages($business))->keyBy('uid'),
            'keywords' => $this->keywordsFor($business, $articles->getCollection()),
            'website' => $this->websiteOf($business),
            'withOpportunities' => $withOpportunities,
            'recommended' => $recommended,
        ]);
    }
}
