<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Website\StoreWebsiteAssetRequest;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\WebsiteAiDraftGenerator;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteRevision;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly WebsiteDraftPageService $draftPages,
        private readonly WebsiteAssetUploadService $assetUploads,
        private readonly WebsitePublisher $publisher,
        private readonly WebsiteAiDraftGenerator $aiGenerator,
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

        return view('customer.business.website.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'pageCount' => $website->pages()->count(),
        ]);
    }

    public function setup(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        return view('customer.business.website.setup', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        $request->validate(['name' => 'required|string|max:120']);

        Website::create([
            'business_id' => $business->id,
            'name' => $request->input('name'),
        ]);

        return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Website created. Add a homepage to get started.',
        ]);
    }

    public function pages(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.pages', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'pages' => $website->pages()->orderBy('sort_order')->get(),
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
        ]);
    }

    public function storePage(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $page = $this->draftPages->createPage($website, $this->pageAttributesFromRequest($request));

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
        ]);
    }

    public function updatePage(Request $request, string $workspaceUid, string $businessUid, string $pageUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $page = $this->resolvePage($website, $pageUid);

        $this->draftPages->updatePage($website, $page, $this->pageAttributesFromRequest($request));

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

        $this->draftPages->deletePage($website, $page, $request->input('promote_uid'));

        return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Page deleted.',
        ]);
    }

    public function preview(string $workspaceUid, string $businessUid, ?string $pageUid = null): View|Factory|Application
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $page = $pageUid !== null
            ? $this->resolvePage($website, $pageUid)
            : $website->pages()->where('is_home', true)->first();

        abort_unless($page !== null, 404);

        $assetsByUid = $website->assets()->get()->keyBy('uid')->map(fn ($asset) => [
            'uid' => $asset->uid,
            'url' => $asset->url(),
            'alt_text' => $asset->alt_text,
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
                'title' => $page->title,
                'seo' => (object) [
                    'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description,
                    'noindex' => $page->noindex,
                ],
            ],
            'sections' => $page->sections ?? [],
            'assetsByUid' => $assetsByUid,
            'isPreview' => true,
        ]);
    }

    public function generate(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $succeeded = $this->aiGenerator->generate($website);

        if (! $succeeded) {
            return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => 'AI generation is currently unavailable. Please try again later or add pages manually.',
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Draft content generated. Review and edit before publishing.',
        ]);
    }

    public function publish(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
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

    public function storeAsset(StoreWebsiteAssetRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $this->assetUploads->store($website, $request->file('image'), $request->input('alt_text'));

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

        $this->assetUploads->delete($website, $asset);

        return redirect()->back()->with([
            'status' => 'success',
            'message' => 'Asset deleted.',
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
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        try {
            $decision = $this->entitlementManager->decide($workspace, $business, PlatformFeature::WebsiteGeneration->value, (int) Auth::id());
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $decision->allowed) {
            abort(404);
        }

        return [$workspace, $business];
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
