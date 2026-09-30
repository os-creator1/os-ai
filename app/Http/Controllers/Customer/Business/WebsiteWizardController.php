<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\Setup\Exceptions\AnswerRevisionConflictException;
use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * Once a Website row exists, this controller's `start()`/`show()` always
 * redirect to Website Studio — the wizard never re-runs for an existing
 * website except through the distinct, explicit editSetupAnswers() action.
 */
class WebsiteWizardController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const TEMPLATE_STEP = 'template';

    public function __construct(
        private readonly WebsiteStarterDraftService $starterDrafts,
        private readonly WebsiteSetupSessionManager $sessionManager,
        private readonly QuestionnaireStepResolver $stepResolver,
        private readonly WebsiteSetupAnswerApplier $answerApplier,
        private readonly GuidedGenerationCommitService $guidedGeneration,
    ) {
    }

    public function start(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        // An in-progress session always wins — resume exactly where it
        // left off, even if the Website shell row already exists (it is
        // deliberately created early, at the template step, so an
        // existing shell alone must never bounce an active session back
        // to Studio).
        $response = $this->inProgressResponse($business);
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
        $response = $this->inProgressResponse($business);

        if ($stepKey === self::TEMPLATE_STEP) {
            // An in-progress session already exists — the template was
            // already chosen; send them to their real current step
            // instead of letting them re-choose mid-flow.
            if ($response !== null) {
                return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
            }

            if (Website::where('business_id', $business->id)->exists()) {
                return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
            }

            return view('customer.business.website.wizard.steps.template', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'templates' => WebsiteTemplate::where('is_active', true)->orderBy('key')->get(),
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

        // Idempotent: a session already in progress (e.g. a double
        // submit of this same form) resumes rather than creating a
        // second Website shell or a second questionnaire session.
        $existingResponse = $this->inProgressResponse($business);
        if ($existingResponse !== null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $existingResponse->current_step_key]);
        }

        if (Website::where('business_id', $business->id)->exists()) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid]);
        }

        $request->validate(['template_key' => 'required|string|max:40']);

        $template = WebsiteTemplate::where('key', $request->input('template_key'))->where('is_active', true)->first();

        if ($template === null) {
            throw ValidationException::withMessages(['template_key' => ['Choose one of the available templates.']]);
        }

        $website = $this->starterDrafts->createShellFromTemplate($business, $template);
        $response = $this->sessionManager->start($business, PhotoboothWebsiteSetupQuestionnaireSeeder::KEY, $website->id);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    public function autosaveAnswer(Request $request, string $workspaceUid, string $businessUid, string $stepKey): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->inProgressResponse($business) ?? abort(404);

        $value = $this->valueFromRequest($request, $response, $stepKey);
        $expectedRevision = (int) $request->input('answers_revision', $response->answers_revision);

        try {
            $response = $this->sessionManager->saveAnswer($response, $stepKey, $value, $expectedRevision);
        } catch (AnswerRevisionConflictException $e) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $stepKey])->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        if ($this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])
            && $this->stepResolver->nextStepKey($response->version->steps(), $response->answers ?? [], $stepKey) === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key]);
    }

    public function goBack(string $workspaceUid, string $businessUid, string $stepKey): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->inProgressResponse($business) ?? abort(404);

        $previousStepKey = $this->stepResolver->previousStepKey($response->version->steps(), $response->answers ?? [], $stepKey);

        if ($previousStepKey === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, self::TEMPLATE_STEP]);
        }

        $this->sessionManager->goToStep($response, $previousStepKey);

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]);
    }

    public function reviewGenerate(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->inProgressResponse($business);

        if ($response === null || ! $this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        return view('customer.business.website.wizard.steps.generating', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
        ]);
    }

    public function generate(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $response = $this->inProgressResponse($business);

        if ($response === null) {
            return redirect()->route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]);
        }

        if (! $this->stepResolver->isComplete($response->version->steps(), $response->answers ?? [])) {
            return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) $response->current_step_key])->with([
                'status' => 'error',
                'message' => 'Answer every required question before generating your website.',
            ]);
        }

        $website = $response->website ?? abort(404);
        $template = WebsiteTemplate::where('key', $website->template_key)->where('is_active', true)->firstOrFail();

        $actorUserId = (int) Auth::id();
        $response = $this->sessionManager->complete($response);
        $this->answerApplier->apply($business, $website, $response, $actorUserId);

        $customSection = $this->customSectionFromAnswers($response);

        $attempt = $this->guidedGeneration->generateFull($business, $website->fresh(), $template, $actorUserId, (string) Str::uuid(), $customSection);

        if ($attempt->status !== WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED) {
            return redirect()->route('customer.workspaces.businesses.website.setup.review', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => $attempt->failure_reason ?? 'Generation did not complete. Try again.',
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Your website is ready! Review it below, then connect a domain or publish whenever you\'re ready.',
        ]);
    }

    /**
     * The required "Edit setup answers" action for an EXISTING website:
     * reopens the same completed response (never a new one) so the owner
     * can change answers; finishing only re-applies changed facts into
     * the canonical records (WebsiteSetupAnswerApplier is idempotent by
     * source key) — it never itself regenerates page content, so manual
     * page edits are never silently overwritten. The owner uses the
     * existing, unchanged Rebuild action afterward if they want the
     * site's copy to reflect the change.
     */
    public function editSetupAnswers(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = Website::where('business_id', $business->id)->first() ?? abort(404);

        $response = QuestionnaireResponse::where('business_id', $business->id)
            ->where('questionnaire_definition_id', function ($query) {
                $query->select('id')->from('questionnaire_definitions')->where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY);
            })
            ->latest('id')
            ->first();

        if ($response === null) {
            return redirect()->route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid])->with([
                'status' => 'error',
                'message' => 'No setup answers were found for this website.',
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, (string) ($response->current_step_key ?? $this->stepResolver->firstStepKey($response->version->steps(), $response->answers ?? []))]);
    }

    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function inProgressResponse(Business $business): ?QuestionnaireResponse
    {
        return QuestionnaireResponse::where('business_id', $business->id)
            ->where('status', 'in_progress')
            ->where('questionnaire_definition_id', function ($query) {
                $query->select('id')->from('questionnaire_definitions')->where('key', PhotoboothWebsiteSetupQuestionnaireSeeder::KEY);
            })
            ->first();
    }

    /**
     * The wizard's HTML posts answers as plain form fields; this maps the
     * request's raw input into the shape each step's target_module
     * expects (a scalar for a simple field, or a re-indexed array of
     * structured entries for a repeatable_group question) — never a
     * question-by-question hardcoded parse, so a niche-authored
     * questionnaire the platform owner later adds still works with this
     * same generic handling.
     */
    private function valueFromRequest(Request $request, QuestionnaireResponse $response, string $stepKey): mixed
    {
        $step = $this->stepResolver->stepAt($response->version->steps(), $response->answers ?? [], $stepKey);

        if ($step !== null && $step['input_type'] === 'repeatable_group') {
            $items = array_filter((array) $request->input('items', []), fn ($item) => is_array($item) && trim((string) ($item['name'] ?? '')) !== '');

            return array_values(array_map(fn (array $item) => $this->normalizeRepeatableItem($item, $step['target_module']), $items));
        }

        if ($step !== null && $step['input_type'] === 'multi_select') {
            return array_values((array) $request->input('value', []));
        }

        if ($step !== null && $step['input_type'] === 'boolean') {
            return $request->boolean('value');
        }

        return $request->input('value');
    }

    /**
     * The HTML form's own field names/shapes (dollars, newline-separated
     * features, a checkbox's presence) are a presentation concern —
     * normalized here into exactly what WebsiteSetupAnswerApplier
     * expects for each target_module, so the applier itself only ever
     * deals in already-well-shaped domain values.
     */
    private function normalizeRepeatableItem(array $item, string $targetModule): array
    {
        $normalized = [
            'key' => (string) ($item['key'] ?? Str::random(12)),
            'name' => trim((string) ($item['name'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')) ?: null,
        ];

        if ($targetModule === 'catalog_item') {
            $price = trim((string) ($item['price'] ?? ''));
            $normalized['price_minor'] = $price !== '' ? (int) round(((float) $price) * 100) : null;
            $normalized['currency_code'] = $normalized['price_minor'] !== null ? strtoupper(trim((string) ($item['currency_code'] ?? 'USD'))) : null;
            $normalized['featured'] = ! empty($item['featured']);
            $normalized['features'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($item['features_text'] ?? '')))));
            $normalized['image'] = null; // image upload for a package is a separate, dedicated step — not this generic form
        }

        if ($targetModule === 'backdrop') {
            $normalized['availability'] = ! empty($item['availability']);
            $normalized['images'] = [];
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
}
