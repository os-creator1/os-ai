<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Enums\Opportunity\OpportunityStatus;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only customer Opportunity queue and detail pages (RFC-002 Milestone 4
 * §43, HTTP Slice 1). No mutations exist yet — OpportunityManager is
 * deliberately not a dependency here.
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
        private readonly BusinessRepository $businessRepository,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        $business = $this->businessRepository->findPrimaryByCustomer($this->customer()->user_id);

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

        $opportunities = $this->opportunityRepository->paginateForCustomer($business, $filters);

        return view('customer.opportunities.index', [
            'opportunities' => $opportunities,
            'selectedStatus' => $selectedStatus,
            'selectedFreshness' => $selectedFreshness,
        ]);
    }

    public function show(int $opportunity): View|RedirectResponse
    {
        $this->ensureOpportunityEngineEnabled();

        $business = $this->businessRepository->findPrimaryByCustomer($this->customer()->user_id);

        if ($business === null) {
            return redirect()->route('customer.onboarding.show');
        }

        $ownedOpportunity = $this->opportunityRepository->findOwned($opportunity, $business->id);

        if ($ownedOpportunity === null) {
            abort(404);
        }

        $latestExecution = $this->actionExecutionRepository->findLatestForOpportunity($ownedOpportunity->id);

        return view('customer.opportunities.show', [
            'opportunity' => $ownedOpportunity,
            'evidence' => $this->safeEvidence($ownedOpportunity),
            'actionLabel' => $this->actionLabel($ownedOpportunity),
            'configuredValue' => $this->configuredValue($ownedOpportunity),
            'completionPolicyLabel' => $this->completionPolicyLabel($ownedOpportunity),
            'execution' => $this->safeExecution($latestExecution),
        ]);
    }

    /**
     * First executable line of both actions above — no query runs before it.
     */
    private function ensureOpportunityEngineEnabled(): void
    {
        abort_unless(config('opportunity.enabled', false), 404);
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
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
     * Never the raw action_key — a fixed mapping for currently reachable
     * actions, or a neutral fallback for anything else.
     */
    private function actionLabel(Opportunity $opportunity): string
    {
        $recommendedAction = $opportunity->recommended_action;
        $actionKey = is_array($recommendedAction) ? ($recommendedAction['action_key'] ?? null) : null;

        if (! is_string($actionKey)) {
            return 'Recommended action';
        }

        return self::ACTION_LABELS[$actionKey] ?? 'Recommended action';
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
}
