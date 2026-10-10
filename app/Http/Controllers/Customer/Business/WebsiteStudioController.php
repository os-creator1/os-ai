<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\Setup\WebsiteCreationStage;
use App\Library\Website\Setup\WebsiteCreationStateResolver;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteForm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Website Builder redesign — the unified post-generation hub replacing
 * the old disconnected "Pages"/"Publish" dashboard cards
 * (show.blade.php). For an existing Website, this is always where
 * `businesses.website.show` lands — the wizard never re-runs here except
 * through the separate, explicit "Edit setup answers" action
 * (WebsiteWizardController::editSetupAnswers()).
 *
 * Five tabs, each a thin, connected view of a REAL canonical module —
 * never duplicated data. The tab strip is a shared-shell region: a click on
 * a tab swaps only the content region (`?fragment=1`, answered by THIS same
 * action — see partials/section-router), and every tab keeps its direct URL.
 *  - website: the overview — the live site, the draft, and the next step
 *    (Preview / Publish / Edit). The management actions live in settings.
 *  - settings: Website look, and the entries to the existing canonical
 *    screens (pages, setup answers, template / rebuild, history, domains).
 *  - packages: the Business's own CatalogItem rows (the same table the
 *    wizard's package step and the standalone, separately-flagged
 *    Catalog screen both read/write — never a website-only copy).
 *  - forms: the Business's own WebsiteForm rows.
 *  - questionnaires: the Business's own completed setup answers, plus
 *    the "Edit setup answers" entry point.
 */
class WebsiteStudioController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const VALID_TABS = ['website', 'packages', 'forms', 'questionnaires', 'settings'];

    /** The module's tab strip, in order: key => label. */
    public const TAB_LABELS = ['website' => 'Website', 'packages' => 'Packages', 'forms' => 'Forms', 'questionnaires' => 'Questionnaires', 'settings' => 'Settings'];

    public function __construct(
        private readonly QuestionnaireResolver $questionnaireResolver,
        private readonly WebsiteCreationStateResolver $creationState,
        private readonly \App\Library\Website\WebsiteCatalogReferences $catalogReferences,
        private readonly \App\Library\Website\WebsiteHealthChecker $health,
    ) {
    }

    /**
     * Where each health check's "fix it" link goes (the existing Website screens).
     *
     * @return array<string, string>
     */
    private function healthLinks(Business $business): array
    {
        $workspaceUid = $business->workspace->uid;
        $params = [$workspaceUid, $business->uid];

        return [
            'publish' => route('customer.workspaces.businesses.website.studio.show', $params),
            'domains' => route('customer.workspaces.businesses.website.domains.index', $params),
            'photos' => route('customer.workspaces.businesses.website.photos.index', $params),
            'pages' => route('customer.workspaces.businesses.website.pages.index', $params),
            'forms' => route('customer.workspaces.businesses.website.forms.index', $params),
            'rebuild' => route('customer.workspaces.businesses.website.rebuild.form', $params),
            'answers' => route('customer.workspaces.businesses.website.edit-setup', $params),
        ];
    }

    /** The deep technical audit lives in the SEO module — linked only when this account can open it. */
    private function seoAuditUrl(Business $business): ?string
    {
        $workspace = $business->workspace;

        $allowed = app(\App\Library\Entitlement\EntitlementManager::class)
            ->decide($workspace, $business, PlatformFeature::SeoModule->value, (int) \Illuminate\Support\Facades\Auth::id())->allowed;

        return $allowed ? route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]) : null;
    }

    /**
     * The one Website entry point. A Website row exists from the moment the
     * wizard's template step runs (createShellFromTemplate()), long before
     * the questionnaire is answered or generation succeeds, so a row alone
     * never means "created". WebsiteCreationStateResolver decides:
     * not started -> the "Create my website" landing; in progress -> the
     * saved question; ready to generate -> the review/generate screen;
     * generated -> Studio.
     */
    public function show(string $workspaceUid, string $businessUid, string $tab = 'website'): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);

        // External Website Audit Mode V1: the Business's PRIMARY website source decides what this entry shows.
        // A Business that already has a hosted Website (or chose to build one) is untouched: the code below is
        // exactly the pre-existing hosted flow.
        $mode = app(\App\Library\Website\WebsiteModeManager::class)->resolve($business);

        if ($mode === \App\Enums\Website\WebsiteMode::External) {
            return redirect()->route('customer.workspaces.businesses.website.external.overview', [$workspaceUid, $businessUid]);
        }

        if ($mode === null || $mode === \App\Enums\Website\WebsiteMode::None) {
            return view('customer.business.website.choose', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'business' => $business,
                'mode' => $mode?->value,
            ]);
        }

        $state = $this->creationState->resolve($business);

        // THE ENTRY INVARIANT (WebsiteCreationStateResolver): Studio is
        // only for a website that actually has generated pages. Every
        // earlier stage lands on its own step of the one creation journey.
        switch ($state->stage) {
            case WebsiteCreationStage::InProgress:
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $state->response->current_step_key]);

            case WebsiteCreationStage::ReadyToGenerate:
                return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid]);

            case WebsiteCreationStage::NotStarted:
                return view('customer.business.website.empty', [
                    'workspaceUid' => $workspaceUid,
                    'businessUid' => $businessUid,
                ]);
        }

        $website = $state->website;

        if (! in_array($tab, self::VALID_TABS, true)) {
            $tab = 'website';
        }

        // The tab router asks for just the content region (same action, same gates, same data).
        $view = request()->boolean('fragment') ? 'customer.business.website.studio._fragment' : 'customer.business.website.studio.shell';

        return view($view, array_merge([
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'tab' => $tab,
            'tabLabels' => self::TAB_LABELS,
        ], $this->tabData($tab, $business, $website)));
    }

    /**
     * What the overview needs to answer "what do I have live, what is my draft, what next?". Every fact
     * comes from an existing authority: the publish state, the health check's own live-vs-draft
     * comparison (`draft_changes`) and the catalog staleness check — nothing is recomputed here.
     *
     * @return array<string, mixed>
     */
    private function overviewData(Business $business, Website $website): array
    {
        $health = $this->health->check($website, $this->healthLinks($business));
        $catalogSync = $this->catalogReferences->staleness($website);
        $draftChanges = collect($health['checks'])->firstWhere('key', 'draft_changes');
        $isPublished = $website->status->value === 'published' && $website->published_revision_id !== null;
        $domain = $website->activePrimaryDomain();

        return [
            'pageCount' => $website->pages()->count(),
            'catalogSync' => $catalogSync,
            'mediaWarnings' => $website->guidedGenerationAttempts()->latest('id')->first()?->warnings ?? [],
            'health' => $health,
            'seoAuditUrl' => $this->seoAuditUrl($business),
            'currentDesign' => \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($website->template_key),
            'isPublished' => $isPublished,
            // Anything the owner would publish: edited pages / look since the live revision, or package changes.
            'hasDraftChanges' => ! $isPublished || ($draftChanges !== null && $draftChanges['status'] !== 'ok') || ! empty($catalogSync['out_of_sync']),
            // The platform address is same-origin (the preview thumbnail can frame it); a connected domain is what visitors use.
            'liveFrameUrl' => route('public.website.home', $website->public_id),
            'liveOpenUrl' => $domain !== null ? 'https://' . $domain->domain . '/' : route('public.website.home', $website->public_id),
            'liveHost' => $domain?->domain,
            'domain' => $domain,
            // A domain that was added but is not active yet (so "connect" is really "finish connecting").
            'pendingDomain' => $domain === null ? $website->domains()->orderBy('id')->first() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tabData(string $tab, Business $business, Website $website): array
    {
        return match ($tab) {
            'website' => $this->overviewData($business, $website),
            'settings' => [
                'pageCount' => $website->pages()->count(),
                'currentDesign' => \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($website->template_key),
                'domain' => $website->activePrimaryDomain(),
                'domainRows' => $website->domains()->count(),
            ],
            'packages' => [
                'catalogItems' => CatalogItem::where('business_id', $business->id)
                    ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
                    ->with('images')
                    ->orderBy('position')
                    ->get(),
            ],
            'forms' => [
                'forms' => WebsiteForm::where('business_id', $business->id)->get(),
            ],
            'questionnaires' => [
                'responses' => ($definition = $this->questionnaireResolver->resolveForBusiness($business)) !== null
                    ? QuestionnaireResponse::where('business_id', $business->id)
                        ->where('questionnaire_definition_id', $definition->id)
                        ->latest('id')
                        ->get()
                    : collect(),
            ],
            default => [],
        };
    }
}
