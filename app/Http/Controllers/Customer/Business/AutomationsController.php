<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Automations\AutomationDefinitionRequest;
use App\Library\Automation\AutomationDefinitionValidator;
use App\Library\Automation\AutomationTriggerEvaluator;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Automation;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Senderid;
use App\Models\Workspace;
use App\Repositories\Contracts\AutomationsRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * B4 Business Automations — the Business-scoped product surface
 * (contract §2, §12, §14).
 *
 * Every action, without exception, runs the mandatory chain (§2.2):
 * Workspace by UID → Business inside that Workspace →
 * WorkspaceManager::userCanAccessBusiness() → Business-scoped
 * EntitlementManager::decide() for PlatformFeature::Automations → the
 * Automation resolved INSIDE that Business. A foreign Workspace, foreign
 * Business, or foreign Automation uid fails closed as 404 exactly like a
 * nonexistent one. Auth::id() appears only as the capability/audit actor —
 * never as tenant identity; there is no primary-Business inference and no
 * LegacyBusinessResolver anywhere here.
 *
 * The listing method is deliberately named listing(), not index():
 * CustomerBaseController::index() takes zero parameters, so an
 * index(string ...) override is a fatal LSP error (contract §8).
 */
class AutomationsController extends CustomerBaseController
{
    public function __construct(
        private readonly AutomationsRepository $automations,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly AutomationDefinitionValidator $definitions,
    ) {
    }

    public function listing(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $automations = Automation::query()
            ->where('business_id', $business->id)
            ->withMax('executions', 'created_at')
            ->orderByDesc('id')
            ->get();

        return view('customer.Automations.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'automations' => $automations,
        ]);
    }

    public function create(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        return view('customer.Automations.form', $this->formData($workspaceUid, $businessUid, $business, null));
    }

    public function store(AutomationDefinitionRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $definition = $this->buildDefinition($business, $request->validated());

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        $automation = Automation::create(array_merge($definition, [
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'status' => $request->boolean('enabled', true) ? Automation::STATUS_ACTIVE : Automation::STATUS_INACTIVE,
        ]));

        return redirect()
            ->route('customer.workspaces.businesses.automations.show', [$workspaceUid, $businessUid, $automation->uid])
            ->with(['status' => 'success', 'message' => 'Automation created.']);
    }

    public function show(string $workspaceUid, string $businessUid, string $automationUid): View|Factory|Application
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        $executions = $automation->executions()
            ->with('contact')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('customer.Automations.overview', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'automation' => $automation,
            'executions' => $executions,
        ]);
    }

    public function edit(string $workspaceUid, string $businessUid, string $automationUid): View|Factory|Application
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        return view('customer.Automations.form', $this->formData($workspaceUid, $businessUid, $business, $automation));
    }

    public function update(AutomationDefinitionRequest $request, string $workspaceUid, string $businessUid, string $automationUid): RedirectResponse
    {
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $definition = $this->buildDefinition($business, $request->validated());

        if ($definition instanceof RedirectResponse) {
            return $definition;
        }

        $automation->fill(array_merge($definition, [
            'status' => $request->boolean('enabled', true) ? Automation::STATUS_ACTIVE : Automation::STATUS_INACTIVE,
        ]))->save();

        return redirect()
            ->route('customer.workspaces.businesses.automations.show', [$workspaceUid, $businessUid, $automation->uid])
            ->with(['status' => 'success', 'message' => 'Automation updated.']);
    }

    public function enable(string $workspaceUid, string $businessUid, string $automationUid): RedirectResponse
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        if (! $automation->isRunnableDefinition()) {
            return $this->showError($workspaceUid, $businessUid, $automation, 'This automation has no valid trigger/action definition and cannot be enabled.');
        }

        $this->automations->enable($automation);

        return $this->showSuccess($workspaceUid, $businessUid, $automation, 'Automation enabled.');
    }

    public function disable(string $workspaceUid, string $businessUid, string $automationUid): RedirectResponse
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $this->automations->disable($automation);

        return $this->showSuccess($workspaceUid, $businessUid, $automation, 'Automation disabled.');
    }

    public function destroy(string $workspaceUid, string $businessUid, string $automationUid): RedirectResponse
    {
        $this->authorize('automations');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $automation = $this->resolveBusinessAutomation($business, $automationUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $this->automations->delete($automation);

        return redirect()
            ->route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Automation deleted.']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * RFC-003 §14.1 boundary, mirroring OutreachController::
     * resolveAccessibleBusiness() verbatim, plus (a) the same active-
     * Business/active-Workspace gate the background AutomationEligibility
     * applies (contract §2.2 — an inactive tenant has no automation surface
     * in either direction) and (b) the Business-scoped Automations
     * entitlement decision (§2.4). Every failure — unknown Workspace,
     * unknown Business, wrong Workspace, inaccessible Business, inactive
     * tenant, not entitled — is the same 404.
     *
     * @return array{0: Workspace, 1: Business}
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
            // (int) Auth::id() here is the audit/actor argument the
            // decision signature requires — never a tenancy decision.
            $decision = $this->entitlementManager->decide($workspace, $business, PlatformFeature::Automations->value, (int) Auth::id());
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $decision->allowed) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * Contract §12.1 — the automation is resolved THROUGH the already-
     * resolved Business; a foreign uid 404s exactly like an unknown one.
     * A legacy NULL-business row can never match this query (§3.5).
     */
    private function resolveBusinessAutomation(Business $business, string $automationUid): Automation
    {
        $automation = Automation::query()
            ->where('business_id', $business->id)
            ->where('uid', $automationUid)
            ->first();

        abort_unless($automation !== null, 404);

        return $automation;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|RedirectResponse
     */
    private function buildDefinition(Business $business, array $input): array|RedirectResponse
    {
        $triggerType = AutomationTriggerType::from($input['trigger_type']);
        $actionType = AutomationActionType::from($input['action_type']);

        try {
            $triggerConfig = $this->definitions->triggerConfig($business, $triggerType, $input);
            $actionConfig = $this->definitions->actionConfig($business, $actionType, $input);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return [
            'name' => $input['name'],
            'trigger_type' => $triggerType->value,
            'trigger_config' => $triggerConfig,
            'action_type' => $actionType->value,
            'action_config' => $actionConfig,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(string $workspaceUid, string $businessUid, Business $business, ?Automation $automation): array
    {
        $groups = ContactGroups::query()->where('business_id', $business->id)->where('status', true)->orderBy('name')->get();

        $dateFields = ContactGroupFields::query()
            ->whereIn('contact_group_id', $groups->pluck('id'))
            ->whereIn('type', [ContactGroupFields::TYPE_DATE, ContactGroupFields::TYPE_DATETIME])
            ->orderBy('label')
            ->get();

        $customFields = ContactGroupFields::query()
            ->whereIn('contact_group_id', $groups->pluck('id'))
            ->where('is_phone', false)
            ->orderBy('label')
            ->get();

        $senders = collect()
            ->merge(Senderid::query()->where('business_id', $business->id)->where('status', Senderid::STATUS_ACTIVE)->pluck('sender_id'))
            ->merge(PhoneNumbers::query()->where('business_id', $business->id)->where('status', 'assigned')->pluck('number'))
            ->unique()
            ->values();

        $channels = CustomerBasedSendingServer::query()
            ->where('business_id', $business->id)
            ->where('status', 1)
            ->with('sendingServer')
            ->get()
            ->filter(fn (CustomerBasedSendingServer $assignment) => $assignment->sendingServer !== null && $assignment->sendingServer->status);

        return [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'automation' => $automation,
            'groups' => $groups,
            'dateFields' => $dateFields,
            'customFields' => $customFields,
            'senders' => $senders,
            'channels' => $channels,
            'offsets' => AutomationTriggerEvaluator::OFFSET_ALLOWLIST,
            'triggerTypes' => AutomationTriggerType::cases(),
            'actionTypes' => AutomationActionType::cases(),
        ];
    }

    private function demoGuard(string $workspaceUid, string $businessUid): ?RedirectResponse
    {
        if (config('app.stage') !== 'demo') {
            return null;
        }

        return redirect()
            ->route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode']);
    }

    private function showSuccess(string $workspaceUid, string $businessUid, Automation $automation, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.automations.show', [$workspaceUid, $businessUid, $automation->uid])
            ->with(['status' => 'success', 'message' => $message]);
    }

    private function showError(string $workspaceUid, string $businessUid, Automation $automation, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.automations.show', [$workspaceUid, $businessUid, $automation->uid])
            ->with(['status' => 'error', 'message' => $message]);
    }
}
