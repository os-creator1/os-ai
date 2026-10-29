<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Enums\Opportunity\OpportunityStatus;
use App\Http\Requests\Opportunity\ConfigureOpportunityActionRequest;
use App\Http\Requests\Opportunity\SnoozeOpportunityRequest;
use App\Library\Catalog\CatalogMoney;
use App\Library\Opportunity\Exceptions\InvalidOpportunityActionParametersException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityExecutionStateException;
use App\Library\Opportunity\Exceptions\InvalidOpportunityStateException;
use App\Library\Opportunity\Exceptions\InvalidSnoozeUntilException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotConfigurableException;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityApprovalNotRequiredException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\Exceptions\OpportunityExecutionRetryNotAvailableException;
use App\Library\Opportunity\ActionCostEstimate;
use App\Library\Opportunity\ActionCostEstimator;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Navigation\MenuEntitlements;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Opportunity\OpportunityPlanEligibility;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Navigation\CustomerContext;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Customer Opportunity queue, detail, and — as of Slice 2A — the
 * configure/request-approval/confirm-approval mutations for the real
 * production add_phone action (RFC-002 Milestone 4 §43). Every mutation
 * only validates input, resolves the tenant-scoped Opportunity, invokes
 * OpportunityManager, and maps the outcome to a redirect — no workflow
 * eligibility logic lives here; OpportunityManager remains authoritative.
 */
class OpportunityController extends Controller
{
    /**
     * Fixed, source-controlled customer-facing labels for currently
     * reachable action_key values — never the raw registry or a per-request
     * value (RFC-002 §46). Extended only as new actions become reachable.
     */
    private const ACTION_LABELS = [
        'add_phone' => 'Add phone number',
        'add_email' => 'Add your public business email',
        'add_website' => 'Add your website URL',
        'add_description' => 'Write a business description of at least 50 characters',
        'add_location' => 'Add your primary business location',
        'complete_location' => 'Complete your primary location details',
        'add_service' => 'Add the services you offer',
        'confirm_primary_service' => 'Choose a primary service',
        'add_gbp_url' => 'Add your Google Business Profile URL',
        'add_facebook_url' => 'Add your Facebook page URL',
        'add_instagram_url' => 'Add your Instagram profile URL',
    ];

    /**
     * Plain-language copy for the optional profile suggestions; every other
     * type keeps its registry title/summary.
     */
    private const CUSTOMER_COPY = [
        'missing_facebook_url' => [
            'title' => 'Add your Facebook page',
            'summary' => 'Make it easier for customers to find and recognize your business by linking your Facebook page.',
        ],
        'missing_instagram_url' => [
            'title' => 'Add your Instagram profile',
            'summary' => 'Make it easier for customers to find and recognize your business by linking your Instagram profile.',
        ],
    ];

    /**
     * Where the customer makes the change themselves, for a recommendation
     * the platform does not execute. Only a route name is stored here: the
     * destination enforces its own authorization, so a link can never grant
     * access, and an unresolvable route yields no link at all.
     */
    private const ACTION_DESTINATIONS = [
        'add_email' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_website' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_description' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_gbp_url' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_facebook_url' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_instagram_url' => ['route' => 'customer.business.edit', 'label' => 'Open business profile'],
        'add_location' => ['route' => 'customer.workspaces.businesses.locations.index', 'label' => 'Open locations'],
        'complete_location' => ['route' => 'customer.workspaces.businesses.locations.index', 'label' => 'Open locations'],
        'add_service' => ['route' => 'customer.workspaces.businesses.services.index', 'label' => 'Open services'],
        'confirm_primary_service' => ['route' => 'customer.workspaces.businesses.services.index', 'label' => 'Open services'],
    ];

    /**
     * Fixed terminology, RFC-002 §32 — never altered per-request.
     */
    private const COMPLETION_POLICY_LABELS = [
        'system_verified' => 'System verified',
        'customer_attested' => 'Marked complete by customer',
        'external_verified' => 'Externally verified',
    ];

    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
        private readonly OpportunityActionExecutionRepository $actionExecutionRepository,
        private readonly OpportunityManager $opportunityManager,
        private readonly ActionCostEstimator $costEstimator,
        private readonly OpportunityPlanEligibility $planEligibility,
        private readonly DashboardLinkGate $links,
        private readonly BusinessRepository $businessRepository,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        $business = $this->selectedBusiness();

        if ($business === null) {
            return redirect()->route('customer.onboarding.show');
        }

        $selectedStatus = $this->normalizeStatus($request->query('status'));
        $selectedFreshness = $this->normalizeFreshness($request->query('freshness'));

        $filters = [];

        if ($selectedStatus !== null) {
            $filters['status'] = $selectedStatus;
        }

        if ($selectedFreshness !== 'all') {
            $filters['freshness'] = $selectedFreshness;
        }

        $opportunities = $this->opportunityRepository->paginateForCustomer($business, $filters + ['suggestions' => 'exclude']);
        $optionalSuggestions = $this->opportunityRepository->paginateForCustomer($business, $filters + ['suggestions' => 'only']);

        return view('customer.opportunities.index', [
            'opportunities' => $opportunities,
            'optionalSuggestions' => $optionalSuggestions->items(),
            'selectedStatus' => $selectedStatus,
            'selectedFreshness' => $selectedFreshness,
        ]);
    }

    public function show(int $opportunity): View|RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        $business = $this->selectedBusiness();

        if ($business === null) {
            return redirect()->route('customer.onboarding.show');
        }

        $ownedOpportunity = $this->findVisible($opportunity, $business);

        $latestExecution = $this->actionExecutionRepository->findLatestForOpportunity($ownedOpportunity->id);

        return view('customer.opportunities.show', [
            'opportunity' => $ownedOpportunity,
            'evidence' => $this->safeEvidence($ownedOpportunity),
            'actionLabel' => $this->actionLabel($ownedOpportunity),
            'isOptional' => OpportunityTypeRegistry::isOptionalProfileSuggestion($ownedOpportunity->type),
            'customerTitle' => self::CUSTOMER_COPY[$ownedOpportunity->type]['title'] ?? $ownedOpportunity->title,
            'customerSummary' => self::CUSTOMER_COPY[$ownedOpportunity->type]['summary'] ?? $ownedOpportunity->summary,
            'actionDestination' => $this->actionDestination($ownedOpportunity, $business),
            'configuredValue' => $this->configuredValue($ownedOpportunity),
            'completionPolicyLabel' => $this->completionPolicyLabel($ownedOpportunity),
            'execution' => $this->safeExecution($latestExecution),
            'canRetry' => $this->canRetry($ownedOpportunity),
            'shouldPollExecution' => $this->shouldPollExecution($ownedOpportunity, $latestExecution),
            'canConfigureAction' => $this->canConfigureAction($ownedOpportunity),
            'costEstimate' => $this->safeCostEstimate($ownedOpportunity, $business),
        ]);
    }

    /**
     * Customer-safe, read-only execution-status poll (RFC-002 §43) — no
     * write of any kind occurs here. Every field is allowlisted; see
     * executionStatusMessage()/shouldPollExecution() for the exact source
     * of truth.
     */
    public function executionStatus(int $opportunity): JsonResponse
    {
        $this->ensureOpportunityEngineEnabled();

        $business = $this->selectedBusiness();

        if ($business === null) {
            abort(404);
        }

        $ownedOpportunity = $this->findVisible($opportunity, $business);

        $latestExecution = $this->actionExecutionRepository->findLatestForOpportunity($ownedOpportunity->id);
        $isTerminal = ! $this->shouldPollExecution($ownedOpportunity, $latestExecution);

        return response()->json([
            'opportunity_status' => $ownedOpportunity->status->value,
            'execution_status' => $latestExecution?->status->value,
            'message' => $this->executionStatusMessage($ownedOpportunity, $latestExecution),
            'is_terminal' => $isTerminal,
        ])->header('Cache-Control', 'no-store');
    }

    public function configureAction(ConfigureOpportunityActionRequest $request, int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->configureAction($ownedOpportunity, $customer, [
                'value' => $request->validated('value'),
            ]);
        } catch (InvalidOpportunityActionParametersException) {
            return redirect()->back()->withInput()->withErrors([
                'value' => 'Enter a valid value.',
            ]);
        } catch (OpportunityActionNotConfigurableException) {
            return $this->safeErrorRedirect("This opportunity's action can't be configured right now.");
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Phone number saved.',
        ]);
    }

    public function requestApproval(int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->requestApproval($ownedOpportunity, $customer);
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        } catch (OpportunityActionNotExecutableException) {
            return $this->safeErrorRedirect("This opportunity's action isn't ready for that step yet.");
        } catch (OpportunityApprovalNotRequiredException) {
            return $this->safeErrorRedirect('This action doesn\'t require approval.');
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Approval requested.',
        ]);
    }

    public function confirmApproval(int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->confirmApproval($ownedOpportunity, $customer);
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        } catch (OpportunityActionNotExecutableException) {
            return $this->safeErrorRedirect("This opportunity's action isn't ready for that step yet.");
        } catch (InvalidOpportunityExecutionStateException) {
            return $this->safeErrorRedirect('This opportunity\'s execution state has changed. Please refresh and try again.');
        } catch (OpportunityEngineDisabledException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Approval confirmed. The action has started.',
        ]);
    }

    public function snooze(SnoozeOpportunityRequest $request, int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        // A fixed, server-computed instant — never a client-supplied
        // timestamp — mapped from the validated duration key only.
        $until = match ($request->validated('duration')) {
            '1_day' => now()->addDay(),
            '3_days' => now()->addDays(3),
            '1_week' => now()->addWeek(),
        };

        try {
            $this->opportunityManager->snooze($ownedOpportunity, $customer, $until);
        } catch (InvalidOpportunityStateException|InvalidSnoozeUntilException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity snoozed.',
        ]);
    }

    public function dismiss(int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->dismiss($ownedOpportunity, $customer);
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity dismissed.',
        ]);
    }

    public function reopen(int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->reopen($ownedOpportunity, $customer, 'customer_reopened');
        } catch (InvalidOpportunityStateException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Opportunity reopened.',
        ]);
    }

    public function retry(int $opportunity): RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        [$customer, $ownedOpportunity, $redirect] = $this->resolveMutationTarget($opportunity);

        if ($redirect !== null) {
            return $redirect;
        }

        try {
            $this->opportunityManager->retryFailedExecution($ownedOpportunity, $customer);
        } catch (OpportunityExecutionRetryNotAvailableException|OpportunityActionNotExecutableException|OpportunityEngineDisabledException) {
            return $this->safeErrorRedirect("This action isn't available for this opportunity right now.");
        }

        return redirect()->route('customer.opportunities.show', $ownedOpportunity->id)->with([
            'status' => 'success',
            'message' => 'Retry started.',
        ]);
    }

    /**
     * Shared tenant-safe lookup for every mutation: feature-flag guard has
     * already run by the time this is called. Resolves Customer → primary
     * Business → the ownership-scoped Opportunity, exactly mirroring
     * index()/show()'s own resolution — never trusts a request-supplied
     * business_id, never fetches the Opportunity globally first.
     *
     * @return array{0: Customer, 1: Opportunity, 2: null}|array{0: null, 1: null, 2: RedirectResponse}
     */
    private function resolveMutationTarget(int $opportunityId): array
    {
        $customer = $this->customer();
        $business = $this->selectedBusiness();

        if ($business === null) {
            return [null, null, redirect()->route('customer.onboarding.show')];
        }

        $ownedOpportunity = $this->findVisible($opportunityId, $business);

        return [$customer, $ownedOpportunity, null];
    }

    /**
     * The fixed-message redirect every non-validation domain exception maps
     * to (RFC-002 §47) — never the raw exception message, which always
     * interpolates an internal Opportunity ID.
     */
    private function safeErrorRedirect(string $message): RedirectResponse
    {
        return redirect()->back()->withErrors(['opportunity' => $message]);
    }

    /**
     * First executable line of every action above — no query runs before it.
     */
    private function ensureOpportunityEngineEnabled(): void
    {
        abort_unless(config('opportunity.enabled', false), 404);
    }

    /**
     * Owned by this Business AND still entitled by its plan; anything else is
     * a 404, so a recommendation hidden by a downgrade is unreachable by id
     * (detail, status poll and every mutation) without being deleted.
     */
    private function findVisible(int $opportunityId, Business $business): Opportunity
    {
        $owned = $this->opportunityRepository->findOwned($opportunityId, $business->id);

        if ($owned === null || ! $this->planEligibility->isEligible($business, $owned->type)) {
            abort(404);
        }

        return $owned;
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
    }

    private function selectedBusiness(): ?Business
    {
        $context = request()->attributes->get('customerContext');

        if (! $context instanceof CustomerContext || ! $context->isBusinessFrame()) {
            return null;
        }

        return Business::query()
            ->whereKey($context->selectedBusiness->id)
            ->where('workspace_id', $context->frameWorkspace()?->id)
            ->first();
    }

    private function normalizeStatus(?string $status): ?string
    {
        static $allowed = null;
        $allowed ??= array_map(static fn (OpportunityStatus $case) => $case->value, OpportunityStatus::cases());

        return in_array($status, $allowed, true) ? $status : null;
    }

    private function normalizeFreshness(?string $freshness): string
    {
        return in_array($freshness, ['current', 'stale', 'all'], true) ? $freshness : 'current';
    }

    /**
     * Only the registry-rendered summary and retrieval timestamp of each
     * evidence item ever reach the view — never fact_key, observed_value,
     * source_identifier, content_hash, or the raw payload. Malformed or
     * incomplete items are skipped silently rather than fabricated or
     * dumped.
     *
     * @return array<int, array{summary: string, retrieved_at: ?string}>
     */
    private function safeEvidence(Opportunity $opportunity): array
    {
        $evidence = $opportunity->evidence;

        if (! is_array($evidence)) {
            return [];
        }

        $safe = [];

        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }

            $summary = $item['summary'] ?? null;

            if (! is_string($summary) || trim($summary) === '') {
                continue;
            }

            $retrievedAt = $item['retrieved_at'] ?? null;

            $safe[] = [
                'summary' => $summary,
                'retrieved_at' => is_string($retrievedAt) ? $retrievedAt : null,
            ];
        }

        return $safe;
    }

    /**
     * Implementation Contract 19 §5.3, §12 19.E — "before a human approves a
     * paid action, show the actual payer, estimated amount or units,
     * pricing basis, and whether that payer's wallet has enough funds."
     *
     * While the Opportunity is still `open`, this is a fresh, read-only,
     * server-computed preview (ActionCostEstimator never reserves or writes
     * anything) — never a stored value, since nothing is snapshotted until
     * requestApproval() runs. From `awaiting_approval` onward, the human has
     * already been shown and approved a ceiling, so this instead renders
     * exactly what was snapshotted then — the actual approved figure, never
     * a re-estimate that could silently disagree with it. Null whenever the
     * action is not `paid_effect` (every shipped action today, add_phone
     * included) — never a fabricated zero-cost estimate.
     *
     * @return array{payerType: string, amountMinorUpperBound: ?int, formattedAmount: ?string, unitCount: ?int, unitKind: ?string, currencyCode: ?string, basis: string, walletSufficient: bool}|null
     */
    private function safeCostEstimate(Opportunity $opportunity, Business $business): ?array
    {
        $actionKey = is_array($opportunity->recommended_action) ? ($opportunity->recommended_action['action_key'] ?? null) : null;

        if (! is_string($actionKey) || ! OpportunityActionRegistry::hasPaidEffect($actionKey)) {
            return null;
        }

        if ($opportunity->status !== OpportunityStatus::Open) {
            $stored = ActionCostEstimate::fromSnapshot([
                'action_cost_payer_type' => $opportunity->action_cost_payer_type,
                'action_cost_payer_workspace_id' => $opportunity->action_cost_payer_workspace_id,
                'action_cost_currency_code' => $opportunity->action_cost_currency_code,
                'action_cost_amount_minor_upper_bound' => $opportunity->action_cost_amount_minor_upper_bound,
                'action_cost_unit_count' => $opportunity->action_cost_unit_count,
                'action_cost_unit_kind' => $opportunity->action_cost_unit_kind,
                'action_cost_basis' => $opportunity->action_cost_basis,
                'action_cost_price_version' => $opportunity->action_cost_price_version,
                'action_cost_estimated_at' => $opportunity->action_cost_estimated_at,
                'action_cost_expires_at' => $opportunity->action_cost_expires_at,
                'action_cost_wallet_sufficient' => $opportunity->action_cost_wallet_sufficient,
            ]);

            return $stored === null ? null : $this->safeEstimateFields($stored);
        }

        $estimate = $this->costEstimator->estimateForAction($business, $actionKey);

        return $estimate === null ? null : $this->safeEstimateFields($estimate);
    }

    /**
     * `formattedAmount` is the actual major-currency figure a human reads
     * ("USD 1.24", never "124 USD") — computed once here, from the exact
     * minor-unit integer plus the currency's own exponent
     * ({@see CatalogMoney::format()}, reused rather than duplicated), so
     * the view never does its own currency arithmetic.
     *
     * @return array{payerType: string, amountMinorUpperBound: ?int, formattedAmount: ?string, unitCount: ?int, unitKind: ?string, currencyCode: ?string, basis: string, walletSufficient: bool}
     */
    private function safeEstimateFields(ActionCostEstimate $estimate): array
    {
        return [
            'payerType' => $estimate->payerType->value,
            'amountMinorUpperBound' => $estimate->amountMinorUpperBound,
            'formattedAmount' => $estimate->amountMinorUpperBound === null
                ? null
                : CatalogMoney::format($estimate->amountMinorUpperBound, $estimate->currencyCode),
            'unitCount' => $estimate->unitCount,
            'unitKind' => $estimate->unitKind,
            'currencyCode' => $estimate->currencyCode,
            'basis' => $estimate->basis,
            'walletSufficient' => $estimate->walletSufficient,
        ];
    }

    /**
     * Never the raw action_key — a fixed mapping for currently reachable
     * actions, or a neutral fallback for anything else.
     */
    private function actionLabel(Opportunity $opportunity): ?string
    {
        $actionKey = $this->actionKey($opportunity);

        return $actionKey === null ? null : (self::ACTION_LABELS[$actionKey] ?? null);
    }

    private function actionKey(Opportunity $opportunity): ?string
    {
        $recommendedAction = $opportunity->recommended_action;
        $actionKey = is_array($recommendedAction) ? ($recommendedAction['action_key'] ?? null) : null;

        return is_string($actionKey) && $actionKey !== '' ? $actionKey : null;
    }

    /**
     * @return array{label: string, url: string}|null the customer's own place to make the change, for a
     *                                                recommendation the platform does not execute
     *
     * Always for the EXACT Business being viewed, or no link at all. The
     * profile edit route resolves the signed-in customer's own primary
     * Business rather than the viewed one, so it is offered only when those
     * are the same Business and the actor is not viewing as a client. The
     * locations/services routes carry the viewed Business's own UIDs and go
     * through DashboardLinkGate (route exists, permission, View As allowlist).
     */
    private function actionDestination(Opportunity $opportunity, Business $business): ?array
    {
        $actionKey = $this->actionKey($opportunity);
        $destination = $actionKey === null ? null : (self::ACTION_DESTINATIONS[$actionKey] ?? null);
        $context = request()->attributes->get('customerContext');

        if ($destination === null || ! $context instanceof CustomerContext || ! $context->isBusinessFrame()) {
            return null;
        }

        $user = Auth::user();

        if ($destination['route'] === 'customer.business.edit') {
            $primary = $this->businessRepository->findPrimaryByCustomer($this->customer()->user_id);

            if ($context->isViewingAsClient() || $primary === null || (int) $primary->id !== (int) $business->id || ! Route::has($destination['route'])) {
                return null;
            }

            return ['label' => $destination['label'], 'url' => route($destination['route'])];
        }

        $url = $this->links->url(
            $context,
            $user,
            MenuEntitlements::none(),
            $destination['route'],
            ['workspaceUid' => (string) $context->selectedBusiness->workspaceUid, 'businessUid' => (string) $context->selectedBusiness->uid],
            ['access_backend'],
        );

        return $url === null ? null : ['label' => $destination['label'], 'url' => $url];
    }

    private function configuredValue(Opportunity $opportunity): string|int|float|null
    {
        $recommendedAction = $opportunity->recommended_action;
        $parameters = is_array($recommendedAction) ? ($recommendedAction['parameters'] ?? null) : null;
        $value = is_array($parameters) ? ($parameters['value'] ?? null) : null;

        return (is_string($value) || is_int($value) || is_float($value)) ? $value : null;
    }

    private function completionPolicyLabel(Opportunity $opportunity): string
    {
        $recommendedAction = $opportunity->recommended_action;
        $policy = is_array($recommendedAction) ? ($recommendedAction['completion_policy'] ?? null) : null;

        if (! is_string($policy)) {
            return 'Completion status unavailable';
        }

        return self::COMPLETION_POLICY_LABELS[$policy] ?? 'Completion status unavailable';
    }

    /**
     * Never the execution id, idempotency_key, recommended_action_hash,
     * action_schema_version, or handler/verifier identifiers — only the
     * customer-safe fields needed to describe current state.
     *
     * @return array{status: string, safe_result_summary: ?string, safe_error_summary: ?string, started_at: mixed, completed_at: mixed, attempt_number: int}|null
     */
    private function safeExecution(?OpportunityActionExecution $execution): ?array
    {
        if ($execution === null) {
            return null;
        }

        return [
            'status' => $execution->status->value,
            'safe_result_summary' => $execution->safe_result_summary,
            'safe_error_summary' => $execution->safe_error_summary,
            'started_at' => $execution->started_at,
            'completed_at' => $execution->completed_at,
            'attempt_number' => $execution->attempt_number,
        ];
    }

    /**
     * Display-only retry eligibility (RFC-002 §31) — deliberately does not
     * use findLatestForOpportunity()/the already-computed $execution array:
     * OpportunityManager::retryFailedExecution() looks for the latest
     * *failed* execution matching the Opportunity's *current* action
     * snapshot exactly, which is not necessarily the latest execution
     * overall (a newer, non-matching failed attempt — e.g. after the action
     * was reconfigured and reconfigured back — would otherwise cause a
     * false negative here). The manager remains fully authoritative
     * regardless of what this returns; a wrong guess here only affects
     * whether the button renders, never whether a retry can actually
     * succeed.
     */
    private function canRetry(Opportunity $opportunity): bool
    {
        if ($opportunity->status->value !== 'open') {
            return false;
        }

        $recommendedAction = $opportunity->recommended_action;
        $actionKey = is_array($recommendedAction) ? ($recommendedAction['action_key'] ?? null) : null;

        if (! is_string($actionKey) || $actionKey === '' || ! OpportunityActionRegistry::mayRetryUnderOriginalApproval($actionKey)) {
            return false;
        }

        $hash = $opportunity->recommended_action_hash;

        if (! is_string($hash) || $hash === '') {
            return false;
        }

        if ($opportunity->action_schema_version === null) {
            return false;
        }

        return $this->actionExecutionRepository->findLatestFailedMatching(
            $opportunity->id,
            $opportunity->occurrence_number,
            $hash,
            $opportunity->action_schema_version,
            $actionKey,
        ) !== null;
    }

    /**
     * Display-only capability hint (RFC-002 §43 hardening) — answers only
     * "does the persisted recommended_action's action_key match the one
     * action this UI currently knows how to configure," purely from data
     * already loaded onto $opportunity. Never queries another repository,
     * never calls OpportunityManager, never reads request input, and never
     * exposes the action_key itself to the view. configureAction() remains
     * the sole authority on whether a configuration attempt actually
     * succeeds — a false positive here only means a form renders that the
     * manager would still safely reject; a false negative only hides a form
     * that would have worked. Extended only as new actions become
     * configurable.
     */
    private function canConfigureAction(Opportunity $opportunity): bool
    {
        $recommendedAction = $opportunity->recommended_action;

        if (! is_array($recommendedAction)) {
            return false;
        }

        $actionKey = $recommendedAction['action_key'] ?? null;

        return is_string($actionKey) && $actionKey !== '' && $actionKey === 'add_phone';
    }

    /**
     * The single source of truth for both the detail page's polling-script
     * activation gate and the JSON endpoint's is_terminal flag (RFC-002
     * §43) — deliberately requires both the Opportunity's own status AND
     * the latest execution's status, not execution status alone. Since a
     * pending/running execution can only legitimately coexist with
     * status=in_progress (every execution-creating manager method sets
     * both in the same transaction), a Opportunity that is not in_progress
     * paired with a pending/running execution is inconsistent — this
     * returns false for it, which both stops polling and prevents the
     * reloaded page from ever restarting it.
     */
    private function shouldPollExecution(Opportunity $opportunity, ?OpportunityActionExecution $execution): bool
    {
        return $opportunity->status->value === 'in_progress'
            && $execution !== null
            && in_array($execution->status->value, ['pending', 'running'], true);
    }

    /**
     * Never the raw exception/internal state — succeeded/failed always
     * prefer the execution's own safe_result_summary/safe_error_summary
     * (RFC-002 §47, already guaranteed customer-safe), falling back to a
     * fixed generic sentence only when absent. The "status changed"
     * message is reserved for the one genuinely inconsistent case: a
     * pending/running execution whose Opportunity is no longer in_progress
     * (e.g. a stale tab polling after another tab reopened/dismissed it).
     */
    private function executionStatusMessage(Opportunity $opportunity, ?OpportunityActionExecution $execution): string
    {
        if ($execution === null) {
            return 'No execution is currently in progress.';
        }

        if ($execution->status->value === 'succeeded') {
            return filled($execution->safe_result_summary)
                ? $execution->safe_result_summary
                : 'This action completed successfully.';
        }

        if ($execution->status->value === 'failed') {
            return filled($execution->safe_error_summary)
                ? $execution->safe_error_summary
                : 'This action could not be completed.';
        }

        // $execution->status is pending or running here.
        if ($opportunity->status->value !== 'in_progress') {
            return 'The opportunity status changed. Refreshing this page.';
        }

        return $execution->status->value === 'pending'
            ? 'This action is currently queued.'
            : 'This action is currently in progress.';
    }
}
