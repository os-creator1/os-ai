<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Contacts\ContactDirectory;
use App\Library\Crm\CrmBoard;
use App\Library\Crm\CrmBoardFilters;
use App\Library\Crm\CrmMoney;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * CRM Opportunities — a Business's sales board, its deals, and each deal's
 * history. (Not the AI COO Advisor, which lives at customer.opportunities.*.)
 *
 * TENANCY. Every request resolves the Workspace/Business pair through the shared
 * ResolvesBusinessTenancy chain, gated on the CRM platform feature, and then looks
 * every pipeline, stage, deal and contact up INSIDE that Business. Anything from
 * another Business is simply not found: the same 404 as an unknown uid.
 *
 * PERMISSIONS reuse the customer's contact permissions — a deal is always about a
 * contact: `view_contact` to see the board, `update_contact` to change anything.
 */
class CrmOpportunitiesController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public const VIEW_PERMISSION = 'view_contact';

    public const MANAGE_PERMISSION = 'update_contact';

    public function __construct(
        private readonly CrmBoard $board,
        private readonly CrmOpportunityService $opportunities,
        private readonly ContactDirectory $contacts,
    ) {
    }

    public function board(Request $request, string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::VIEW_PERMISSION);

        $pipelines = $this->board->pipelines($business);

        if ($pipelines->isEmpty()) {
            return view('customer.crm.setup');
        }

        $pipeline = $pipelines->firstWhere('uid', (string) $request->query('pipeline')) ?? $pipelines->first();
        $filters = CrmBoardFilters::fromRequest($request);

        return view('customer.crm.board', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'filters' => $filters,
            'board' => $this->board->board($business, $pipeline, $filters),
        ]);
    }

    public function create(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $pipelines = $this->board->pipelines($business);

        if ($pipelines->isEmpty()) {
            return redirect()->route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid]);
        }

        $pipeline = $pipelines->firstWhere('uid', (string) $request->query('pipeline', old('pipeline'))) ?? $pipelines->first();
        $contactUid = (string) $request->query('contact', (string) old('contact'));
        $contact = $contactUid !== '' ? $this->contacts->findForBusiness($business, $contactUid) : null;

        return view('customer.crm.create', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'stages' => $pipeline->activeStages()->get(),
            'startingStage' => $this->opportunities->startingStage($pipeline),
            'contact' => $contact === null ? null : ($this->contacts->summaries($business, [(int) $contact->id])[(int) $contact->id] ?? null),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:' . CrmOpportunityService::TITLE_MAX],
            'contact' => ['required', 'string', 'max:64'],
            'pipeline' => ['required', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:64'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:' . (CrmMoney::MAX_MINOR / 100)],
        ], [
            'contact.required' => 'Choose the contact this opportunity is with.',
        ]);

        $pipeline = $this->pipeline($business, $data['pipeline']);
        $contact = $this->contacts->findForBusiness($business, $data['contact']);

        if ($contact === null) {
            return back()->withInput()->withErrors(['contact' => 'Choose a contact of this Business.']);
        }

        $stage = empty($data['stage']) ? null : $this->stage($pipeline, $data['stage']);

        try {
            $opportunity = $this->opportunities->create($business, $pipeline, $contact, $data['title'], CrmMoney::toMinor($data['value'] ?? null), $stage, (int) Auth::id());
        } catch (CrmRuleException $exception) {
            return back()->withInput()->withErrors(['title' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid])
            ->with(['status' => 'success', 'message' => '"' . $opportunity->title . '" added to ' . $pipeline->name . '.']);
    }

    public function show(string $workspaceUid, string $businessUid, string $opportunityUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::VIEW_PERMISSION);

        $opportunity = $this->opportunity($business, $opportunityUid);
        $pipeline = CrmPipeline::query()->forBusiness($business)->find($opportunity->pipeline_id) ?? abort(404);

        return view('customer.crm.show', [
            'opportunity' => $opportunity,
            'pipeline' => $pipeline,
            'stage' => CrmPipelineStage::query()->where('business_id', $business->id)->find($opportunity->stage_id) ?? abort(404),
            'stages' => $pipeline->activeStages()->get(),
            'contact' => $opportunity->contact_id === null ? null : ($this->contacts->summaries($business, [(int) $opportunity->contact_id])[(int) $opportunity->contact_id] ?? null),
            'history' => $opportunity->history()->with('actor:id,first_name,last_name')->limit(100)->get(),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:' . CrmOpportunityService::TITLE_MAX],
            'value' => ['nullable', 'numeric', 'min:0', 'max:' . (CrmMoney::MAX_MINOR / 100)],
        ]);

        return $this->attempt(fn () => $this->opportunities->updateDetails($opportunity, $data['title'], CrmMoney::toMinor($data['value'] ?? null)), 'Saved.');
    }

    /**
     * Move to another stage: from the board's drag and drop (JSON) or the
     * detail page's form (redirect back). Same validation, same service.
     */
    public function move(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): JsonResponse|RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        $data = $request->validate(['stage' => ['required', 'string', 'max:64']]);
        $pipeline = CrmPipeline::query()->forBusiness($business)->find($opportunity->pipeline_id) ?? abort(404);
        $stage = $this->stage($pipeline, $data['stage']);

        try {
            $moved = $this->opportunities->moveToStage($opportunity, $stage, (int) Auth::id());
        } catch (CrmRuleException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->with(['status' => 'error', 'message' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['moved' => $moved, 'stage' => ['uid' => $stage->uid, 'name' => $stage->name]]);
        }

        return back()->with(['status' => 'success', 'message' => $moved ? 'Moved to ' . $stage->name . '.' : 'Already in ' . $stage->name . '.']);
    }

    public function won(string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        return $this->attempt(fn () => $this->opportunities->markWon($opportunity, (int) Auth::id()), 'Marked as won.');
    }

    public function lost(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        $data = $request->validate(['lost_reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->opportunities->markLost($opportunity, $data['lost_reason'] ?? null, (int) Auth::id()), 'Marked as lost.');
    }

    public function reopen(string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        return $this->attempt(fn () => $this->opportunities->reopen($opportunity, (int) Auth::id()), 'Reopened.');
    }

    public function contactStatus(Request $request, string $workspaceUid, string $businessUid, string $opportunityUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $opportunity = $this->opportunity($business, $opportunityUid);

        $data = $request->validate(['contact_status' => ['required', Rule::enum(CrmContactStatus::class)]]);
        $status = CrmContactStatus::from($data['contact_status']);

        return $this->attempt(fn () => $this->opportunities->setContactStatus($opportunity, $status, actorUserId: (int) Auth::id()), 'Contact status: ' . $status->label() . '.');
    }

    /** The "Add opportunity" contact picker: this Business's contacts only. */
    public function contactSearch(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json([
            'results' => array_map(fn (array $contact) => [
                'id' => $contact['uid'],
                'text' => $contact['name'] !== null ? $contact['name'] . ' · ' . $contact['phone'] : $contact['phone'],
            ], $this->contacts->search($business, $search, 20)),
        ]);
    }

    private function business(string $workspaceUid, string $businessUid): Business
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);

        return $business;
    }

    /**
     * Implementation Contract 08B — Location ACL. A deal with a proven
     * `location_id` is additionally re-checked against LocationAccessGuard,
     * re-derived from persistence, never a route-supplied value. A NULL
     * `location_id` is never guessed and never gates access on its own —
     * the actor's own Business-level access, already confirmed by
     * business(), governs exactly as it did before this contract. A denial
     * folds into the same 404 every other tenancy failure in this
     * controller already uses.
     */
    private function opportunity(Business $business, string $uid): CrmOpportunity
    {
        $opportunity = CrmOpportunity::query()->where('business_id', $business->id)->where('uid', $uid)->first() ?? abort(404);

        if ($opportunity->location_id !== null) {
            $location = $opportunity->location;

            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
                abort(404);
            }
        }

        return $opportunity;
    }

    private function pipeline(Business $business, string $uid): CrmPipeline
    {
        return CrmPipeline::query()->forBusiness($business)->active()->where('uid', $uid)->first() ?? abort(404);
    }

    private function stage(CrmPipeline $pipeline, string $uid): CrmPipelineStage
    {
        return CrmPipelineStage::query()
            ->where('business_id', $pipeline->business_id)
            ->where('pipeline_id', $pipeline->id)
            ->where('uid', $uid)
            ->first() ?? abort(404);
    }

    private function attempt(callable $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (CrmRuleException $exception) {
            return back()->with(['status' => 'error', 'message' => $exception->getMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => $success]);
    }
}
