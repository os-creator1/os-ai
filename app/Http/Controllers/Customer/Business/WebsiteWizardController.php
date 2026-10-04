<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Gallery\WebsiteAssetAltTextGenerator;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use App\Library\Website\Setup\QuestionnaireAnswerValidator;
use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\Setup\WebsiteCreationStage;
use App\Library\Website\Setup\WebsiteCreationStateResolver;
use App\Library\Business\BusinessImageStore;
use App\Library\Website\Gallery\ImageAltText;
use App\Library\Website\Setup\ServiceAreaList;
use App\Library\Website\Setup\WebsiteReviewSourceStatus;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\Setup\WebsiteSetupPackageManager;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Library\Website\Setup\WizardPresentationAnswers;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Website Builder redesign — the full-screen setup wizard: choose a
 * template, answer one niche-defined question per screen (never
 * hardcoded here — driven entirely by QuestionnaireStepResolver against
 * the Business's pinned QuestionnaireResponse), generate, then hand off
 * to the existing preview/domain/publish surfaces (WebsiteController /
 * WebsiteDomainController — reused verbatim, never rebuilt).
 *
 * Runs the same tenancy chain every Business-scoped controller in this
 * codebase runs (ResolvesBusinessTenancy, PlatformFeature::
 * WebsiteGeneration) — never PackagesProducts, which gates only the
 * separate, untouched standalone Catalog screen.
 *
 * Independent-review correction round: the questionnaire a Business
 * answers is resolved by QuestionnaireResolver from the Business's own
 * `industry` (Photobooth remains the only seeded niche this release) —
 * never a hardcoded definition key — and every wizard route shares one
 * lifecycle: `in_progress` covers BOTH a fresh setup and a reopened
 * `edit_mode` session; a response is only ever marked `completed` after
 * a first-time generation actually succeeds, or after an edit-mode
 * finish reconciles facts without regenerating anything.
 */
class WebsiteWizardController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const TEMPLATE_STEP = 'template';

    /** Matches the custom_section section's own persisted-body bound — enforced at output time, never merely requested of the model. */
    private const CUSTOM_SECTION_BODY_MAX = 800;

    /** A service description is a short blurb; bounded at output time, never merely requested of the model. */
    private const SERVICE_DESCRIPTION_MAX = 500;

    private const SERVICE_DESCRIPTION_TOKENS = 220;

    public function __construct(
        private readonly WebsiteStarterDraftService $starterDrafts,
        private readonly WebsiteSetupSessionManager $sessionManager,
        private readonly QuestionnaireStepResolver $stepResolver,
        private readonly WebsiteSetupAnswerApplier $answerApplier,
        private readonly GuidedGenerationCommitService $guidedGeneration,
        private readonly QuestionnaireResolver $questionnaireResolver,
        private readonly QuestionnaireAnswerValidator $answerValidator,
        private readonly WebsiteGalleryManager $gallery,
        private readonly WebsiteAssetAltTextGenerator $altTextGenerator,
        private readonly WebsiteAiGenerationClient $aiClient,
        private readonly WebsiteGenerationCoordinator $generationCoordinator,
        private readonly WebsiteCreationStateResolver $creationState,
        private readonly WebsiteSetupPackageManager $packageManager,
        private readonly WebsiteReviewSourceStatus $reviewSources,
        private readonly BusinessImageStore $businessImages,
        private readonly \App\Library\Website\Setup\WebsitePlanSummary $planSummary,
        private readonly \App\Library\Website\Design\WebsiteTemplatePreviewRenderer $templatePreviews,
    ) {
    }

    public function start(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        if ($definition === null) {
            return $this->unavailableView($workspaceUid, $businessUid);
        }

        // Website creation flow fix — the destination is decided by the
        // ONE authoritative creation state, never by "a Website row
        // exists" (the wizard creates that shell row at the template
        // step, long before anything is generated).
        $state = $this->creationState->resolve($business);

        return match ($state->stage) {
            WebsiteCreationStage::InProgress => redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $state->response->current_step_key]),
            WebsiteCreationStage::ReadyToGenerate => redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid]),
            WebsiteCreationStage::Generated => redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]),
            // No up-front style question: setup begins on the first real
            // question with the niche's default template (Template 1), and the
            // owner picks or changes the look on the Review screen — with
            // real previews of their own business — before generating.
            WebsiteCreationStage::NotStarted => $this->beginWithDefaultTemplate($business, $definition, $state->website, $workspaceUid, $businessUid),
        };
    }

    /**
     * Starts a setup on the niche's default template (idempotent, like
     * chooseTemplate(): a leftover zero-page shell is adopted, never
     * duplicated) and lands on the first question.
     */
    private function beginWithDefaultTemplate(Business $business, QuestionnaireDefinition $definition, ?Website $shell, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $template = $this->templatesForNiche($this->questionnaireResolver->nicheKeyFor($business))
            ->sortBy(fn (WebsiteTemplate $candidate) => \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($candidate->key)?->number ?? 99)
            ->first();

        if ($template === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, self::TEMPLATE_STEP]);
        }

        $website = $shell === null
            ? $this->starterDrafts->createShellFromTemplate($business, $template)
            : ($shell->template_key === null ? $this->starterDrafts->updateShellTemplate($shell, $template) : $shell);
        $response = $this->sessionManager->start($business, $definition->key, $website->id);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    public function show(string $workspaceUid, string $businessUid, string $stepKey): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        if ($definition === null) {
            return $this->unavailableView($workspaceUid, $businessUid);
        }

        $response = $this->inProgressResponse($business, $definition);

        if ($stepKey === self::TEMPLATE_STEP) {
            // Independent-review correction round 2 — an edit_mode
            // session never exposes template replacement (the website
            // already has real, possibly manually edited pages) — send
            // it back to its own current step instead. A fresh,
            // first-time in-progress session DOES render the picker
            // again here (pre-selecting its current choice): this is
            // exactly how the first question's Back arrow reaches the
            // template step, and it must actually show the chooser
            // rather than bounce straight back to the question.
            if ($response !== null && $response->edit_mode) {
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
            }

            if ($response !== null) {
                return view('customer.business.website.wizard.steps.template', [
                    'workspaceUid' => $workspaceUid,
                    'businessUid' => $businessUid,
                    'templates' => $this->templatesForNiche($this->questionnaireResolver->nicheKeyFor($business)),
                    'selectedTemplateKey' => $response->website?->template_key,
                    'progress' => ['current' => 1, 'total' => 2, 'label' => 'Choose a style'],
                ]);
            }

            $state = $this->creationState->resolve($business);

            if ($state->stage === WebsiteCreationStage::Generated) {
                return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
            }

            if ($state->stage === WebsiteCreationStage::ReadyToGenerate) {
                return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid]);
            }

            // NotStarted — including a leftover zero-page Website shell,
            // which is NOT a created website: offer the picker again,
            // pre-selecting whatever style the shell already has.
            return view('customer.business.website.wizard.steps.template', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'templates' => $this->templatesForNiche($this->questionnaireResolver->nicheKeyFor($business)),
                'selectedTemplateKey' => $state->website?->template_key,
                'progress' => ['current' => 1, 'total' => 2, 'label' => 'Choose a style'],
            ]);
        }

        if ($response === null) {
            // No in-progress session at all — nothing to answer yet
            // (or setup already finished elsewhere); route through
            // start() to decide the right destination.
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        $steps = $response->version->steps();
        $answers = $response->answers ?? [];
        $screen = $this->stepResolver->screenFor($steps, $answers, $stepKey);

        if ($screen === []) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
        }

        // A screen is addressed by its FIRST step; any other step key of it
        // resolves to that address so there is one URL per screen.
        if ($screen[0]['key'] !== $stepKey) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $screen[0]['key']]);
        }

        $screens = $this->stepResolver->screens($steps, $answers);
        $position = array_search($stepKey, array_map(fn (array $s) => $s[0]['key'], $screens), true);
        $previousStepKey = $this->stepResolver->previousStepKey($steps, $answers, $stepKey);

        return view('customer.business.website.wizard.steps.question', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'response' => $response,
            'website' => $response->website,
            'business' => $business,
            'steps' => $screen,
            'screenKey' => $stepKey,
            'step' => $screen[0],
            'previousStepKey' => $previousStepKey ?? self::TEMPLATE_STEP,
            // "Never ask twice": a question whose answer already lives in the Business OS is shown filled in.
            'answers' => $answers + \App\Library\Website\Setup\WizardPrefill::forSteps($business, $screen, $answers),
            'answer' => $response->answer($stepKey) ?? (\App\Library\Website\Setup\WizardPrefill::forSteps($business, $screen, $answers)[$stepKey] ?? null),
            'packageRows' => $this->packageRowsFor($business, $screen, $answers),
            'reviewSource' => $this->reviewSources->forBusiness($business, $screen),
            // Screens, then the Review screen (the look is chosen there — no separate style step).
            'progress' => ['current' => $position !== false ? $position + 1 : 1, 'total' => count($screens) + 1, 'label' => $screen[0]['prompt']],
        ]);
    }

    public function chooseTemplate(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        if ($definition === null) {
            abort(404);
        }

        $request->validate(['template_key' => 'required|string|max:40']);
        $nicheKey = $this->questionnaireResolver->nicheKeyFor($business);
        $template = $this->templatesForNiche($nicheKey)->firstWhere('key', $request->input('template_key'));

        if ($template === null) {
            throw ValidationException::withMessages(['template_key' => ['Choose one of the available templates.']]);
        }

        // Independent-review correction round 2 — a session already in
        // progress reaching this action is the owner CHANGING their
        // template choice from the wizard's own Back arrow (never
        // exposed to an edit_mode session — see show()'s own guard), not
        // a double submit to guard against: update the existing shell in
        // place (never losing saved answers or creating a second
        // Website/response) rather than always treating this as a fresh
        // start. Submitting the SAME template is the ordinary double-
        // submit case and is just as safely a no-op here.
        $existingResponse = $this->inProgressResponse($business, $definition);
        if ($existingResponse !== null) {
            $website = $existingResponse->website ?? abort(404);

            try {
                $this->sessionManager->runIfNotGenerating($existingResponse, function () use ($website, $template) {
                    if ($website->template_key !== $template->key) {
                        $this->starterDrafts->updateShellTemplate($website, $template);
                    }
                });
            } catch (GenerationInProgressException $e) {
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $existingResponse->current_step_key])->with([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $existingResponse->current_step_key]);
        }

        $state = $this->creationState->resolve($business);

        if ($state->stage === WebsiteCreationStage::Generated) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
        }

        if ($state->stage === WebsiteCreationStage::ReadyToGenerate) {
            return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid]);
        }

        // NotStarted. A leftover zero-page Website shell (never a created
        // website) is adopted — restyled in place, never duplicated —
        // rather than bouncing the owner into an empty Studio.
        $website = $state->website !== null
            ? $this->starterDrafts->updateShellTemplate($state->website, $template)
            : $this->starterDrafts->createShellFromTemplate($business, $template);
        $response = $this->sessionManager->start($business, $definition->key, $website->id);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    public function autosaveAnswer(Request $request, string $workspaceUid, string $businessUid, string $stepKey): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);

        // Independent-review correction round: an unknown or currently
        // hidden step key (a foreign niche's step, a step a prior answer
        // has since hidden, or one that never existed) is refused here,
        // before any value is parsed — valueFromRequest() previously fell
        // through to accepting ANY raw value for such a step.
        $step = $this->stepResolver->stepAt($response->version->steps(), $response->answers ?? [], $stepKey);
        if ($step === null) {
            abort(404);
        }

        // A wizard SCREEN groups consecutive atomic steps. Every step of the
        // screen is parsed and validated on its own; a single-step screen
        // (every v1 step) keeps the original un-namespaced field names.
        $screen = $this->stepResolver->screenFor($response->version->steps(), $response->answers ?? [], $stepKey);
        $screenKey = $screen[0]['key'];
        $isMultiStepScreen = count($screen) > 1;
        $expectedRevision = (int) $request->input('answers_revision', $response->answers_revision);

        // Canonical package writes (CatalogItemManager) happen at save
        // time; refuse a stale form BEFORE they run so a double-submitted
        // screen can never create a package twice.
        if ((int) $response->answers_revision !== $expectedRevision) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $screenKey])->with([
                'status' => 'error',
                'message' => (new AnswerRevisionConflictException((int) $response->answers_revision))->getMessage(),
            ]);
        }

        $values = [];

        try {
            // Catalog selections are processed LAST so a validation failure
            // on any other field of the screen never leaves canonical
            // package edits half-applied behind an error page.
            $ordered = collect($screen)->sortBy(fn (array $s) => $s['input_type'] === 'catalog_selection' ? 1 : 0)->values()->all();

            foreach ($ordered as $screenStep) {
                $stepRequest = $isMultiStepScreen
                    ? $request->duplicate(null, (array) $request->input('s.' . $screenStep['key'], []))
                    : $request;

                $value = $this->stepValue($stepRequest, $screenStep, $response->website, $business);
                $this->answerValidator->validate($screenStep, $value);
                $values[$screenStep['key']] = $value;
            }
        } catch (InvalidAnswerException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $screenKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $response = $this->sessionManager->saveAnswers($response, $screenKey, $values, $expectedRevision);
        } catch (AnswerRevisionConflictException|GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        // Independent-review correction round 3 (item 11) — FAQ and
        // custom-section answers are the two presentation-only surfaces
        // that reach real generated page content through
        // MediaBindingService, but only at generation/rebuild time. An
        // edit-mode session saving either after the website already has
        // generated pages must flag that a deliberate rebuild is still
        // needed — never silently imply the live pages already changed.
        if ($response->website !== null && collect($screen)->contains(fn (array $s) => in_array($s['target_module'], ['custom_section', 'faq'], true))) {
            $this->markPresentationChangePending($response->website);
        }

        if ($this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])
            && $this->stepResolver->nextStepKey($response->version->steps(), $response->answers ?? [], $screenKey) === null) {
            // Independent-review correction round: this used to redirect
            // to setup.generate, a POST-only route — a browser following
            // a redirect always issues GET, which returned a 405. The
            // review screen is the correct GET landing spot; its own form
            // POSTs to setup.generate.
            return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    public function goBack(string $workspaceUid, string $businessUid, string $stepKey): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);

        $previousStepKey = $this->stepResolver->previousStepKey($response->version->steps(), $response->answers ?? [], $stepKey);

        if ($previousStepKey === null) {
            // Independent-review correction round 2 — an edit_mode
            // session has nowhere safe "before" its first question:
            // template replacement is never exposed to it (see show()),
            // so Back exits to Studio instead of a dead-end reload of
            // the same step. A fresh, first-time session genuinely does
            // have something before its first question — the template
            // picker — and now actually reaches it (see show()'s own
            // template-step handling for a non-edit in-progress session).
            if ($response->edit_mode) {
                return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
            }

            // Nothing precedes the first question any more (the look is chosen
            // on the Review screen): Back returns to the Website landing.
            return redirect()->route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
        }

        try {
            $this->sessionManager->goToStep($response, $previousStepKey);
        } catch (GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]);
    }

    public function reviewGenerate(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        $response = $definition !== null ? $this->inProgressResponse($business, $definition) : null;

        // A `completed` response whose website never got pages is
        // reopened into the generate/try-again step (see
        // WebsiteCreationStateResolver) rather than stranding the owner.
        if ($response === null && $definition !== null) {
            $state = $this->creationState->resolve($business);

            if ($state->stage === WebsiteCreationStage::ReadyToGenerate && $state->response?->status === QuestionnaireResponseStatus::Completed) {
                try {
                    $response = $this->sessionManager->reopenForGeneration($state->response);
                } catch (GenerationInProgressException $e) {
                    return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid])->with([
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($response === null || ! $this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        $lastAttempt = $response->website?->guidedGenerationAttempts()->latest('id')->first();

        return view('customer.business.website.wizard.steps.generating', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'editMode' => $response->edit_mode,
            'answersRevision' => $response->answers_revision,
            'previousStepKey' => (function () use ($response) {
                // "Back and edit" returns to the LAST screen (addressed by its first step).
                $screens = $this->stepResolver->screens($response->version->steps(), $response->answers ?? []);

                return $screens !== [] ? (string) end($screens)[0]['key'] : (string) $response->current_step_key;
            })(),
            'lastAttemptFailed' => $lastAttempt !== null && $lastAttempt->status === WebsiteGuidedGenerationAttempt::STATUS_FAILED,
            'generationAvailable' => (bool) config('services.openai.active'),
            'answerSummary' => $this->answerSummary($response),
        ] + $this->lookAndPlanData($business, $response));
    }

    /**
     * The Review screen's template cards, brand/logo/hero state and the
     * "Website plan" (which pages Generate will build, and why not the
     * others). An edit-mode session never shows them (its pages exist).
     *
     * @return array<string, mixed>
     */
    private function lookAndPlanData(Business $business, QuestionnaireResponse $response): array
    {
        $website = $response->website;

        if ($response->edit_mode || $website === null) {
            return ['templateCards' => [], 'plan' => null];
        }

        $templates = $this->templatesForNiche($this->questionnaireResolver->nicheKeyFor($business))
            ->sortBy(fn (WebsiteTemplate $candidate) => \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($candidate->key)?->number ?? 99)
            ->values();

        $current = $templates->firstWhere('key', $website->template_key) ?? $templates->first();
        $theme = $website->theme ?? [];
        $assets = $website->assets()->whereIn('uid', array_filter([$theme['logo_asset_uid'] ?? null, $theme['hero_asset_uid'] ?? null]))->get()->keyBy('uid');

        return [
            'templateCards' => $templates->map(fn (WebsiteTemplate $template) => [
                'key' => $template->key,
                'design' => \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($template->key),
                'selected' => $current !== null && $template->key === $current->key,
                // The real renderer, embedded (srcdoc): no extra request per card.
                'html' => $this->templatePreviews->render($business, $website, $template),
            ])->all(),
            'brandColor' => $theme['brand_color'] ?? null,
            'logo' => isset($theme['logo_asset_uid'], $assets[$theme['logo_asset_uid']]) ? ['url' => $assets[$theme['logo_asset_uid']]->url(), 'alt' => $assets[$theme['logo_asset_uid']]->alt_text] : null,
            'hero' => isset($theme['hero_asset_uid'], $assets[$theme['hero_asset_uid']]) ? ['url' => \App\Library\Website\Media\WebsiteMediaPayload::thumbUrl($assets[$theme['hero_asset_uid']]), 'alt' => $assets[$theme['hero_asset_uid']]->alt_text] : null,
            'plan' => $current !== null ? $this->planSummary->forResponse($business, $website, $current, $response) : null,
        ];
    }

    /**
     * A human review of what the owner answered, shown on the review
     * screen so "Generate my website" is a confident final click, never a
     * blind one. Grouped under four plain headings; repeatable answers
     * (service areas, services, packages, backdrops, photos) are listed
     * entry by entry — never comma-dumped — and every block carries the
     * address of the screen that edits it. Packages are read live from the
     * canonical catalog (the answer only stores their uids), so the review
     * always shows today's name and price.
     *
     * @return array<string, array<int, array{prompt: string, edit_key: string, kind: string, value: ?string, entries: array<int, array{label: string, meta: ?string, thumb: ?string}>}>> heading => blocks (empty groups omitted)
     */
    private function answerSummary(QuestionnaireResponse $response): array
    {
        $groupOf = [
            'business' => 'About your business',
            'business_location' => 'About your business',
            'knowledge_profile' => 'About your business',
            'business_service' => 'Services & packages',
            'catalog_item' => 'Services & packages',
            'backdrop' => 'Services & packages',
            'gallery' => 'Website content',
            'custom_section' => 'Website content',
            'faq' => 'Website content',
            'website_form' => 'Contact & other',
            'answers' => 'Contact & other',
        ];

        $groups = ['About your business' => [], 'Services & packages' => [], 'Website content' => [], 'Contact & other' => []];
        $steps = $response->version->steps();
        $answers = $response->answers ?? [];
        $website = $response->website;

        foreach ($this->stepResolver->screens($steps, $answers) as $screen) {
            $screenKey = $screen[0]['key'];

            foreach ($screen as $step) {
                $block = $this->summaryBlock($step, $answers[$step['key']] ?? null, $website, $screenKey);

                if ($block !== null) {
                    $groups[$groupOf[$step['target_module'] ?? ''] ?? 'Contact & other'][] = $block;
                }
            }
        }

        return array_filter($groups);
    }

    /**
     * @return ?array{prompt: string, edit_key: string, kind: string, value: ?string, entries: array<int, array{label: string, meta: ?string, thumb: ?string}>}
     */
    private function summaryBlock(array $step, mixed $value, ?Website $website, string $screenKey): ?array
    {
        $options = is_array($step['options'] ?? null) ? $step['options'] : [];
        $label = fn ($item) => (string) ($options[(string) $item] ?? $item);
        $entry = fn (string $text, ?string $meta = null, ?string $thumb = null) => ['label' => $text, 'meta' => $meta, 'thumb' => $thumb];
        $block = fn (string $kind, ?string $text, array $entries = []) => [
            'prompt' => (string) $step['prompt'],
            'edit_key' => $screenKey,
            'kind' => $kind,
            'value' => $text !== null ? Str::limit($text, 240) : null,
            'entries' => $entries,
        ];

        // A photo step's photos are real Website assets, not an answer.
        if ($step['input_type'] === 'photo_upload') {
            $assets = $website?->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->get() ?? collect();

            return $assets->isEmpty() ? null : $block('photos', null, $assets->take(12)->map(
                fn (WebsiteAsset $a) => $entry((string) ($a->title ?: $a->alt_text ?: 'Photo'), null, asset($a->path))
            )->all());
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if ($step['input_type'] === 'catalog_selection') {
            $uids = array_values(array_filter(array_map(fn ($e) => is_array($e) ? ($e['uid'] ?? null) : null, (array) $value)));
            // Only packages that are STILL live in Packages & Products count;
            // generation skips the others, so the review says so plainly.
            $items = \App\Models\CatalogItem::whereIn('uid', $uids)
                ->where('business_id', $website?->business_id)
                ->where('lifecycle_state', \App\Enums\Catalog\CatalogItemLifecycleState::Active->value)
                ->get()->keyBy('uid');
            $entries = [];

            foreach ($uids as $uid) {
                if (isset($items[$uid])) {
                    $item = $items[$uid];
                    $entries[] = $entry($item->name, $item->price_minor !== null ? \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) : 'Contact for pricing');
                }
            }

            $missing = count($uids) - count($entries);
            $packagesBlock = $block('packages', null, $entries);
            $packagesBlock['notice'] = $missing > 0
                ? ($missing === 1 ? 'A package you selected is' : $missing . ' packages you selected are') . ' no longer in Packages & Products and will be left out of your website. Edit this step to choose others.'
                : null;

            return ($entries === [] && $missing === 0) ? null : $packagesBlock;
        }

        if ($step['input_type'] === 'string_list') {
            return $block('list', null, array_map(fn ($v) => $entry((string) $v), array_values((array) $value)));
        }

        if ($step['input_type'] === 'repeatable_group') {
            $categories = is_array($step['categories'] ?? null) ? $step['categories'] : [];
            $entries = [];

            foreach ((array) $value as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = (string) ($item['name'] ?? $item['question'] ?? $item['author_name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $meta = isset($item['category']) ? ($categories[$item['category']] ?? null) : (isset($item['quote']) ? Str::limit((string) $item['quote'], 80) : null);
                $thumb = ! empty($item['image_path']) ? asset($item['image_path']) : null;
                $entries[] = $entry($name, $meta, $thumb);
            }

            return $entries === [] ? null : $block(collect($entries)->contains(fn ($e) => $e['thumb'] !== null) ? 'backdrops' : 'list', null, $entries);
        }

        if (is_array($value)) {
            return $block('list', null, array_map(fn ($v) => $entry($label($v)), array_values($value)));
        }

        if (is_bool($value)) {
            return $block('text', $value ? 'Yes' : 'No');
        }

        return $block('text', $label($value));
    }

    /**
     * Independent-review correction round — rewritten lifecycle:
     *
     *  - the response is marked `completed` ONLY after generation
     *    actually succeeds (never before), so a failed attempt leaves it
     *    genuinely `in_progress` and retryable rather than stranded with
     *    inProgressResponse() no longer finding it;
     *  - an edit_mode session finishing here reconciles canonical facts
     *    (WebsiteSetupAnswerApplier — idempotent/reconciling, see that
     *    class) and returns to Studio WITHOUT ever calling guided
     *    generation again, so manually edited page content is never
     *    silently regenerated or overwritten;
     *  - the idempotency key handed to GuidedGenerationCommitService is
     *    now STABLE (derived from the response's own uid, its pinned
     *    version, the chosen template, and the answers revision) instead
     *    of a fresh random uuid every POST, so a double-click or a
     *    genuine retry with unchanged answers converges to the SAME
     *    underlying attempt rather than defeating that service's own
     *    material-hash idempotency;
     *  - a per-response Cache lock serializes concurrent submissions of
     *    this same response end to end (reconciliation included, not
     *    only the AI call), so a double-click can neither reconcile
     *    canonical records twice nor spend AI twice.
     */
    /**
     * Independent-review correction round 2 — a durable compare-and-swap
     * protocol replaces trusting whatever generate() resolved BEFORE the
     * lock:
     *
     *  - the per-response lock is now a single, non-blocking attempt — a
     *    generation already in progress returns an immediate, friendly
     *    redirect rather than blocking 15 seconds and then throwing
     *    LockTimeoutException;
     *  - WebsiteSetupSessionManager::beginGeneration(), called ONLY after
     *    the lock is held, re-reads ownership/status/completeness/
     *    answers_revision fresh, under a row lock, and REFUSES to enter
     *    generation at all if any of them no longer match what this
     *    request observed before acquiring the lock — a response another
     *    request already completed, or whose answers changed underneath
     *    it, is never silently reconciled or spent on;
     *  - successfully entering generation sets `generation_started_at`,
     *    which saveAnswer()/updateAnswerInPlace() both refuse to write
     *    through — answers genuinely cannot mutate while an AI call is
     *    in flight;
     *  - a technical failure (generateFull() didn't succeed) explicitly
     *    clears that freeze (recordGenerationFailure()), returning the
     *    response to a genuinely retryable in_progress state; success
     *    completes exactly once via complete()/completeEdit().
     */
    public function generate(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business, allowMissing: true);

        if ($response === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        $expectedRevision = (int) $request->input('answers_revision', $response->answers_revision);
        $website = $response->website ?? abort(404);
        $actorUserId = (int) Auth::id();

        // Independent-review correction round 4 (item 1) — the Website-
        // level lease, not a per-response Cache lock, is what now
        // coordinates with Studio's own "Regenerate"/"Rebuild" actions
        // (WebsiteController) and every setup mutation (WebsiteSetup
        // SessionManager::runIfNotGenerating()) — all three share this
        // SAME lease on the SAME row.
        try {
            $leaseToken = $this->generationCoordinator->beginLease($website);
        } catch (GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            try {
                $response = $this->sessionManager->beginGeneration($business, $response, $expectedRevision);
            } catch (AnswerRevisionConflictException $e) {
                return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            } catch (\DomainException $e) {
                // Not in progress (already completed by a request that
                // won the race), or not yet complete: report honestly
                // rather than pretending generation started.
                if ($response->fresh()->status === QuestionnaireResponseStatus::Completed) {
                    return redirect()->route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid]);
                }

                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->fresh()->current_step_key])->with([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->answerApplier->apply($business, $website, $response, $actorUserId);

                if ($response->edit_mode) {
                    $this->sessionManager->completeEdit($response);

                    return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid])->with([
                        'status' => 'success',
                        'message' => 'Your setup answers were updated.',
                    ]);
                }

                $template = WebsiteTemplate::where('key', $website->template_key)->where('is_active', true)->firstOrFail();
                $customSection = WizardPresentationAnswers::customSection($response);
                $customerFaq = WizardPresentationAnswers::customerFaq($response);
                $idempotencyKey = $this->stableIdempotencyKey($response, $website);

                $attempt = $this->guidedGeneration->generateFull($business, $website->fresh(), $template, $actorUserId, $idempotencyKey, $leaseToken, $customSection, $customerFaq, WizardPresentationAnswers::catalogSelection($response), WizardPresentationAnswers::serviceAreas($response));

                if ($attempt->status !== WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED) {
                    $this->sessionManager->recordGenerationFailure($response);

                    return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                        'status' => 'error',
                        // A failure_reason is for support (it may carry internals); the customer gets a calm, safe sentence.
                        'message' => \App\Library\Website\GuidedGeneration\GenerationFailureMessage::forCustomer($attempt->failure_reason),
                    ]);
                }

                $this->sessionManager->complete($response);

                return redirect()->route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid])->with([
                    'status' => 'success',
                    'message' => 'Your website is ready! Review it below, then connect a domain or publish whenever you\'re ready.',
                ]);
            } catch (Throwable $e) {
                // Independent-review correction round 3 (item 1) —
                // `generation_started_at` must never remain set forever
                // merely because something AFTER beginGeneration() threw
                // (a canonical-write failure in applyServices()/
                // applyPackages()/applyBackdrops(), a missing template
                // row, or any other unexpected exception). Every path
                // through this try block that does not end in an
                // explicit complete()/completeEdit() call above clears
                // the freeze here instead, returning the response to a
                // genuinely retryable in_progress state, and the
                // customer sees a plain redirect rather than a raw 500.
                report($e);
                $this->sessionManager->recordGenerationFailure($response);

                return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => 'Something went wrong while generating your website. Please try again.',
                ]);
            }
        } finally {
            $this->generationCoordinator->release($website, $leaseToken);
        }
    }

    /**
     * Independent-review correction round — genuinely reopens the SAME
     * pinned completed response (WebsiteSetupSessionManager::beginEdit())
     * rather than redirecting toward a response the rest of this
     * controller can never resolve (every other route only ever resolves
     * an `in_progress` one). Finishing this session again goes through
     * generate() above, which detects `edit_mode` and reconciles facts
     * without ever regenerating pages.
     */
    public function editSetupAnswers(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        Website::where('business_id', $business->id)->first() ?? abort(404);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        if ($definition === null) {
            abort(404);
        }

        $completed = QuestionnaireResponse::where('business_id', $business->id)
            ->where('questionnaire_definition_id', $definition->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($completed === null) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => 'No setup answers were found for this website.',
            ]);
        }

        $response = $this->sessionManager->beginEdit($business, $completed);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    /**
     * The wizard's real "Show your work" gallery step: uploads land as
     * ordinary Website-owned WebsiteAsset rows immediately (durable —
     * survives leaving and returning to the step, since the data lives
     * in `website_assets`, not in the response's own `answers` JSON) via
     * the same WebsiteGalleryManager the earlier round built and tested
     * but never wired into any real HTTP surface.
     */
    public function uploadGalleryPhotos(Request $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'gallery');

        $request->validate([
            'photos' => 'required|array|max:20',
            'photos.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
        ]);

        // Independent-review correction round 4 (item 6) — the same
        // orphaned-file-cleanup discipline uploadCustomSectionImage() uses:
        // runIfNotGenerating() is the OUTERMOST transaction for this
        // mutation (not merely uploadMany()'s own inner one), so tracking
        // every asset this call creates means ANY later failure inside
        // that same transaction — today or after a future change adds
        // more work to this closure — rolls back the DB AND deletes the
        // file(s) this request itself just wrote, never leaving an orphan
        // on disk pointing at a row that no longer exists.
        $createdAssetPaths = [];
        $uploadedAssets = [];

        try {
            // Independent-review correction round 4 (items 1, 6) — the
            // gallery step's own visibility is RE-verified under the
            // SAME lock as the generation-freeze check, immediately
            // before anything is written, including a file to disk —
            // never merely checked once, earlier, against a read that
            // could already be stale by the time the mutation runs.
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($website, $request, &$createdAssetPaths, &$uploadedAssets) {
                $this->stepKeyForModule($locked, 'gallery');
                $assets = $this->gallery->uploadMany($website, $request->file('photos', []), WebsiteAssetPurpose::Gallery, $request->input('category_tag'));
                foreach ($assets as $asset) {
                    $createdAssetPaths[] = $asset->path;
                    $uploadedAssets[] = $asset;
                }
            });
        } catch (InvalidWebsiteAssetException|GenerationInProgressException $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), $e instanceof GenerationInProgressException ? 409 : 422);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            throw $e;
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'assets' => array_map(fn (WebsiteAsset $a) => $this->assetPayload($a), $uploadedAssets)]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * Independent-review correction round 3 (item 7) — title/category/
     * alt-text metadata and the cover flag are now genuinely independent
     * mutations: the "Make cover" form submits ONLY `is_cover` (no
     * title/category/alt fields at all), and setTitleAndCategory() is
     * only ever called when at least one of those metadata fields is
     * actually PRESENT in the request — never merely because the form
     * happened to also carry an `is_cover` field. Previously, every
     * cover-only submission was interpreted as "the owner cleared every
     * metadata field", silently wiping title/category and regenerating
     * (or, worse, blanking) the alt text.
     */
    public function updateGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'gallery');
        // Independent-review correction round 3 (item 7) — abort(404), not
        // firstOrFail(): this codebase's own exception handler
        // (app/Exceptions/Handler.php) deliberately renders a
        // ModelNotFoundException as a 500 "Server Error" page outside the
        // local environment, never a 404 — abort_unless() is the
        // established pattern for a genuine "not found for you" scope
        // check (see WebsiteAssetUploadService::delete()).
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::Gallery->value)->first();
        abort_unless($asset !== null, 404);

        $request->validate(['title' => 'nullable|string|max:160', 'category_tag' => 'nullable|string|max:80', 'alt_text' => 'nullable|string|max:160']);

        // A niche that defines a category vocabulary for its gallery step
        // accepts only those keys — never free text.
        $galleryStep = $this->stepResolver->stepAt($response->version->steps(), $response->answers ?? [], $stepKey);
        $vocabulary = $galleryStep['categories'] ?? null;
        if (is_array($vocabulary) && $request->filled('category_tag') && ! array_key_exists((string) $request->input('category_tag'), $vocabulary)) {
            throw ValidationException::withMessages(['category_tag' => ['Choose one of the listed categories.']]);
        }

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($request, $website, $asset) {
                $this->stepKeyForModule($locked, 'gallery');

                if ($request->hasAny(['title', 'category_tag', 'alt_text'])) {
                    $this->gallery->setTitleAndCategory($website, $asset, WebsiteAssetPurpose::Gallery, $request->input('title'), $request->input('category_tag'), $request->input('alt_text'));
                }

                if ($request->boolean('is_cover')) {
                    $this->gallery->setCover($website, $asset);
                }
            });
        } catch (GenerationInProgressException $e) {
            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), 409);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'asset' => $this->assetPayload($asset->fresh())]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * v1's guaranteed reordering mechanism (up/down buttons, never
     * drag-and-drop — the approved scope): swaps this asset with its
     * immediate neighbor, then hands the WHOLE ordered uid list to
     * WebsiteGalleryManager::reorder(), which is the only thing that
     * actually writes `sort_order`. Scoped to gallery-purpose assets
     * alone — a custom-section or package-mirror asset is never part of
     * this ordering.
     */
    public function moveGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'gallery');

        $request->validate(['direction' => 'required|in:up,down']);

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($website, $request, $assetUid) {
                $this->stepKeyForModule($locked, 'gallery');
                $orderedUids = $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->pluck('uid')->all();
                $position = array_search($assetUid, $orderedUids, true);

                if ($position === false) {
                    abort(404);
                }

                $swapWith = $request->input('direction') === 'up' ? $position - 1 : $position + 1;

                if ($swapWith >= 0 && $swapWith < count($orderedUids)) {
                    [$orderedUids[$position], $orderedUids[$swapWith]] = [$orderedUids[$swapWith], $orderedUids[$position]];
                    $this->gallery->reorder($website, WebsiteAssetPurpose::Gallery, $orderedUids);
                }
            });
        } catch (GenerationInProgressException $e) {
            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), 409);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success']);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * Independent-review correction round 2/3 — scoped to gallery-purpose
     * assets alone: this action can never delete a custom-section or
     * package-mirror image, whatever uid is given (a wrong-purpose or
     * foreign uid explicitly 404s below, never a silent no-op).
     */
    public function removeGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'gallery');
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::Gallery->value)->first();
        abort_unless($asset !== null, 404);

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($website, $asset) {
                $this->stepKeyForModule($locked, 'gallery');
                app(WebsiteAssetUploadService::class)->delete($website, $asset);
            });
        } catch (ValidationException|GenerationInProgressException $e) {
            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), $e instanceof GenerationInProgressException ? 409 : 422);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success']);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * A custom-section image is an ordinary Website-owned asset (this
     * content is website-presentation-specific, not a reusable canonical
     * business fact — see WebsitePageStrategy's own reasoning for the
     * same distinction) tagged so it never mingles with the gallery
     * listing, and its uid is appended directly into the response's own
     * `custom_section` answer (never a separate model) — the same answer
     * customSectionFromAnswers()/MediaBindingService::bindCustomSection()
     * already expect.
     */
    public function uploadCustomSectionImage(Request $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'custom_section');

        $request->validate(['photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192']);

        // Independent-review correction round 4 (item 6) — a stored file
        // must never survive a later failure elsewhere in this same
        // mutation. runIfNotGenerating() already wraps this entire
        // callback in ONE transaction (the OUTERMOST one for this
        // mutation, not merely uploadMany()'s own inner one) — tracking
        // every asset this call creates means a failure in
        // updateAnswerInPlace() (e.g. the answers JSON size guard) rolls
        // back that whole transaction AND deletes the file this request
        // itself just wrote, never leaving an orphan on disk pointing at
        // a row that no longer exists.
        $createdAssetPaths = [];
        $newAsset = null;

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $website, $request, &$createdAssetPaths, &$newAsset) {
                $this->stepKeyForModule($locked, 'custom_section');

                $assets = $this->gallery->uploadMany($website, [$request->file('photo')], WebsiteAssetPurpose::CustomSection);
                $newAsset = $assets[0] ?? null;
                foreach ($assets as $asset) {
                    $createdAssetPaths[] = $asset->path;
                }

                $entry = $this->currentCustomSectionEntry($locked, $stepKey);
                $entry['images'][] = $assets[0]->uid;
                $entry['images'] = array_values(array_slice($entry['images'], 0, QuestionnaireAnswerValidator::MAX_CUSTOM_SECTION_IMAGES));

                $this->sessionManager->updateAnswerInPlace($locked, $stepKey, [$entry]);
            });
        } catch (InvalidWebsiteAssetException|GenerationInProgressException $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), $e instanceof GenerationInProgressException ? 409 : 422);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            throw $e;
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json($this->assetPayload($newAsset) + [
                'status' => 'success',
                'answers_revision' => (int) $response->fresh()->answers_revision,
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * A backdrop picture is stored the moment the owner picks it (immediate
     * upload) in the Business-owned image store. Only the resulting path
     * travels back into the screen's answer; the canonical backdrop row and
     * its image row are written when setup is applied.
     */
    public function uploadBackdropImage(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $stepKey = $this->stepKeyForModule($response, 'backdrop');

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
            'name' => 'nullable|string|max:160',
            'category' => 'nullable|string|max:40',
        ]);

        try {
            $stored = $this->businessImages->store($business, $request->file('photo'));
        } catch (InvalidWebsiteAssetException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $step = $this->stepResolver->stepAt($response->version->steps(), $response->answers ?? [], $stepKey);
        $categoryLabel = ($step['categories'][$request->input('category')] ?? null);

        return response()->json([
            'status' => 'success',
            'path' => $stored['path'],
            'url' => asset($stored['path']),
            'alt_suggestion' => ImageAltText::suggest($request->input('name'), $categoryLabel, $business->name),
        ]);
    }

    /**
     * Frees a picture the owner replaced or removed before it was ever
     * saved. Only a file inside this Business's own directory that no
     * canonical backdrop/package row still references is ever deleted.
     */
    public function removeBackdropImage(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $this->stepKeyForModule($response, 'backdrop');

        $path = (string) $request->validate(['path' => 'required|string|max:255'])['path'];
        $this->businessImages->deleteIfUnreferenced($business, $path);

        return response()->json(['status' => 'success']);
    }

    /**
     * "Generate description with AI" for one service: the service name plus
     * the Business and niche context in, a short factual description out.
     * It never saves anything — the owner reviews and edits the text, and
     * the screen asks before replacing text the owner wrote. Fails closed
     * (a plain message, never an exception) when AI is switched off, out
     * of budget, or returns anything unusable.
     */
    public function suggestServiceDescription(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $this->stepKeyForModule($response, 'business_service');

        $name = trim((string) $request->validate(['name' => 'required|string|max:160'])['name']);

        $messages = [
            ['role' => 'system', 'content' => implode("\n", [
                'You write the short description of ONE service offered by a local business, for its website.',
                'Respond with a single JSON object: {"description": string}.',
                'Plain text, at most ' . self::SERVICE_DESCRIPTION_MAX . ' characters, one to three sentences, in a warm and factual tone.',
                'Use only the facts provided. Never invent prices, awards, statistics, locations, reviews or guarantees.',
            ])],
            ['role' => 'user', 'content' => json_encode([
                'business_name' => $business->name,
                'business_type' => $business->industry instanceof \BackedEnum ? $business->industry->value : (string) $business->industry,
                'business_description' => Str::limit(trim((string) $business->description), 600, ''),
                'service_name' => $name,
            ])],
        ];

        $raw = $this->aiClient->complete($messages, $business, (int) Auth::id(), self::SERVICE_DESCRIPTION_TOKENS);

        if ($raw === null) {
            if ($this->aiClient->lastCallWasBudgetExhausted()) {
                return $this->jsonError('The included AI generation budget is used up for this period.', 422);
            }

            if ($this->aiClient->lastRefusalReason() === \App\Library\Ai\Enums\AiRefusalReason::AiDisabled) {
                return $this->jsonError("AI descriptions aren't available in this environment right now. You can write the description yourself.", 503);
            }

            return $this->jsonError("We couldn't write a description right now. You can write it yourself or try again.", 503);
        }

        $decoded = json_decode($raw, true);
        $description = is_array($decoded) && is_string($decoded['description'] ?? null) ? trim($decoded['description']) : '';

        // The bound is enforced on the OUTPUT — the prompt alone is never trusted.
        if ($description === '' || mb_strlen($description) > self::SERVICE_DESCRIPTION_MAX) {
            return $this->jsonError("We couldn't write a usable description. You can write it yourself or try again.", 503);
        }

        return response()->json(['status' => 'success', 'description' => $description]);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    /**
     * @return array{uid: string, url: string, alt_text: ?string, title: ?string, category_tag: ?string, is_cover: bool}
     */
    private function assetPayload(WebsiteAsset $asset): array
    {
        return [
            'uid' => (string) $asset->uid,
            'url' => asset($asset->path),
            'alt_text' => $asset->alt_text,
            'title' => $asset->title,
            'category_tag' => $asset->category_tag,
            'is_cover' => (bool) $asset->is_cover,
        ];
    }

    /**
     * @param  array<int, string>  $paths  disk-relative paths of files written during a mutation this request is unwinding
     */
    private function deleteOrphanedAssetFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $fullPath = public_path($path);

            if (is_file($fullPath)) {
                @unlink($fullPath);
            }

            app(\App\Library\Website\Media\ImageVariants::class)->delete($path);
        }
    }

    public function removeCustomSectionImage(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse|JsonResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'custom_section');

        // Independent-review correction round 2/3 — scoped to the
        // custom-section purpose alone: a gallery/package/foreign-Website
        // uid explicitly 404s rather than being silently accepted as
        // "not found, remove it from the answer anyway." abort_unless(),
        // not firstOrFail() — see updateGalleryPhoto()'s own comment on
        // why a ModelNotFoundException would render as a 500 here instead.
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::CustomSection->value)->first();
        abort_unless($asset !== null, 404);

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $website, $asset, $assetUid) {
                $this->stepKeyForModule($locked, 'custom_section');
                $entry = $this->currentCustomSectionEntry($locked, $stepKey);
                $entry['images'] = array_values(array_diff($entry['images'], [$assetUid]));
                $this->sessionManager->updateAnswerInPlace($locked, $stepKey, [$entry]);

                try {
                    app(WebsiteAssetUploadService::class)->delete($website, $asset);
                } catch (ValidationException) {
                    // Left in place (e.g. already published) — the answer
                    // no longer references it either way.
                }
            });
        } catch (GenerationInProgressException $e) {
            if ($request->expectsJson()) {
                return $this->jsonError($e->getMessage(), 409);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'answers_revision' => (int) $response->fresh()->answers_revision]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * Independent-review correction round 2 — rewritten: the "Improve
     * with AI" button now submits the answer form's OWN currently-typed
     * fields (via a `form=`-associated sibling button, never a nested
     * form) to this action, so it always improves what the owner is
     * looking at right now, never a stale persisted value. This action
     * always PERSISTS the submitted text first (exactly like an ordinary
     * autosave) so a refusal, malformed response, or oversized output
     * never loses the owner's own typed draft — only a genuinely
     * successful, bounded AI improvement ever replaces it.
     *
     * The ONLY place the custom section's copy is ever sent to AI — never
     * merely because the step was opened, saved, resumed, or previewed.
     * One explicit action, one AiGateway-budgeted call through the SAME
     * seam (WebsiteAiGenerationClient) the main guided-generation batch
     * uses; no second AI integration. A per-response lock keyed to the
     * exact submitted content makes a double-click/concurrent identical
     * request converge to a single AI spend.
     */
    /**
     * Independent-review correction round 3 (item 2/3) — the Cache lock
     * plus 60-second Cache "done" marker this method used to rely on is
     * replaced entirely by WebsiteSetupSessionManager's durable,
     * response-row-locked Improve state (beginCustomSectionImprove()/
     * completeCustomSectionImprove()): a duplicate or in-flight request
     * now always resolves immediately to a friendly result (never a
     * Cache::lock()->block() timeout/LockTimeoutException/HTTP 500), and
     * a stale AI response can never overwrite a newer edit (compare-and-
     * swap against `answers_revision`). The AI call itself asks for at
     * most IMPROVE_MAX_OUTPUT_TOKENS — far below the shared
     * `website_generation` route's own 8,000-token ceiling sized for a
     * full multi-page site — and carries this exact submission's own
     * durable idempotency key, never a fresh meaningless-for-dedup uuid.
     */
    private const IMPROVE_MAX_OUTPUT_TOKENS = 400;

    public function improveCustomSection(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $stepKey = $this->stepKeyForModule($response, 'custom_section');

        // Independent-review correction round 4 (item 2) — required, not
        // defaulted: a form that never observed a real revision has no
        // business asserting the identity of "the revision it last saw."
        $request->validate(['answers_revision' => 'required|integer']);
        $expectedRevision = (int) $request->input('answers_revision');

        $submittedItems = array_filter((array) $request->input('items', []), fn ($item) => is_array($item));
        $submitted = array_values($submittedItems)[0] ?? [];

        try {
            $entry = $this->normalizeRepeatableItem(is_array($submitted) ? $submitted : [], ['target_module' => 'custom_section'], $website);
            $this->answerValidator->validate(['input_type' => 'repeatable_group', 'required' => false, 'target_module' => 'custom_section'], [$entry]);
        } catch (InvalidAnswerException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        if (trim((string) $entry['body']) === '') {
            try {
                $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $entry, $expectedRevision) {
                    if ((int) $locked->answers_revision !== $expectedRevision) {
                        throw new AnswerRevisionConflictException((int) $locked->answers_revision);
                    }

                    $this->sessionManager->updateAnswerInPlace($locked, $stepKey, [$entry]);
                });
            } catch (AnswerRevisionConflictException|GenerationInProgressException $e) {
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with(['status' => 'error', 'message' => $e->getMessage()]);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => 'Write something in the body field before asking AI to improve it.',
            ]);
        }

        // A durable identity for this EXACT logical submission — the
        // response, the revision the owner's own form last observed, and
        // the complete submitted material (title/body/layout). A changed
        // title with the same body, or a submission against a
        // since-changed revision, always produces a different key. This
        // is NEVER the key handed to the AI gateway directly — see
        // beginCustomSectionImprove()'s own returned, always-fresh-per-
        // attempt ledger key below.
        $logicalKey = hash('sha256', implode('|', [$response->uid, $expectedRevision, $entry['name'], $entry['body'], $entry['layout']]));

        try {
            $begin = $this->sessionManager->beginCustomSectionImprove($response, $stepKey, $logicalKey, $entry, $expectedRevision);
        } catch (AnswerRevisionConflictException|GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $this->markPresentationChangePending($website);

        if ($begin['outcome'] === 'duplicate_pending') {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'success',
                'message' => 'This section is already being improved — check back in a moment.',
            ]);
        }

        if ($begin['outcome'] === 'duplicate_succeeded') {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'success',
                'message' => 'Your custom section copy was improved.',
            ]);
        }

        $ledgerKey = $begin['ledgerKey'];

        // AI provider call stays outside any database transaction
        // (matching GuidedGenerationCommitService's own documented
        // discipline) — every write above and below it is its own short,
        // separately locked transaction.
        $messages = [
            ['role' => 'system', 'content' => 'You improve one short marketing section of a small business website. Respond with a single JSON object: {"body": string}. Keep the improved copy under ' . self::CUSTOM_SECTION_BODY_MAX . ' characters, factual, and free of invented claims.'],
            ['role' => 'user', 'content' => json_encode([
                'business_name' => $business->name,
                'section_title' => $entry['name'],
                'current_body' => $entry['body'],
            ])],
        ];

        $budgetExhausted = false;

        try {
            $raw = $this->aiClient->complete($messages, $business, (int) Auth::id(), self::IMPROVE_MAX_OUTPUT_TOKENS, $ledgerKey);
            $decoded = $raw !== null ? json_decode($raw, true) : null;
            $improved = is_array($decoded) ? ($decoded['body'] ?? null) : null;
            $budgetExhausted = $this->aiClient->lastCallWasBudgetExhausted();
        } catch (\Illuminate\Database\UniqueConstraintViolationException|\Illuminate\Database\QueryException) {
            // Independent-review correction round 4 (item 2) — AiGateway's
            // own documentation warns a reused ledger idempotency_key can
            // throw this. beginCustomSectionImprove()'s per-attempt
            // ordinal is specifically designed to make this vanishingly
            // unlikely, but a genuine collision converts to the SAME
            // friendly "could not improve" outcome a provider failure
            // already produces, never a raw 500.
            $improved = null;
        }

        // Independent-review correction round 2 — the output bound is
        // enforced HERE, after decoding, regardless of whether the
        // prompt asked the model to stay under it: an oversized response
        // is treated exactly like a refusal.
        $validImprovement = is_string($improved) && trim($improved) !== '' && mb_strlen(trim($improved)) <= self::CUSTOM_SECTION_BODY_MAX
            ? trim($improved)
            : null;

        $complete = $this->sessionManager->completeCustomSectionImprove($response, $stepKey, $logicalKey, $ledgerKey, $validImprovement);

        return match ($complete['outcome']) {
            'succeeded' => redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'success',
                'message' => 'Your custom section copy was improved.',
            ]),
            'stale', 'stale_edit' => redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => 'Your text changed since this request started, so the improvement was discarded. Your current text is unchanged.',
            ]),
            default => redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $budgetExhausted
                    ? 'The included AI generation budget is used up for this period.'
                    : 'Could not improve this section right now — your current text is unchanged.',
            ]),
        };
    }

    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function inProgressResponse(Business $business, QuestionnaireDefinition $definition): ?QuestionnaireResponse
    {
        return QuestionnaireResponse::where('business_id', $business->id)
            ->where('status', 'in_progress')
            ->where('questionnaire_definition_id', $definition->id)
            ->first();
    }

    /**
     * Resolves the Business's niche and its in-progress response
     * together — the one path every gallery/custom-section/answer action
     * below shares. `allowMissing` lets generate() distinguish "no
     * session at all" (redirect to start) from every other caller, which
     * treats a missing session as a hard 404.
     */
    private function currentResponseOrFail(Business $business, bool $allowMissing = false): ?QuestionnaireResponse
    {
        $definition = $this->questionnaireResolver->resolveForBusiness($business);

        if ($definition === null) {
            if ($allowMissing) {
                return null;
            }

            abort(404);
        }

        $response = $this->inProgressResponse($business, $definition);

        if ($response === null && ! $allowMissing) {
            abort(404);
        }

        return $response;
    }

    /**
     * @return Collection<int, WebsiteTemplate>
     */
    private function templatesForNiche(?string $nicheKey): Collection
    {
        return WebsiteTemplate::where('is_active', true)
            ->where(function ($query) use ($nicheKey) {
                $query->whereNull('niche_key');

                if ($nicheKey !== null) {
                    $query->orWhere('niche_key', $nicheKey);
                }
            })
            ->orderBy('key')
            ->get();
    }

    private function unavailableView(string $workspaceUid, string $businessUid): View
    {
        return view('customer.business.website.setup-unavailable', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
        ]);
    }

    /**
     * Independent-review correction round — a STABLE key derived from
     * durable identity (the pinned response, its pinned version, the
     * chosen template, and the current answers revision), replacing a
     * fresh random uuid on every POST. GuidedGenerationCommitService
     * folds this into its own material-hash idempotency base; a caller
     * key that changes on every submission defeated that entirely, since
     * two otherwise-identical submissions would never look identical.
     * The answers_revision is included so a genuine content change
     * (which bumps the revision) is correctly treated as a NEW request,
     * never conflated with a stale retry.
     */
    private function stableIdempotencyKey(QuestionnaireResponse $response, Website $website): string
    {
        return implode(':', [
            $response->uid,
            $response->questionnaire_version_id,
            $website->template_key,
            $response->answers_revision,
        ]);
    }

    /**
     * @return array{key: string, name: string, description: ?string, body: ?string, layout: string, images: array<int, string>}
     */
    private function currentCustomSectionEntry(QuestionnaireResponse $response, string $answerKey): array
    {
        $entries = $response->answer($answerKey);
        $entry = is_array($entries) && isset($entries[0]) && is_array($entries[0]) ? $entries[0] : [];

        return [
            'key' => (string) ($entry['key'] ?? Str::random(12)),
            'name' => (string) ($entry['name'] ?? ''),
            'description' => $entry['description'] ?? null,
            'body' => $entry['body'] ?? null,
            'layout' => in_array($entry['layout'] ?? null, ['stacked', 'image_left', 'image_right', 'grid'], true) ? $entry['layout'] : 'stacked',
            'images' => is_array($entry['images'] ?? null) ? array_values($entry['images']) : [],
        ];
    }

    /**
     * The wizard's HTML posts answers as plain form fields; this maps the
     * request's raw input into the shape each step's target_module
     * expects (a scalar for a simple field, or a re-indexed array of
     * structured entries for a repeatable_group question) — never a
     * question-by-question hardcoded parse, so a niche-authored
     * questionnaire the platform owner later adds still works with this
     * same generic handling.
     *
     * Independent-review correction round — `$step` is now required
     * (callers resolve and 404 on an unknown/hidden step before this
     * runs, rather than this method silently accepting any raw value for
     * one), and a `boolean` question whose `value` field was never
     * submitted at all now returns null rather than defaulting to false —
     * a required boolean with no explicit choice must fail validation,
     * never silently become "No."
     */
    private function valueFromRequest(Request $request, array $step, ?Website $website = null): mixed
    {
        if ($step['input_type'] === 'repeatable_group') {
            $nonEmptyCheck = $this->repeatableItemNonEmptyCheck($step);
            $items = array_values(array_filter((array) $request->input('items', []), fn ($item) => is_array($item) && $nonEmptyCheck($item)));

            // Independent-review correction round 3 (item 9) — v1
            // supports exactly ONE custom section; a forged/legacy
            // multi-entry submission is safely normalized to its first
            // entry rather than silently inviting the owner to fill in
            // content that generation would ignore anyway.
            if ($step['target_module'] === 'custom_section') {
                $items = array_slice($items, 0, 1);
            }

            return array_values(array_map(fn (array $item) => $this->normalizeRepeatableItem($item, $step, $website), $items));
        }

        if ($step['input_type'] === 'string_list') {
            // One entry per row — never split apart by a delimiter. Order
            // is the owner's priority and is preserved.
            $raw = $request->input('value', []);

            return ServiceAreaList::normalize(is_array($raw) ? $raw : [$raw]);
        }

        if ($step['input_type'] === 'multi_select') {
            return array_values((array) $request->input('value', []));
        }

        if ($step['input_type'] === 'boolean') {
            return $request->has('value') ? $request->boolean('value') : null;
        }

        return $request->input('value');
    }

    /**
     * A repeatable-group row is "worth keeping" when its OWN shape's
     * primary field is non-blank — a testimonial needs a quote, an FAQ
     * entry needs a question, everything else needs a name. Matches
     * normalizeRepeatableItem()'s own per-shape branching below.
     */
    /**
     * One step's answer value. A catalog selection is the one answer that
     * writes through to a canonical module while it is being saved (the
     * package edits/creations land in Packages & Products immediately and
     * the answer keeps only the resulting uids).
     */
    private function stepValue(Request $request, array $step, ?Website $website, Business $business): mixed
    {
        if ($step['input_type'] === 'catalog_selection') {
            return $this->packageManager->sync($business, array_values((array) $request->input('items', [])), (int) Auth::id());
        }

        return $this->valueFromRequest($request, $step, $website);
    }

    /**
     * Live canonical package rows for any catalog_selection step on the
     * screen (never read back from stored answers).
     *
     * @param  array<int, array<string, mixed>>  $screen
     * @param  array<string, mixed>  $answers
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function packageRowsFor(Business $business, array $screen, array $answers): array
    {
        $rows = [];

        foreach ($screen as $screenStep) {
            if ($screenStep['input_type'] === 'catalog_selection') {
                $rows[$screenStep['key']] = $this->packageManager->rowsFor($business, $answers[$screenStep['key']] ?? null);
            }
        }

        return $rows;
    }

    private function repeatableItemNonEmptyCheck(array $step): \Closure
    {
        if ($step['target_module'] === 'faq') {
            return fn (array $item) => trim((string) ($item['question'] ?? '')) !== '';
        }

        if ($step['target_module'] === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials') {
            return fn (array $item) => trim((string) ($item['quote'] ?? '')) !== '';
        }

        return fn (array $item) => trim((string) ($item['name'] ?? '')) !== '';
    }

    /**
     * The HTML form's own field names/shapes (dollars, newline-separated
     * features, a checkbox's presence) are a presentation concern —
     * normalized here into exactly what WebsiteSetupAnswerApplier
     * expects for each target_module, so the applier itself only ever
     * deals in already-well-shaped domain values.
     *
     * Independent-review correction round 2 — testimonials
     * (target_module='knowledge_profile', target_field='testimonials')
     * and FAQ entries (target_module='faq') each get their OWN real
     * field shape (quote/author_name/author_title; question/answer)
     * instead of being forced through the generic name/description
     * shape those two canonical destinations never accept.
     */
    private function normalizeRepeatableItem(array $item, array $step, ?Website $website = null): array
    {
        $targetModule = $step['target_module'];
        $key = (string) ($item['key'] ?? Str::random(12));

        if ($targetModule === 'faq') {
            return [
                'key' => $key,
                'question' => trim((string) ($item['question'] ?? '')),
                'answer' => trim((string) ($item['answer'] ?? '')),
            ];
        }

        if ($targetModule === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials') {
            return [
                'key' => $key,
                'quote' => trim((string) ($item['quote'] ?? '')),
                'author_name' => trim((string) ($item['author_name'] ?? '')),
                'author_title' => trim((string) ($item['author_title'] ?? '')) ?: null,
            ];
        }

        $normalized = [
            'key' => $key,
            'name' => trim((string) ($item['name'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')) ?: null,
        ];

        if ($targetModule === 'catalog_item') {
            $price = trim((string) ($item['price'] ?? ''));
            $normalized['price_minor'] = $price !== '' ? (int) round(((float) $price) * 100) : null;
            $normalized['currency_code'] = $normalized['price_minor'] !== null ? strtoupper(trim((string) ($item['currency_code'] ?? 'USD'))) : null;
            $normalized['featured'] = ! empty($item['featured']);
            // One feature per row (`features[]`); the legacy one-per-line
            // textarea (`features_text`) is still read for v1 sessions.
            $normalized['features'] = is_array($item['features'] ?? null)
                ? array_values(array_filter(array_map(fn ($f) => trim((string) $f), $item['features']), fn ($f) => $f !== ''))
                : array_values(array_filter(array_map('trim', explode("\n", (string) ($item['features_text'] ?? '')))));
            $normalized['image'] = null; // per-item package image upload is an explicitly deferred follow-up — not wired into this generic form
        }

        if ($targetModule === 'backdrop') {
            $normalized['availability'] = ! empty($item['availability']);
            $normalized['images'] = [];

            // A category-aware (v2) backdrop picks its category from the
            // niche vocabulary and carries ONE picture: the image was
            // already stored (immediately, on selection) by the Business
            // image store; only its path travels here and every fact about
            // the file is re-derived from the file itself.
            if (isset($step['categories'])) {
                $normalized['category'] = trim((string) ($item['category'] ?? '')) ?: null;

                $altText = trim((string) ($item['alt_text'] ?? '')) ?: null;
                $normalized['alt_text'] = $altText;

                $imagePath = trim((string) ($item['image_path'] ?? ''));
                $normalized['image_path'] = $imagePath !== '' ? $imagePath : null;

                if ($imagePath !== '') {
                    $business = $website?->business;
                    $described = $business !== null ? $this->businessImages->describe($business, $imagePath) : null;

                    if ($described === null) {
                        throw new InvalidAnswerException('A backdrop image is no longer available — add it again.');
                    }

                    $categoryLabel = $normalized['category'] !== null ? ($step['categories'][$normalized['category']] ?? null) : null;
                    $normalized['images'] = [$described + [
                        'alt_text' => $altText ?? ImageAltText::suggest($normalized['name'], $categoryLabel, $business?->name),
                    ]];
                }
            }
        }

        if ($targetModule === 'custom_section') {
            $normalized['body'] = trim((string) ($item['body'] ?? '')) ?: null;
            $normalized['layout'] = in_array($item['layout'] ?? null, ['stacked', 'image_left', 'image_right', 'grid'], true) ? $item['layout'] : 'stacked';
            $images = array_values(array_filter((array) ($item['images'] ?? []), fn ($v) => is_string($v) && $v !== ''));
            $normalized['images'] = $website !== null ? $this->assertOwnedCustomSectionImages($website, $images) : $images;
        }

        return $normalized;
    }

    /**
     * Independent-review correction round 3 (item 6) — a submitted
     * custom-section `images[]` uid is untrusted client input (it
     * arrives as a plain hidden form field the browser can forge or
     * replay): every uid must belong to THIS Website and carry purpose
     * `custom_section` specifically, or the whole answer is refused here
     * rather than silently accepting a gallery/package-mirror/foreign-
     * Website image into a section it was never uploaded for.
     *
     * @param  array<int, string>  $uids
     * @return array<int, string>
     * @throws InvalidAnswerException
     */
    private function assertOwnedCustomSectionImages(Website $website, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $owned = WebsiteAsset::where('website_id', $website->id)
            ->where('purpose', WebsiteAssetPurpose::CustomSection->value)
            ->whereIn('uid', $uids)
            ->pluck('uid')
            ->all();

        if (count($owned) !== count(array_unique($uids))) {
            throw new InvalidAnswerException('One of the selected images is no longer available for this custom section.');
        }

        return $uids;
    }

    /**
     * Independent-review correction round 4 (item 4) — resolves the
     * REAL, currently-visible step for a given target_module from the
     * response's own pinned version, rather than assuming any niche's
     * custom-section/gallery/FAQ step is literally keyed
     * 'custom_section'/'gallery'/'faq_items'. Used both as a 404 guard
     * (a niche whose CURRENT definition has no such step, or one this
     * response's own answers currently hide, must never let a client
     * mutate an answer key with nothing behind it) and as the single
     * source of the real answer-storage/redirect key every caller below
     * uses instead of a hardcoded literal.
     */
    private function stepKeyForModule(QuestionnaireResponse $response, string $targetModule): string
    {
        $visible = $this->stepResolver->visibleSteps($response->version->steps(), $response->answers ?? []);
        $step = collect($visible)->firstWhere('target_module', $targetModule);

        abort_unless($step !== null, 404);

        return $step['key'];
    }

    /**
     * Independent-review correction round 3 (item 11) — the narrowest
     * safe behavior for a post-generation presentation edit: never
     * silently discarded, but also never claimed as "applied to your
     * live pages" until the owner takes the existing, explicit Rebuild
     * action (GuidedGenerationCommitService clears this the moment a
     * generation/rebuild actually commits). A no-op before any pages
     * exist — nothing is "pending" for a website that was never
     * generated yet.
     */
    private function markPresentationChangePending(Website $website): void
    {
        if ($website->pages()->exists()) {
            $website->forceFill(['presentation_changes_pending_at' => now()])->save();
        }
    }

}
