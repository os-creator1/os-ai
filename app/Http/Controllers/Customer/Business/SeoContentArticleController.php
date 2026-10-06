<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Seo\ArticleIntent;
use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleDraftException;
use App\Exceptions\Seo\ArticleException;
use App\Exceptions\Seo\SeoKeywordException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoContent;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\Content\ArticleAnalyzer;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleDraftGenerator;
use App\Library\Seo\Content\ArticleInternalLinkSuggester;
use App\Library\Seo\Content\ArticleManager;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Website\Blog\WebsiteBlogRenderer;
use App\Library\Website\Media\WebsiteMediaPayload;
use App\Models\Business;
use App\Models\WebsiteArticle;
use App\Models\WebsiteAsset;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SEO Content Engine V1 — the article editor and every lifecycle action.
 *
 * Thin by design: every write is ArticleManager (the one write authority), every check is
 * ArticleAnalyzer / ArticleCannibalizationGuard, the preview is the Website's own renderer. This class
 * only authorizes, validates input SHAPE, maps ArticleException to a friendly message and redirects.
 *
 * Chain on every action: Workspace → Business → userCanAccessBusiness() → active Business → entitlement
 * → capability (`view_seo` to read, `manage_seo` to write). Tenancy and entitlement failures are 404
 * (never 403); an article uid that belongs to another Business is a 404 too. The AI is reachable from
 * exactly one action, opportunitiesDraft(), and only through an explicit owner POST; it creates a DRAFT
 * and nothing here ever publishes on its own.
 */
class SeoContentArticleController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;
    use ResolvesSeoContent;

    public function __construct(
        private readonly ArticleManager $articles,
        private readonly ArticleAnalyzer $analyzer,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleInternalLinkSuggester $links,
        private readonly ArticleOpportunityEngine $opportunities,
    ) {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function create(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        if ($this->websiteOf($business) === null) {
            return $this->noWebsite($workspaceUid, $businessUid, $business);
        }

        return view('customer.business.seo.content.editor', $this->editorData($workspaceUid, $businessUid, $business, null)
            + ['hasContentModule' => $this->hasContentModule($workspace, $business)]);
    }

    public function edit(string $workspaceUid, string $businessUid, string $articleUid): View
    {
        [$workspace, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);

        return view('customer.business.seo.content.editor', $this->editorData($workspaceUid, $businessUid, $business, $article)
            + ['hasContentModule' => $this->hasContentModule($workspace, $business)]);
    }

    /** The saved article rendered by the Website's own renderer, exactly as visitors would see it. */
    public function preview(string $workspaceUid, string $businessUid, string $articleUid): Response
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $article = $this->articleOr404($business, $articleUid);
        $website = $this->websiteOf($business);

        abort_if($website === null, 404);

        return app(WebsiteBlogRenderer::class)->renderArticlePreview($website, $article);
    }

    /** The transparent checklist for the SAVED article, as JSON (the editor's "Re-check" button). */
    public function analysis(string $workspaceUid, string $businessUid, string $articleUid): JsonResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $article = $this->articleOr404($business, $articleUid);

        return response()->json($this->analysisFor($business, $article));
    }

    /** A safe, display-only Markdown preview of the text being typed. Nothing is stored. */
    public function markdownPreview(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $body = (string) $request->input('body', '');

        return response()->json([
            'html' => (string) ArticleMarkdown::toHtml(mb_substr($body, 0, 200000), fn () => '#'),
        ]);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $this->validated($request);

        try {
            $article = $this->articles->create((int) Auth::id(), $business, $input);
        } catch (ArticleException $e) {
            return $this->refused($e, route('customer.workspaces.businesses.seo.content.articles.create', [$workspaceUid, $businessUid]), true);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, 'Draft saved. It is not visible to anyone until you publish it.');
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);
        $input = $this->validated($request);

        try {
            $article = $this->articles->update((int) Auth::id(), $business, $article, $input);
        } catch (ArticleException $e) {
            return $this->refused($e, $this->editUrl($workspaceUid, $businessUid, $article), true);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, $article->isPublished()
            ? 'Saved. Changes to a published article are live straight away.'
            : 'Saved.');
    }

    public function publish(Request $request, string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);
        $actorId = (int) Auth::id();
        $input = $request->has('title') ? $this->validated($request) : null;

        try {
            if ($input !== null) {
                $article = $this->articles->update($actorId, $business, $article, $input);
            }

            $article = $this->articles->publish($actorId, $business, $article, $request->boolean('acknowledge_overlap'));
        } catch (ArticleException $e) {
            return $this->refused($e, $this->editUrl($workspaceUid, $businessUid, $article), $input !== null);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, 'Published. Your article is on your website.');
    }

    public function schedule(Request $request, string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);
        $actorId = (int) Auth::id();
        $input = $request->has('title') ? $this->validated($request) : null;
        $timezone = $this->timezoneOf($business);

        $request->validate(['scheduled_at_local' => ['required', 'date_format:Y-m-d\TH:i']], [
            'scheduled_at_local.required' => 'Choose the date and time to publish.',
            'scheduled_at_local.date_format' => 'Choose the date and time to publish.',
        ]);

        $at = Carbon::createFromFormat('Y-m-d\TH:i', (string) $request->input('scheduled_at_local'), $timezone)->utc();

        try {
            if ($input !== null) {
                $article = $this->articles->update($actorId, $business, $article, $input);
            }

            $article = $this->articles->schedule($actorId, $business, $article, $at, $request->boolean('acknowledge_overlap'));
        } catch (ArticleException $e) {
            return $this->refused($e, $this->editUrl($workspaceUid, $businessUid, $article), $input !== null);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, 'Scheduled for ' . $at->copy()->setTimezone($timezone)->format('M j, Y g:i A') . ' (' . $timezone . ').');
    }

    /** Scheduled back to Draft. */
    public function draft(string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        return $this->lifecycle($workspaceUid, $businessUid, $articleUid, 'Back to draft. Nothing will publish automatically.',
            fn (int $actor, Business $business, WebsiteArticle $article) => $this->articles->backToDraft($actor, $business, $article));
    }

    public function archive(string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        return $this->lifecycle($workspaceUid, $businessUid, $articleUid, 'Archived. It is no longer on your website or in search, and its address stays reserved.',
            fn (int $actor, Business $business, WebsiteArticle $article) => $this->articles->archive($actor, $business, $article));
    }

    /** Archived back to Draft. */
    public function restore(string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        return $this->lifecycle($workspaceUid, $businessUid, $articleUid, 'Restored as a draft. Publish it again when you are ready.',
            fn (int $actor, Business $business, WebsiteArticle $article) => $this->articles->backToDraft($actor, $business, $article));
    }

    public function reviewed(Request $request, string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);

        try {
            $article = $this->articles->markReviewed($business, $article);
        } catch (ArticleException $e) {
            return $this->refused($e, $this->editUrl($workspaceUid, $businessUid, $article), false);
        }

        $back = $request->input('return') === 'plan'
            ? route('customer.workspaces.businesses.seo.content.plan', [$workspaceUid, $businessUid])
            : $this->editUrl($workspaceUid, $businessUid, $article);

        return redirect($back)->with('status', 'success')->with('message', 'Marked as reviewed.');
    }

    /**
     * "Track this topic": adds the article's primary topic as an ordinary SEO keyword. It never starts
     * rank tracking and never takes a rank slot — the owner chooses that on the Keywords page.
     */
    public function track(string $workspaceUid, string $businessUid, string $articleUid): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);
        $topic = trim((string) $article->primary_topic);

        if ($topic === '') {
            return redirect($this->editUrl($workspaceUid, $businessUid, $article))
                ->with('status', 'error')->with('message', 'Add a target topic to this article first, then you can track it.');
        }

        try {
            app(SeoKeywordManager::class)->create((int) Auth::id(), $business, $topic);
        } catch (SeoKeywordException $e) {
            abort_if($e->reason === SeoKeywordException::ACCESS_DENIED, 404);

            $message = $e->reason === SeoKeywordException::DUPLICATE
                ? '"' . $topic . '" is already one of your keywords. You can turn on rank tracking for it here.'
                : $e->customerMessage();

            $status = $e->reason === SeoKeywordException::DUPLICATE ? 'info' : 'error';

            return redirect()->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
                ->with('status', $status)->with('message', $message);
        }

        return redirect()->route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid])
            ->with('status', 'success')
            ->with('message', '"' . $topic . '" was added to your keywords. Rank tracking is off until you turn it on here, within your plan\'s limits.');
    }

    // -----------------------------------------------------------------
    // From an opportunity (SeoModule)
    // -----------------------------------------------------------------

    /** The ONE action that may call the AI: an explicit owner POST that creates a DRAFT and nothing more. */
    public function opportunityDraft(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $opportunity = $this->opportunityFrom($request, $business);

        if (($existing = $this->existingArticleFor($business, $opportunity)) !== null) {
            return $this->toEditor($workspaceUid, $businessUid, $existing, 'You already have an article for this topic. Here it is.');
        }

        try {
            $article = app(ArticleDraftGenerator::class)->generate($business, (int) Auth::id(), $opportunity);
        } catch (ArticleDraftException $e) {
            return redirect()->route('customer.workspaces.businesses.seo.content.opportunities', [$workspaceUid, $businessUid])
                ->with('status', 'error')->with('message', $e->customerMessage());
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, 'AI draft created. Read it, correct anything that is not true for your business, then publish when you are happy. Nothing is published automatically.');
    }

    /** "Write it myself": an empty draft pre-filled with the opportunity's topic, intent and supported page. */
    public function opportunityStart(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $opportunity = $this->opportunityFrom($request, $business);

        if (($existing = $this->existingArticleFor($business, $opportunity)) !== null) {
            return $this->toEditor($workspaceUid, $businessUid, $existing, 'You already have an article for this topic. Here it is.');
        }

        try {
            $article = $this->articles->create((int) Auth::id(), $business, [
                'title' => (string) $opportunity['title'],
                'primary_topic' => (string) $opportunity['primary_topic'],
                'search_intent' => $opportunity['search_intent'] ?? null,
                'supports_page_uid' => $opportunity['supports_page_uid'] ?? null,
                'opportunity_key' => (string) $opportunity['key'],
                'source' => 'opportunity',
            ]);
        } catch (ArticleException $e) {
            return $this->refused($e, route('customer.workspaces.businesses.seo.content.opportunities', [$workspaceUid, $businessUid]), false);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, 'Draft started from this topic. Write it in your own words.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  callable(int, Business, WebsiteArticle): WebsiteArticle  $apply
     */
    private function lifecycle(string $workspaceUid, string $businessUid, string $articleUid, string $done, callable $apply): RedirectResponse
    {
        [, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $article = $this->articleOr404($business, $articleUid);

        try {
            $article = $apply((int) Auth::id(), $business, $article);
        } catch (ArticleException $e) {
            return $this->refused($e, $this->editUrl($workspaceUid, $businessUid, $article), false);
        }

        return $this->toEditor($workspaceUid, $businessUid, $article, $done);
    }

    /**
     * Input SHAPE only, validated after tenancy, entitlement and capability — authority is ArticleManager's.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:80'],
            'excerpt' => ['nullable', 'string', 'max:400'],
            'body' => ['nullable', 'string', 'max:200000'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'primary_topic' => ['nullable', 'string', 'max:190'],
            'search_intent' => ['nullable', Rule::enum(ArticleIntent::class)],
            'supports_page_uid' => ['nullable', 'string', 'max:64'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'noindex' => ['nullable', 'boolean'],
            'featured_asset_uid' => ['nullable', 'string', 'max:64'],
        ], [
            'title.required' => 'Give the article a title.',
        ]);

        $input['noindex'] = (bool) ($input['noindex'] ?? false);
        $input['featured_asset_uid'] = $input['featured_asset_uid'] ?? null;

        return $input;
    }

    /** @return array<string, mixed> */
    private function opportunityFrom(Request $request, Business $business): array
    {
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:120']])['key'];
        $opportunity = $this->opportunities->find($business, $key);

        abort_if($opportunity === null, 404);

        return $opportunity;
    }

    /** @param array<string, mixed> $opportunity */
    private function existingArticleFor(Business $business, array $opportunity): ?WebsiteArticle
    {
        $uid = $opportunity['article_uid'] ?? null;

        return is_string($uid) && $uid !== '' ? $this->articles->find($business, $uid) : null;
    }

    /** @return array<string, mixed> */
    private function editorData(string $workspaceUid, string $businessUid, Business $business, ?WebsiteArticle $article): array
    {
        $website = $this->websiteOf($business);
        $pages = $this->inventory->pages($business);

        $assets = $website === null ? collect() : WebsiteAsset::query()
            ->where('website_id', $website->id)
            ->orderBy('sort_order')->orderBy('id')
            ->limit(120)
            ->get()
            ->reject(fn (WebsiteAsset $a) => $a->purpose?->value === 'logo')
            ->values();

        $suggestions = $this->links->suggest(
            $business,
            $article,
            $article?->supports_page_uid,
            $article?->primary_topic,
        );

        $analysis = null;

        if ($article !== null) {
            $analysis = $this->analysisFor($business, $article);
        }

        $timezone = $this->timezoneOf($business);

        return [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'article' => $article,
            'pages' => $pages,
            'assets' => $assets->map(fn (WebsiteAsset $a) => [
                'uid' => $a->uid,
                'thumb' => WebsiteMediaPayload::thumbUrl($a),
                'alt' => trim((string) $a->alt_text),
                'title' => trim((string) ($a->title ?: '')),
            ])->all(),
            'featuredUid' => $article?->featuredAsset?->uid,
            'suggestions' => $suggestions,
            'analysis' => $analysis,
            'intents' => ArticleIntent::cases(),
            'timezone' => $timezone,
            'scheduledLocal' => $article?->scheduled_at?->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
        ];
    }

    /** @return array<string, mixed> */
    private function analysisFor(Business $business, WebsiteArticle $article): array
    {
        $analysis = $this->analyzer->analyze($business, $article);
        $overlap = $this->guard->check($business, array_filter([$article->title, $article->primary_topic]), (int) $article->id);

        return [
            'checks' => $analysis['checks'],
            'overall' => $analysis['overall'],
            'blockers' => $this->analyzer->blockers($business, $article),
            'indexable' => $this->analyzer->indexability($article)[0],
            'overlap' => [
                'strong' => $overlap->strongFindings(),
                'related' => $overlap->related(),
            ],
        ];
    }

    private function timezoneOf(Business $business): string
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function editUrl(string $workspaceUid, string $businessUid, WebsiteArticle $article): string
    {
        return route('customer.workspaces.businesses.seo.content.articles.edit', [$workspaceUid, $businessUid, $article->uid]);
    }

    private function toEditor(string $workspaceUid, string $businessUid, WebsiteArticle $article, string $message): RedirectResponse
    {
        return redirect($this->editUrl($workspaceUid, $businessUid, $article))
            ->with('status', 'success')
            ->with('message', $message);
    }

    /** NO_WEBSITE and NOT_FOUND are friendly states/404s, everything else is a message back on the editor. */
    private function refused(ArticleException $e, string $url, bool $withInput): RedirectResponse
    {
        abort_if($e->reason === ArticleException::NOT_FOUND, 404);

        $redirect = redirect($url);

        if ($withInput) {
            $redirect = $redirect->withInput(request()->except(['_token']));
        }

        return $redirect->with('status', 'error')->with('message', $e->customerMessage());
    }
}
