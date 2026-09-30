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
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use App\Library\Website\Setup\QuestionnaireAnswerValidator;
use App\Library\Website\Setup\QuestionnaireResolver;
use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        // An in-progress session always wins (this covers a reopened
        // edit_mode session too — see class docblock) — resume exactly
        // where it left off, even if the Website shell row already
        // exists (it is deliberately created early, at the template
        // step, so an existing shell alone must never bounce an active
        // session back to Studio).
        $response = $this->inProgressResponse($business, $definition);
        if ($response !== null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
        }

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, self::TEMPLATE_STEP]);
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

            if (Website::where('business_id', $business->id)->exists()) {
                return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
            }

            return view('customer.business.website.wizard.steps.template', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'templates' => $this->templatesForNiche($this->questionnaireResolver->nicheKeyFor($business)),
                'selectedTemplateKey' => null,
                'progress' => ['current' => 1, 'total' => 2, 'label' => 'Choose a style'],
            ]);
        }

        if ($response === null) {
            // No in-progress session at all — nothing to answer yet
            // (or setup already finished elsewhere); route through
            // start() to decide the right destination.
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        $step = $this->stepResolver->stepAt($response->version->steps(), $response->answers ?? [], $stepKey);

        if ($step === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
        }

        $visibleSteps = $this->stepResolver->visibleSteps($response->version->steps(), $response->answers ?? []);
        $position = array_search($stepKey, array_column($visibleSteps, 'key'), true);
        $previousStepKey = $this->stepResolver->previousStepKey($response->version->steps(), $response->answers ?? [], $stepKey);

        return view('customer.business.website.wizard.steps.question', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'response' => $response,
            'website' => $response->website,
            'step' => $step,
            'previousStepKey' => $previousStepKey ?? self::TEMPLATE_STEP,
            'answer' => $response->answer($stepKey),
            'progress' => ['current' => $position !== false ? $position + 2 : 2, 'total' => count($visibleSteps) + 2, 'label' => $step['prompt']],
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

            if ($website->template_key !== $template->key) {
                $this->starterDrafts->updateShellTemplate($website, $template);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $existingResponse->current_step_key]);
        }

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
        }

        $website = $this->starterDrafts->createShellFromTemplate($business, $template);
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

        $value = $this->valueFromRequest($request, $step);

        try {
            $this->answerValidator->validate($step, $value);
        } catch (InvalidAnswerException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $expectedRevision = (int) $request->input('answers_revision', $response->answers_revision);

        try {
            $response = $this->sessionManager->saveAnswer($response, $stepKey, $value, $expectedRevision);
        } catch (AnswerRevisionConflictException|GenerationInProgressException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        if ($this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])
            && $this->stepResolver->nextStepKey($response->version->steps(), $response->answers ?? [], $stepKey) === null) {
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

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, self::TEMPLATE_STEP]);
        }

        $this->sessionManager->goToStep($response, $previousStepKey);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]);
    }

    public function reviewGenerate(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $definition = $this->questionnaireResolver->resolveForBusiness($business);
        $response = $definition !== null ? $this->inProgressResponse($business, $definition) : null;

        if ($response === null || ! $this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        return view('customer.business.website.wizard.steps.generating', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'editMode' => $response->edit_mode,
            'answersRevision' => $response->answers_revision,
        ]);
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

        $lock = Cache::lock('website-setup-generate:' . $response->id, 60);

        if (! $lock->get()) {
            return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => 'Your website is already being generated. Please wait a moment and check back.',
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

            $this->answerApplier->apply($business, $website, $response, $actorUserId);

            if ($response->edit_mode) {
                $this->sessionManager->completeEdit($response);

                return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid])->with([
                    'status' => 'success',
                    'message' => 'Your setup answers were updated.',
                ]);
            }

            $template = WebsiteTemplate::where('key', $website->template_key)->where('is_active', true)->firstOrFail();
            $customSection = $this->customSectionFromAnswers($response);
            $customerFaq = $this->customerFaqFromAnswers($response);
            $idempotencyKey = $this->stableIdempotencyKey($response, $website);

            $attempt = $this->guidedGeneration->generateFull($business, $website->fresh(), $template, $actorUserId, $idempotencyKey, $customSection, $customerFaq);

            if ($attempt->status !== WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED) {
                $this->sessionManager->recordGenerationFailure($response);

                return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                    'status' => 'error',
                    'message' => $attempt->failure_reason ?? 'Generation did not complete. Try again.',
                ]);
            }

            $this->sessionManager->complete($response);

            return redirect()->route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid])->with([
                'status' => 'success',
                'message' => 'Your website is ready! Review it below, then connect a domain or publish whenever you\'re ready.',
            ]);
        } finally {
            $lock->release();
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
    public function uploadGalleryPhotos(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);

        $request->validate([
            'photos' => 'required|array|max:20',
            'photos.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
        ]);

        try {
            $this->gallery->uploadMany($website, $request->file('photos', []), WebsiteAssetPurpose::Gallery, $request->input('category_tag'));
        } catch (InvalidWebsiteAssetException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery'])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery']);
    }

    public function updateGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::Gallery->value)->firstOrFail();

        $request->validate(['title' => 'nullable|string|max:160', 'category_tag' => 'nullable|string|max:80', 'alt_text' => 'nullable|string|max:160']);
        $this->gallery->setTitleAndCategory($website, $asset, WebsiteAssetPurpose::Gallery, $request->input('title'), $request->input('category_tag'), $request->input('alt_text'));

        if ($request->boolean('is_cover')) {
            $this->gallery->setCover($website, $asset);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery']);
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
    public function moveGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);

        $request->validate(['direction' => 'required|in:up,down']);

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

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery']);
    }

    /**
     * Independent-review correction round 2 — scoped to gallery-purpose
     * assets alone: this action can never delete a custom-section or
     * package-mirror image, whatever uid is given.
     */
    public function removeGalleryPhoto(string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::Gallery->value)->firstOrFail();

        try {
            app(WebsiteAssetUploadService::class)->delete($website, $asset);
        } catch (ValidationException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery'])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'gallery']);
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
    public function uploadCustomSectionImage(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);

        $request->validate(['photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192']);

        try {
            $assets = $this->gallery->uploadMany($website, [$request->file('photo')], WebsiteAssetPurpose::CustomSection);
        } catch (InvalidWebsiteAssetException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $entry = $this->currentCustomSectionEntry($response);
        $entry['images'][] = $assets[0]->uid;

        $this->sessionManager->updateAnswerInPlace($response, 'custom_section', [$entry]);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section']);
    }

    public function removeCustomSectionImage(string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);
        $website = $response->website ?? abort(404);
        // Independent-review correction round 2 — scoped to the
        // custom-section purpose alone: a gallery photo's uid was never
        // in this answer's own images list, but this guard also refuses
        // deleting the FILE for one even if it somehow were.
        $asset = WebsiteAsset::where('website_id', $website->id)->where('uid', $assetUid)->where('purpose', WebsiteAssetPurpose::CustomSection->value)->first();

        $entry = $this->currentCustomSectionEntry($response);
        $entry['images'] = array_values(array_diff($entry['images'], [$assetUid]));
        $this->sessionManager->updateAnswerInPlace($response, 'custom_section', [$entry]);

        if ($asset !== null) {
            try {
                app(WebsiteAssetUploadService::class)->delete($website, $asset);
            } catch (ValidationException) {
                // Left in place (e.g. already published) — the answer no
                // longer references it either way.
            }
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section']);
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
    public function improveCustomSection(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->currentResponseOrFail($business);

        $submittedItems = array_filter((array) $request->input('items', []), fn ($item) => is_array($item));
        $submitted = array_values($submittedItems)[0] ?? [];
        $entry = $this->normalizeRepeatableItem(is_array($submitted) ? $submitted : [], ['target_module' => 'custom_section']);

        try {
            $this->answerValidator->validate(['input_type' => 'repeatable_group', 'required' => false, 'target_module' => 'custom_section'], [$entry]);
        } catch (InvalidAnswerException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        if (trim((string) $entry['body']) === '') {
            $this->sessionManager->updateAnswerInPlace($response, 'custom_section', [$entry]);

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                'status' => 'error',
                'message' => 'Write something in the body field before asking AI to improve it.',
            ]);
        }

        $contentHash = hash('sha256', (string) $entry['body']);
        $lockKey = 'website-setup-improve-custom-section:' . $response->id;
        $processedKey = $lockKey . ':done:' . $contentHash;

        return Cache::lock($lockKey, 30)->block(15, function () use ($workspaceUid, $businessUid, $business, $response, $entry, $processedKey) {
            // Independent-review correction round 2 — a double-click or a
            // concurrent identical request for the SAME submitted body
            // waits for this lock, then finds this marker already set and
            // never spends AI OR touches the persisted answer again: the
            // winning request's own improved text (already persisted
            // below, inside its own turn holding this lock) must never be
            // clobbered by a late duplicate re-persisting the pre-
            // improvement submitted text.
            if (Cache::has($processedKey)) {
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                    'status' => 'success',
                    'message' => 'Your custom section copy was improved.',
                ]);
            }

            // Persist the owner's own currently-typed text now — this is
            // what "preserve current text on refusal" means once AI has
            // not yet even been asked: there is nothing to roll back to
            // beyond what they just typed, and it must never be lost.
            // Deferred until after the duplicate check above so a late
            // duplicate of an ALREADY-improved submission never overwrites
            // the improvement with the pre-improvement text.
            $this->sessionManager->updateAnswerInPlace($response, 'custom_section', [$entry]);

            $messages = [
                ['role' => 'system', 'content' => 'You improve one short marketing section of a small business website. Respond with a single JSON object: {"body": string}. Keep the improved copy under ' . self::CUSTOM_SECTION_BODY_MAX . ' characters, factual, and free of invented claims.'],
                ['role' => 'user', 'content' => json_encode([
                    'business_name' => $business->name,
                    'section_title' => $entry['name'],
                    'current_body' => $entry['body'],
                ])],
            ];

            $raw = $this->aiClient->complete($messages, $business, (int) Auth::id());
            $decoded = $raw !== null ? json_decode($raw, true) : null;
            $improved = is_array($decoded) ? ($decoded['body'] ?? null) : null;

            // Independent-review correction round 2 — the output bound is
            // enforced HERE, after decoding, regardless of whether the
            // prompt asked the model to stay under it: an oversized
            // response is treated exactly like a refusal (the owner's
            // already-persisted, just-typed text is preserved, never a
            // silently truncated mid-sentence result).
            if (is_string($improved) && trim($improved) !== '' && mb_strlen(trim($improved)) <= self::CUSTOM_SECTION_BODY_MAX) {
                $entry['body'] = trim($improved);
                $this->sessionManager->updateAnswerInPlace($response, 'custom_section', [$entry]);
                Cache::put($processedKey, true, 60);

                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                    'status' => 'success',
                    'message' => 'Your custom section copy was improved.',
                ]);
            }

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, 'custom_section'])->with([
                'status' => 'error',
                'message' => $this->aiClient->lastCallWasBudgetExhausted()
                    ? 'The included AI generation budget is used up for this period.'
                    : 'Could not improve this section right now — your current text is unchanged.',
            ]);
        });
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
    private function currentCustomSectionEntry(QuestionnaireResponse $response): array
    {
        $entries = $response->answer('custom_section');
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
    private function valueFromRequest(Request $request, array $step): mixed
    {
        if ($step['input_type'] === 'repeatable_group') {
            $nonEmptyCheck = $this->repeatableItemNonEmptyCheck($step);
            $items = array_filter((array) $request->input('items', []), fn ($item) => is_array($item) && $nonEmptyCheck($item));

            return array_values(array_map(fn (array $item) => $this->normalizeRepeatableItem($item, $step), $items));
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
    private function normalizeRepeatableItem(array $item, array $step): array
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
            $normalized['features'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($item['features_text'] ?? '')))));
            $normalized['image'] = null; // per-item package image upload is an explicitly deferred follow-up — not wired into this generic form
        }

        if ($targetModule === 'backdrop') {
            $normalized['availability'] = ! empty($item['availability']);
            $normalized['images'] = []; // per-item backdrop image upload is an explicitly deferred follow-up — not wired into this generic form
        }

        if ($targetModule === 'custom_section') {
            $normalized['body'] = trim((string) ($item['body'] ?? '')) ?: null;
            $normalized['layout'] = in_array($item['layout'] ?? null, ['stacked', 'image_left', 'image_right', 'grid'], true) ? $item['layout'] : 'stacked';
            $normalized['images'] = array_values(array_filter((array) ($item['images'] ?? []), fn ($v) => is_string($v) && $v !== ''));
        }

        return $normalized;
    }

    /**
     * @return ?array{title: string, layout: string, body: ?string, images: array<int, string>}
     */
    private function customSectionFromAnswers(QuestionnaireResponse $response): ?array
    {
        $entries = $response->answer('custom_section');

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $entry = $entries[0];

        if (trim((string) ($entry['name'] ?? '')) === '') {
            return null;
        }

        return [
            'title' => (string) $entry['name'],
            'layout' => $entry['layout'] ?? 'stacked',
            'body' => $entry['body'] ?? null,
            'images' => $entry['images'] ?? [],
        ];
    }

    /**
     * @return ?array<int, array{question: string, answer: string}>
     */
    private function customerFaqFromAnswers(QuestionnaireResponse $response): ?array
    {
        $entries = $response->answer('faq_items');

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $pairs = array_values(array_filter(array_map(function ($entry) {
            if (! is_array($entry)) {
                return null;
            }

            $question = trim((string) ($entry['question'] ?? ''));
            $answer = trim((string) ($entry['answer'] ?? ''));

            return $question !== '' && $answer !== '' ? ['question' => $question, 'answer' => $answer] : null;
        }, $entries)));

        return $pairs === [] ? null : $pairs;
    }
}
