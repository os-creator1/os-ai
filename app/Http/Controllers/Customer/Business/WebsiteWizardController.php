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
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
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
            WebsiteCreationStage::NotStarted => redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, self::TEMPLATE_STEP]),
        };
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

        try {
            $value = $this->valueFromRequest($request, $step, $response->website);
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

        // Independent-review correction round 3 (item 11) — FAQ and
        // custom-section answers are the two presentation-only surfaces
        // that reach real generated page content through
        // MediaBindingService, but only at generation/rebuild time. An
        // edit-mode session saving either after the website already has
        // generated pages must flag that a deliberate rebuild is still
        // needed — never silently imply the live pages already changed.
        if (in_array($step['target_module'], ['custom_section', 'faq'], true) && $response->website !== null) {
            $this->markPresentationChangePending($response->website);
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
                $visible = $this->stepResolver->visibleSteps($response->version->steps(), $response->answers ?? []);

                return $visible !== [] ? (string) end($visible)['key'] : (string) $response->current_step_key;
            })(),
            'lastAttemptFailed' => $lastAttempt !== null && $lastAttempt->status === WebsiteGuidedGenerationAttempt::STATUS_FAILED,
            'generationAvailable' => (bool) config('services.openai.active'),
            'answerSummary' => $this->answerSummary($response),
        ]);
    }

    /**
     * A concise, human review of what the owner answered, one line per
     * answered question in questionnaire order (hidden/conditional steps
     * excluded) — shown on the review screen so "Generate my website" is
     * a confident final click, never a blind one.
     *
     * Grouped under three plain headings so a long setup reads as a
     * summary, not a form dump; select / multi-select answers show their
     * human labels, never their stored keys.
     *
     * @return array<string, array<int, array{prompt: string, value: string}>>  heading => rows (empty groups omitted)
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
        $answers = $response->answers ?? [];

        foreach ($this->stepResolver->visibleSteps($response->version->steps(), $answers) as $step) {
            $value = $answers[$step['key']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $options = is_array($step['options'] ?? null) ? $step['options'] : [];
            $label = fn ($item) => (string) ($options[(string) $item] ?? $item);

            if (is_array($value)) {
                $parts = array_map(function ($item) use ($label) {
                    if (is_array($item)) {
                        return (string) ($item['name'] ?? $item['question'] ?? $item['quote'] ?? '');
                    }

                    return $label($item);
                }, $value);
                $text = implode(', ', array_filter($parts, fn ($p) => $p !== ''));
            } elseif (is_bool($value)) {
                $text = $value ? 'Yes' : 'No';
            } else {
                $text = $label($value);
            }

            if ($text !== '') {
                $heading = $groupOf[$step['target_module'] ?? ''] ?? 'Contact & other';
                $groups[$heading][] = ['prompt' => (string) $step['prompt'], 'value' => \Illuminate\Support\Str::limit($text, 140)];
            }
        }

        return array_filter($groups);
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

                $attempt = $this->guidedGeneration->generateFull($business, $website->fresh(), $template, $actorUserId, $idempotencyKey, $leaseToken, $customSection, $customerFaq);

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
    public function uploadGalleryPhotos(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
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

        try {
            // Independent-review correction round 4 (items 1, 6) — the
            // gallery step's own visibility is RE-verified under the
            // SAME lock as the generation-freeze check, immediately
            // before anything is written, including a file to disk —
            // never merely checked once, earlier, against a read that
            // could already be stale by the time the mutation runs.
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($website, $request, &$createdAssetPaths) {
                $this->stepKeyForModule($locked, 'gallery');
                $assets = $this->gallery->uploadMany($website, $request->file('photos', []), WebsiteAssetPurpose::Gallery, $request->input('category_tag'));
                foreach ($assets as $asset) {
                    $createdAssetPaths[] = $asset->path;
                }
            });
        } catch (InvalidWebsiteAssetException|GenerationInProgressException $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            throw $e;
        }

        $this->markPresentationChangePending($website);

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
    public function updateGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
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
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

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
    public function moveGalleryPhoto(Request $request, string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
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
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
    }

    /**
     * Independent-review correction round 2/3 — scoped to gallery-purpose
     * assets alone: this action can never delete a custom-section or
     * package-mirror image, whatever uid is given (a wrong-purpose or
     * foreign uid explicitly 404s below, never a silent no-op).
     */
    public function removeGalleryPhoto(string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
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
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

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
    public function uploadCustomSectionImage(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
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

        try {
            $this->sessionManager->runIfNotGenerating($response, function (QuestionnaireResponse $locked) use ($stepKey, $website, $request, &$createdAssetPaths) {
                $this->stepKeyForModule($locked, 'custom_section');

                $assets = $this->gallery->uploadMany($website, [$request->file('photo')], WebsiteAssetPurpose::CustomSection);
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

            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->deleteOrphanedAssetFiles($createdAssetPaths);

            throw $e;
        }

        $this->markPresentationChangePending($website);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey]);
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
        }
    }

    public function removeCustomSectionImage(string $workspaceUid, string $businessUid, string $assetUid): RedirectResponse
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
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $this->markPresentationChangePending($website);

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
