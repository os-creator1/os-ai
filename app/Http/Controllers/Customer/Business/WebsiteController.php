<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Website\StoreWebsiteAssetRequest;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteAiDraftGenerator;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDesigns;
use App\Library\Website\WebsiteStarterDraftService;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\Setup\WizardPresentationAnswers;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsitePage;
use App\Models\WebsiteRevision;
use App\Models\WebsiteTemplate;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A — the Business-scoped
 * authenticated management surface (contract §2, §17, §31.1).
 *
 * Every action, without exception, runs the mandatory chain (§2.2):
 * Workspace by UID → Business inside that Workspace →
 * WorkspaceManager::userCanAccessBusiness() → Business-scoped
 * EntitlementManager::decide() for PlatformFeature::WebsiteGeneration →
 * the Website resolved scoped to that Business → any
 * page/revision/asset resolved scoped to that Website. A foreign
 * Workspace, foreign Business, or foreign Website/page/revision/asset
 * uid fails closed as 404 exactly like a nonexistent one. Auth::id()
 * appears only as the capability/audit actor for the always-fresh
 * mutation-path entitlement decision (§26.2) — never as tenant identity.
 *
 * All draft page field mutation is delegated to
 * WebsiteDraftPageService (contract §17.1) — this controller never
 * writes to website_pages directly.
 */
class WebsiteController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly WebsiteDraftPageService $draftPages,
        private readonly WebsiteAssetUploadService $assetUploads,
        private readonly WebsitePublisher $publisher,
        private readonly WebsiteAiDraftGenerator $aiGenerator,
        private readonly WebsiteStarterDraftService $starterDrafts,
        private readonly BusinessKnowledgeProfileManager $profiles,
        private readonly WebsitePageStrategy $pageStrategy,
        private readonly GuidedGenerationCommitService $guidedGeneration,
        private readonly QuestionnaireResolver $questionnaireResolver,
        private readonly \App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator $generationCoordinator,
        private readonly \App\Library\Website\Setup\WebsiteCreationStateResolver $creationState,
    ) {
    }

    /**
     * Bare /website entry/selector route (contract §32's nav entry
     * target). Never guesses a Business — mirrors
     * Business\MessagingChannelsController::entry() exactly.
     */
    public function entry(): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');

        $accessible = $this->accessibleBusinesses();

        if (count($accessible) === 0) {
            return view('customer.business.website.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid]);
        }

        return view('customer.business.website.entry', ['accessible' => $accessible]);
    }

    public function show(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $website = Website::where('business_id', $business->id)->first();

        if ($website === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup', [$workspaceUid, $businessUid]);
        }

        // Acceptance-correction Blocker 9 — "expose a clear missing-media
        // checklist": the most recent guided-generation attempt's own
        // warnings (MediaBindingService's real, deterministic findings —
        // e.g. "no uploaded photos", "not enough distinct photos for
        // every slot") surfaced directly on the dashboard, never a
        // fabricated or generic message.
        $latestAttempt = $website->guidedGenerationAttempts()->latest('id')->first();

        return view('customer.business.website.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'pageCount' => $website->pages()->count(),
            'mediaWarnings' => $latestAttempt?->warnings ?? [],
        ]);
    }

    public function setup(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        $completeness = $this->profiles->completenessCheck($business);
        $eligibleLocations = $this->pageStrategy->eligibleLocations($business);
        $needsMoreInfoLocations = $this->pageStrategy->locationsNeedingMoreInfo($business);

        return view('customer.business.website.setup', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'templates' => WebsiteTemplate::where('is_active', true)->orderBy('key')->get(),
            'designs' => WebsiteStarterDesigns::all(),
            'services' => $business->services()->where('status', 'active')->orderBy('sort_order')->limit(4)->get(),
            'location' => $business->primaryLocation()->first(),
            'reusable' => WebsiteStarterDraftService::isPhotoBooth($business) ? $this->starterDrafts->reusableContent($business) : null,
            // "Website completeness" (task instruction) — the real,
            // deterministic facts the page-strategy engine and the
            // About/FAQ builders actually consult, never a pretend list.
            // No `photoCount` here on purpose (acceptance-correction
            // Blocker 9's "NEW-WEBSITE MEDIA FLOW"): no Website row (and
            // therefore no WebsiteAsset rows at all) exists yet at this
            // screen, so there is no real count to compute — the view's
            // own static "add real photos after your site is created"
            // line states that honestly instead of faking a number.
            'completeness' => [
                'missingFieldKeys' => $completeness->missingFieldKeys,
                'staleFieldKeys' => $completeness->staleFieldKeys,
                'eligibleServiceCount' => $this->pageStrategy->eligibleServices($business)->count(),
                'eligibleCatalogCount' => $this->pageStrategy->eligibleCatalogItems($business)->count(),
                'eligibleLocationCount' => $eligibleLocations->count(),
                'needsMoreInfoLocationCount' => $needsMoreInfoLocations->count(),
            ],
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        $request->validate([
            'design' => 'nullable|in:clean,bold,premium,blank',
            'template_key' => 'nullable|string|max:40',
            'name' => 'nullable|string|max:120',
        ]);

        if (! $request->filled('design') && ! $request->filled('template_key') && ! $request->filled('name')) {
            throw ValidationException::withMessages(['design' => ['Choose a design to start your website.']]);
        }

        // The four-template picker (task instruction: "replace the
        // current generic Clean/Bold/Premium selection") — resolved
        // against the operator-owned, `is_active`-checked catalog. The
        // older `design` (clean/bold/premium/blank) path stays fully
        // functional and untouched for a business that still submits it
        // (e.g. the "Start with a blank website" link), never removed —
        // only no longer the primary path the setup screen itself offers.
        if ($request->filled('template_key')) {
            $template = WebsiteTemplate::where('key', $request->input('template_key'))
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                throw ValidationException::withMessages(['template_key' => ['Choose one of the available templates.']]);
            }

            $website = $this->starterDrafts->createFromTemplate($business, $template, $request->input('name'));
        } else {
            $design = $request->input('design', 'blank');
            $website = $this->starterDrafts->create($business, $design, $request->input('name'));
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $website->pages()->exists()
                ? 'Your starter draft is ready. Review the page and add your own photos before publishing.'
                : 'Website created. Add a homepage to get started.',
        ]);
    }

    public function pages(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        // A website with no pages has not been created yet — the Pages
        // management screen (checklist, Preview, Publish...) is for a
        // generated site only. Send the owner to the one creation journey.
        if (! $this->creationState->hasGeneratedPages($website)) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        $isPhotoBooth = WebsiteStarterDraftService::isPhotoBooth($business);

        // The exact Knowledge Profile facts the About/FAQ pages read
        // (WebsiteStarterDraftService::createAboutPage()/createFaqPage())
        // — never the full field set, so this count means "would make
        // THESE pages more complete", not "profile completeness" in
        // general (a different, already-existing surface's concern).
        $aboutFaqFieldKeys = [
            'years_operating', 'ideal_customers', 'differentiators',
            'credentials', 'warranties_guarantees', 'pricing_method', 'financing_available',
        ];
        $aboutFaqMissingCount = $isPhotoBooth
            ? count(array_intersect($this->profiles->completenessCheck($business, $website)->missingFieldKeys, $aboutFaqFieldKeys))
            : null;

        return view('customer.business.website.pages', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'pages' => $website->pages()->orderBy('sort_order')->orderBy('id')->get(),
            'isPhotoBooth' => $isPhotoBooth,
            'reusable' => $isPhotoBooth ? $this->starterDrafts->reusableContent($business) : null,
            'photoCount' => $isPhotoBooth ? $website->assets()->count() : null,
            'galleryPage' => $isPhotoBooth ? $website->pages()->where('slug', 'gallery')->first() : null,
            'quoteForm' => $isPhotoBooth ? $website->forms()->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)->first() : null,
            'aboutFaqMissingCount' => $aboutFaqMissingCount,
        ]);
    }

    public function createPage(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.page-form', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'page' => null,
            'assets' => $website->assets()->latest()->get(),
            'forms' => $website->forms()->get(),
        ]);
    }

    public function storePage(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $attributes = $this->pageAttributesFromRequest($request);

        // Independent-review correction round 5 (item 1) — this advanced,
        // direct draft-page editor had NO lease guard at all, despite
        // writing straight into the exact page rows a generation/rebuild
        // deletes and recreates wholesale.
        try {
            $page = $this->generationCoordinator->runExclusive(
                $website,
                fn () => $this->draftPages->createPage($website, $attributes),
            );
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid])->with([
            'status' => 'success',
            'message' => 'Page created.',
        ]);
    }

    public function editPage(string $workspaceUid, string $businessUid, string $pageUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $page = $this->resolvePage($website, $pageUid);

        return view('customer.business.website.page-form', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'page' => $page,
            'assets' => $website->assets()->latest()->get(),
            'forms' => $website->forms()->get(),
        ]);
    }

    public function updatePage(Request $request, string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $page = $this->resolvePage($website, $pageUid);
        $attributes = $this->pageAttributesFromRequest($request);

        // Independent-review correction round 5 (item 1) — re-resolves
        // the page UNDER the Website lock (never the pre-lock read above)
        // and performs the write inside the SAME transaction, so a
        // generation/rebuild that deletes-and-recreates every page can
        // never interleave with this update.
        try {
            $this->generationCoordinator->runExclusive($website, function () use ($website, $pageUid, $attributes) {
                $locked = $this->resolvePage($website, $pageUid);
                $this->draftPages->updatePage($website, $locked, $attributes);
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid])->with([
            'status' => 'success',
            'message' => 'Page saved.',
        ]);
    }

    public function destroyPage(Request $request, string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $page = $this->resolvePage($website, $pageUid);
        $promoteUid = $request->input('promote_uid');

        try {
            $this->generationCoordinator->runExclusive($website, function () use ($website, $pageUid, $promoteUid) {
                $locked = $this->resolvePage($website, $pageUid);
                $this->draftPages->deletePage($website, $locked, $promoteUid);
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Page deleted.',
        ]);
    }

    public function preview(string $workspaceUid, string $businessUid, ?string $pageUid = null): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($pageUid === null && ! $this->creationState->hasGeneratedPages($website)) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        $page = $pageUid !== null
            ? $this->resolvePage($website, $pageUid)
            : $website->pages()->where('is_home', true)->first();

        abort_unless($page !== null, 404);

        $assetsByUid = $website->assets()->get()->keyBy('uid')->map(fn ($asset) => [
            'uid' => $asset->uid,
            'url' => $asset->url(),
            'alt_text' => $asset->alt_text,
        ])->all();

        $formsByUid = $website->forms()->get()->keyBy('uid')->map(fn ($form) => [
            'uid' => $form->uid,
            'name' => $form->name,
            'fields' => $form->fields,
            'submit_label' => $form->submit_label,
        ])->all();

        // Contract §19 — preview renders the CURRENT DRAFT, never the
        // published revision. Normalized to the exact same shape the
        // public renderer feeds public.website.page (contract §7.3's
        // resolved contact_details values are intentionally NOT
        // pre-resolved here — preview shows live Business state exactly
        // as it will be resolved at the NEXT publish, per §7.3).
        return view('public.website.page', [
            'website' => $website,
            'websiteMeta' => ['name' => $website->name, 'theme' => $website->theme ?? []],
            'page' => (object) [
                'uid' => $page->uid,
                'title' => $page->title,
                'seo' => (object) [
                    'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description,
                    'noindex' => $page->noindex,
                ],
            ],
            'sections' => $page->sections ?? [],
            'assetsByUid' => $assetsByUid,
            'formsByUid' => $formsByUid,
            'isPreview' => true,
            'navigationPages' => $website->pages()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($candidate) => [
                'uid' => $candidate->uid,
                'title' => $candidate->title,
                'is_home' => $candidate->is_home,
                'url' => route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid, $candidate->uid]),
            ])->all(),
        ]);
    }

    public function generate(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        // Acceptance-correction Blocker 1 — the normal full-site
        // generation action for a template-backed Website is the
        // guided AI runtime (deterministic WebsitePageStrategy plan ->
        // AI -> validate -> media-bind -> atomic commit), never the
        // older, unguided WebsiteAiDraftGenerator. The old generator
        // remains the path ONLY for a legacy, non-template (`design`
        // clean/bold/premium/blank) Website, which has no WebsiteTemplate
        // to build a deterministic plan from.
        if ($website->template_key !== null) {
            $template = WebsiteTemplate::where('key', $website->template_key)->where('is_active', true)->first();

            if ($template === null) {
                return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => "This website's template is no longer available. Contact support.",
                ]);
            }

            return $this->runGuidedGeneration($request, $workspaceUid, $businessUid, $business, $website, $template, WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION);
        }

        // Independent-review correction round 6 — the legacy (non-
        // template) AI draft generator now coordinates through the SAME
        // Website-level generation lease every other generation path
        // uses: an active generation refuses here with the identical
        // friendly, immediate GenerationInProgressException guided
        // generation produces, never a second, unguarded code path that
        // could interleave with a template-backed generation/rebuild or
        // with a Studio/wizard mutation (both of which now coordinate
        // through this exact same lease via runExclusive()).
        try {
            $leaseToken = $this->generationCoordinator->beginLease($website);
        } catch (GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            // The "no pages yet" precondition is re-checked HERE (inside
            // generateValidatedPages(), called only once the lease is
            // held) rather than merely trusting whatever was true before
            // beginLease() returned — every page/media mutation now
            // coordinates through this same lease (round 5's
            // runExclusive()), so this check is genuinely stable for the
            // rest of this lease's lifetime, not merely at the instant it
            // ran. The AI provider call inside generateValidatedPages()
            // stays entirely outside any database transaction, exactly
            // as this generator already documented.
            $pages = $this->aiGenerator->generateValidatedPages($website);

            if ($pages === null) {
                // Correction 6 / §11.4 — a paused allowance and a provider
                // outage are different facts and only one of them is worth
                // waiting for. Editing the website by hand is unaffected
                // either way.
                $message = $this->aiGenerator->lastRunWasPausedByBudget()
                    ? 'AI drafting is paused until ' . $this->aiGenerator->budgetResetsOnLabel() . '. You can keep editing your website.'
                    : 'AI generation is currently unavailable. Please try again later or add pages manually.';

                return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => $message,
                ]);
            }

            // The one canonical fenced commit: locks the Website row,
            // verifies THIS worker's lease token is still the one on the
            // row, and only then creates the entire page batch inside
            // that same transaction — an obsolete worker whose lease was
            // reclaimed in the meantime writes zero pages, and any
            // mid-batch failure rolls the whole batch back.
            $committed = $this->generationCoordinator->commitFencedLegacyDraft($website, $leaseToken, function (Website $locked) use ($pages) {
                foreach ($pages as $pageData) {
                    $this->draftPages->createPage($locked, $pageData);
                }
            });

            if (! $committed) {
                return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => 'This generation was superseded before it could finish. Please try again.',
                ]);
            }

            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'success',
                'message' => 'Draft content generated. Review and edit before publishing.',
            ]);
        } finally {
            $this->generationCoordinator->release($website, $leaseToken);
        }
    }

    public function publish(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        // Nothing to publish before the website is generated; say so
        // plainly instead of surfacing a raw validation exception.
        if (! $website->pages()->exists()) {
            $message = 'Your website has no pages yet. Create your website first, then publish.';

            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])
                ->withErrors(['website' => $message])
                ->with(['status' => 'error', 'message' => $message]);
        }

        $this->publisher->publish($website, (int) Auth::id());

        return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Website published.',
        ]);
    }

    public function history(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.history', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'revisions' => WebsiteRevision::where('website_id', $website->id)->orderByDesc('version_number')->get(),
        ]);
    }

    public function rollback(string $workspaceUid, string $businessUid, string $revisionUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $this->publisher->rollback($website, $revisionUid);

        return redirect()->route('customer.workspaces.businesses.website.history', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Website rolled back.',
        ]);
    }

    /**
     * Website Generator + Local SEO Completion — "Rebuild website from
     * template" (task instruction). Shows which template is currently
     * selected, the choice of a new one, and states plainly that a
     * rebuild replaces every current DRAFT page while the site that is
     * actually live right now (if published) keeps serving completely
     * unaffected until the owner explicitly publishes the rebuilt draft.
     */
    public function rebuildForm(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.rebuild', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'templates' => WebsiteTemplate::where('is_active', true)->orderBy('key')->get(),
            'currentPageCount' => $website->pages()->count(),
            'isPublished' => $website->published_revision_id !== null,
        ]);
    }

    public function rebuild(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $request->validate([
            'template_key' => 'required|string|max:40',
            // Explicit confirmation (task instruction, step 3) — never
            // inferred from merely visiting the page or clicking once.
            'confirm_rebuild' => 'accepted',
        ]);

        $template = WebsiteTemplate::where('key', $request->input('template_key'))
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages(['template_key' => ['Choose one of the available templates.']]);
        }

        // Acceptance-correction Blocker 1/4 — the customer-facing
        // "Rebuild website from template" action now goes through the
        // SAME guided AI runtime as full generation (fixed by this
        // correction to genuinely replace the draft, atomically, rather
        // than the earlier deterministic-only rebuild path).
        return $this->runGuidedGeneration($request, $workspaceUid, $businessUid, $business, $website, $template, WebsiteGuidedGenerationAttempt::MODE_REBUILD);
    }

    /**
     * Shared by generate() (full generation, resolving the Website's
     * OWN current template) and rebuild() (an explicitly chosen,
     * possibly different template) — both are, from the guided-generation
     * runtime's point of view, "replace this Website's entire draft with
     * a freshly AI-written batch for this template's deterministic page
     * plan," differing only in which WebsiteTemplate and user-facing
     * copy apply.
     *
     * Independent-review correction round 3 (item 11) — this is also the
     * "deliberate rebuild" the wizard's own post-generation presentation
     * edits (FAQ, custom section, gallery cover/order) require before
     * they take effect: resolving and passing through the SAME
     * customSection/customerFaq facts the wizard itself would ensures a
     * rebuild triggered from here genuinely picks up the current state
     * rather than silently reverting to whatever was true at the last
     * generation.
     *
     * Independent-review correction round 4 (item 1) — now coordinates
     * through the SAME Website-level generation lease wizard generation
     * uses (WebsiteGenerationCoordinator), instead of bypassing it
     * entirely: a wizard generation already in flight refuses a Studio-
     * originated one and vice versa. Also refuses outright while a setup
     * edit session is genuinely in progress (not merely completed) for
     * this Business — generating now would read the LATEST COMPLETED
     * response's facts, silently ignoring whatever FAQ/custom-section/
     * other answers the owner is mid-way through changing in that
     * still-open session.
     */
    private function runGuidedGeneration(Request $request, string $workspaceUid, string $businessUid, Business $business, Website $website, WebsiteTemplate $template, string $mode): RedirectResponse
    {
        $definition = $this->questionnaireResolver->resolveForBusiness($business);

        if ($definition !== null && QuestionnaireResponse::where('business_id', $business->id)
            ->where('questionnaire_definition_id', $definition->id)
            ->where('status', 'in_progress')
            ->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => 'Finish or cancel your in-progress setup edit before generating — generating now would not include the answers you are currently editing.',
            ]);
        }

        try {
            $leaseToken = $this->generationCoordinator->beginLease($website);
        } catch (\App\Library\Website\Setup\Exceptions\GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            // A fresh idempotency nonce per page render (the hidden form
            // field both the "Generate"/"Regenerate" button and the rebuild
            // confirmation form carry) — a genuine double submit of the SAME
            // rendered form carries the SAME nonce and converges to one
            // attempt; a later, deliberate resubmission after seeing a
            // failure gets a fresh nonce and is never permanently stuck
            // (acceptance-correction Blocker 6).
            $idempotencyKey = (string) $request->input('idempotency_key', (string) Str::uuid());

            $completedResponse = $this->latestCompletedSetupResponse($business);
            $customSection = WizardPresentationAnswers::customSection($completedResponse);
            $customerFaq = WizardPresentationAnswers::customerFaq($completedResponse);

            $attempt = $mode === WebsiteGuidedGenerationAttempt::MODE_REBUILD
                ? $this->guidedGeneration->rebuild($business, $website, $template, (int) Auth::id(), $idempotencyKey, $leaseToken, $customSection, $customerFaq)
                : $this->guidedGeneration->generateFull($business, $website, $template, (int) Auth::id(), $idempotencyKey, $leaseToken, $customSection, $customerFaq);

            if ($attempt->status === WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED) {
                return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                    'status' => 'success',
                    'message' => $mode === WebsiteGuidedGenerationAttempt::MODE_REBUILD && $website->published_revision_id !== null
                        ? 'Draft rebuilt with AI. Your currently published site is unaffected until you review and publish this draft.'
                        : 'Draft content generated. Review and edit before publishing.',
                ]);
            }

            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => $attempt->failure_reason ?: 'AI generation is currently unavailable. Please try again later or add pages manually.',
            ]);
        } finally {
            $this->generationCoordinator->release($website, $leaseToken);
        }
    }

    public function storeAsset(StoreWebsiteAssetRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        // Independent-review correction round 5 (item 1) — the check and
        // the mutation now share ONE Website row lock across ONE
        // transaction (WebsiteGenerationCoordinator::runExclusive()),
        // never a check that is released before this upload runs. A
        // generation that acquires the lease cannot do so inside this
        // call's own critical section; a lease already held means this
        // call never reaches uploadService::store() at all.
        $createdPath = null;

        try {
            $this->generationCoordinator->runExclusive($website, function () use ($website, $request, &$createdPath) {
                $asset = $this->assetUploads->store($website, $request->file('image'), $request->input('alt_text'));
                $createdPath = $asset->path;
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->deleteOrphanedAssetFiles([$createdPath]);

            throw $e;
        }

        return redirect()->back()->with([
            'status' => 'success',
            'message' => 'Asset uploaded.',
        ]);
    }

    public function destroyAsset(string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $asset = $website->assets()->where('uid', $assetUid)->first();
        abort_unless($asset !== null, 404);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        try {
            $this->generationCoordinator->runExclusive($website, function () use ($website, $asset) {
                $this->assetUploads->delete($website, $asset);
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->back()->with([
            'status' => 'success',
            'message' => 'Asset deleted.',
        ]);
    }

    /**
     * Independent-review correction round 5 (item 1) — mirrors
     * WebsiteWizardController's own helper of the same name exactly: a
     * stored file must never survive a later failure elsewhere in the
     * SAME outer transaction (WebsiteGenerationCoordinator::
     * runExclusive() is the outermost transaction for storeAsset(), not
     * merely WebsiteAssetUploadService::store()'s own unwrapped write).
     *
     * @param  array<int, ?string>  $paths
     */
    private function deleteOrphanedAssetFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path === null) {
                continue;
            }

            $fullPath = public_path($path);

            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    /**
     * The Website-area photo library: upload real photos (independent of
     * any page — an upload here never attaches to a page by itself, so a
     * photo stays freely deletable until explicitly selected below), then
     * select which ones belong in the Gallery page.
     */
    public function photos(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $galleryPage = $website->pages()->where('slug', 'gallery')->first();
        $gallerySection = $galleryPage !== null
            ? collect($galleryPage->sections ?? [])->firstWhere('type', 'gallery')
            : null;

        return view('customer.business.website.photos', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'assets' => $website->assets()->latest()->get(),
            'galleryPage' => $galleryPage,
            'selectedUids' => collect($gallerySection['data']['items'] ?? [])->pluck('image')->all(),
        ]);
    }

    /**
     * Reuses the SAME photos already selected onto the Gallery page —
     * never a second selection UI, and never a photo the owner has not
     * already chosen — onto any other page of this Website (typically
     * the Photo Booth Services or Packages page). Idempotent: replaces
     * an existing `gallery` section on the target page in place, or
     * appends one, leaving every other section on that page untouched,
     * mirroring storeGallery()'s own upsert exactly.
     */
    public function copyGalleryPhotosToPage(string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $page = $this->resolvePage($website, $pageUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        // Independent-review correction round 5 (item 1) — this endpoint
        // previously had NO lease guard at all, despite directly
        // mutating draft page content (exactly the state a generation/
        // rebuild replaces wholesale). Re-reads the gallery page/section
        // under the SAME Website lock the write happens under, since a
        // stale pre-lock read could otherwise describe a gallery that a
        // concurrent mutation has since changed.
        try {
            $hadPhotos = $this->generationCoordinator->runExclusive($website, function () use ($website, $page) {
                $galleryPage = $website->pages()->where('slug', 'gallery')->first();
                $gallerySection = $galleryPage !== null
                    ? collect($galleryPage->sections ?? [])->firstWhere('type', 'gallery')
                    : null;
                $items = $gallerySection['data']['items'] ?? [];

                if (empty($items)) {
                    return false;
                }

                $newSection = ['type' => 'gallery', 'data' => [
                    'heading' => $gallerySection['data']['heading'] ?? 'Photos',
                    'items' => $items,
                ]];

                $locked = $this->resolvePage($website, $page->uid);
                $sections = collect($locked->sections ?? []);
                $index = $sections->search(fn ($section) => ($section['type'] ?? null) === 'gallery');
                $index === false ? $sections->push($newSection) : $sections->put($index, $newSection);

                $this->draftPages->updatePage($website, $locked, [
                    'title' => $locked->title,
                    'slug' => $locked->slug,
                    'is_home' => $locked->is_home,
                    'sections' => $sections->values()->all(),
                    'seo_title' => $locked->seo_title,
                    'meta_description' => $locked->meta_description,
                    'noindex' => $locked->noindex,
                ]);

                return true;
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $hadPhotos) {
            return redirect()->back()->with([
                'status' => 'error',
                'message' => 'Select photos for your Gallery page first, then reuse them here.',
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid])->with([
            'status' => 'success',
            'message' => 'Gallery photos added to this page.',
        ]);
    }

    /**
     * Creates the Gallery page the first time, or updates only its
     * `gallery` section thereafter — every other section already on that
     * page (if the owner added any) is left untouched. Every uid must
     * belong to this Website's own assets; a photo never enters a page
     * merely because it exists.
     */
    public function storeGallery(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $validated = validator($request->all(), [
            'asset_uids' => 'required|array|min:1|max:24',
            'asset_uids.*' => 'string',
        ])->validate();

        // Independent-review correction round 5 (item 1) — the ownership
        // re-check and the page write both happen under the SAME Website
        // lock, inside the SAME transaction, via runExclusive(): never a
        // released pre-check followed by an unguarded mutation.
        try {
            $message = $this->generationCoordinator->runExclusive($website, function () use ($website, $validated) {
                $selectedUids = collect($validated['asset_uids'])->unique()->values();
                $ownedCount = $website->assets()->whereIn('uid', $selectedUids)->count();

                if ($ownedCount !== $selectedUids->count()) {
                    throw ValidationException::withMessages([
                        'asset_uids' => ['One or more selected photos could not be found.'],
                    ]);
                }

                $gallerySection = ['type' => 'gallery', 'data' => [
                    'heading' => 'Gallery',
                    'items' => $selectedUids->map(fn ($uid) => ['image' => $uid])->all(),
                ]];

                $existing = $website->pages()->where('slug', 'gallery')->first();

                if ($existing === null) {
                    $this->draftPages->createPage($website, [
                        'title' => 'Gallery',
                        'slug' => 'gallery',
                        'is_home' => false,
                        'sections' => [$gallerySection],
                        'noindex' => true,
                    ]);

                    return 'Gallery page created with your selected photos.';
                }

                $sections = collect($existing->sections ?? []);
                $index = $sections->search(fn ($section) => ($section['type'] ?? null) === 'gallery');
                $index === false ? $sections->push($gallerySection) : $sections->put($index, $gallerySection);

                $this->draftPages->updatePage($website, $existing, [
                    'title' => $existing->title,
                    'slug' => $existing->slug,
                    'is_home' => $existing->is_home,
                    'sections' => $sections->values()->all(),
                    'seo_title' => $existing->seo_title,
                    'meta_description' => $existing->meta_description,
                    'noindex' => $existing->noindex,
                ]);

                return 'Gallery page updated with your selected photos.';
            });
        } catch (GenerationInProgressException $e) {
            return redirect()->back()->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        return redirect()->route('customer.workspaces.businesses.website.photos.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $message,
        ]);
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: Business}>
     */
    private function accessibleBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }

    /**
     * Contract §2.2/§26.2 — the mandatory per-request resolution chain,
     * mirroring Business\AutomationsController::resolveEntitledBusiness()
     * exactly: Workspace by UID -> Business inside that Workspace ->
     * userCanAccessBusiness() -> fresh, always-recomputed entitlement
     * decision for PlatformFeature::WebsiteGeneration.
     *
     * @return array{0: \App\Models\Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    /**
     * Contract §2.3/§4 — the Website resolved scoped to the
     * already-resolved Business. A Business with no Website yet 404s
     * here (every action except show()/setup()/store() requires one to
     * already exist).
     */
    private function resolveWebsite(Business $business): Website
    {
        $website = Website::where('business_id', $business->id)->first();

        abort_unless($website !== null, 404);

        return $website;
    }

    /**
     * Independent-review correction round 3 (item 11) — the same
     * completed setup response WebsiteWizardController::editSetupAnswers()
     * would reopen, resolved here read-only so a rebuild triggered from
     * this controller (Studio's "Regenerate with AI"/rebuild-form
     * actions) picks up the SAME custom-section/FAQ facts the wizard
     * itself would. A Business with no resolvable niche questionnaire,
     * or no completed response yet, has nothing to resolve — null is a
     * normal, silent no-op for WizardPresentationAnswers' own callers.
     */
    private function latestCompletedSetupResponse(Business $business): ?QuestionnaireResponse
    {
        $definition = $this->questionnaireResolver->resolveForBusiness($business);

        if ($definition === null) {
            return null;
        }

        return QuestionnaireResponse::where('business_id', $business->id)
            ->where('questionnaire_definition_id', $definition->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }

    private function resolvePage(Website $website, string $pageUid): WebsitePage
    {
        $page = $website->pages()->where('uid', $pageUid)->first();

        abort_unless($page !== null, 404);

        return $page;
    }

    private function pageAttributesFromRequest(Request $request): array
    {
        $sections = $request->input('sections');

        if (is_string($sections)) {
            $decoded = json_decode($sections, true);
            $sections = is_array($decoded) ? $decoded : [];
        }

        return [
            'title' => $request->input('title'),
            'slug' => $request->input('slug'),
            'is_home' => $request->boolean('is_home'),
            'sections' => $sections ?? [],
            'seo_title' => $request->input('seo_title'),
            'meta_description' => $request->input('meta_description'),
            'noindex' => $request->boolean('noindex'),
        ];
    }

    private function demoGuard(string $workspaceUid, string $businessUid): ?RedirectResponse
    {
        if (config('app.stage') !== 'demo') {
            return null;
        }

        return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid])->with([
            'status' => 'error',
            'message' => 'Sorry! This option is not available in demo mode',
        ]);
    }
}
