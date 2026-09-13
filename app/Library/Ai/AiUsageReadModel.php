<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Slice AI-2 — the one read seam over AI-1's `ai_usage_periods` and
 * `ai_usage_ledger` for everything that DISPLAYS usage: the customer's
 * Settings -> Billing -> AI usage state (§11.3) and the admin ledger
 * summary (§10.3 C-9).
 *
 * It writes nothing and owns no budget rule. AiUsageLedgerManager stays the
 * only writer of both tables and AiBudgetPolicyResolver the only source of a
 * policy; this class only reads what they recorded.
 *
 * Every read is bounded. The customer reads are one row per scope plus one
 * existence probe (grouped by Business for the Agency rows); the admin reads
 * are paginated with a hard per-page ceiling, and the ledger read names its
 * columns explicitly so nothing beyond attribution, provenance, usage, cost
 * and state can ever be selected — AI-1 persists no prompt or response, and
 * an explicit list keeps it that way even if a column is added later.
 */
final class AiUsageReadModel
{
    public const ADMIN_MAX_PER_PAGE = 100;

    /** @var array<int, string> */
    private const ADMIN_LEDGER_COLUMNS = [
        'ai_usage_ledger.id',
        'ai_usage_ledger.uid',
        'ai_usage_ledger.workspace_id',
        'ai_usage_ledger.business_id',
        'ai_usage_ledger.category',
        'ai_usage_ledger.lane',
        'ai_usage_ledger.model_route',
        'ai_usage_ledger.provider',
        'ai_usage_ledger.provider_model',
        'ai_usage_ledger.price_version',
        'ai_usage_ledger.status',
        'ai_usage_ledger.refusal_reason',
        'ai_usage_ledger.input_tokens',
        'ai_usage_ledger.cached_input_tokens',
        'ai_usage_ledger.output_tokens',
        'ai_usage_ledger.estimated_cost_microusd',
        'ai_usage_ledger.actual_cost_microusd',
        'ai_usage_ledger.period_key',
        'ai_usage_ledger.idempotency_key',
        'ai_usage_ledger.actor_user_id',
        'ai_usage_ledger.created_at',
        'ai_usage_ledger.settled_at',
    ];

    /**
     * The Workspace's own standing this period. A period that has not been
     * opened yet has spent nothing, and its cap is the policy's — the same
     * rule AiUsageLedgerManager::enforcedWorkspaceCapMicrousd() applies, so
     * the page and the gateway can never disagree about which cap counts.
     *
     * WHICH REFUSALS SPEAK FOR THE WORKSPACE. §11.3 counts a `budget_exhausted`
     * refusal as the allowance being used up, and the ledger records that a
     * call was refused but not which cap refused it.
     *
     *  - With one allowance (no per-Business cap: Core, Growth, trial) every
     *    such refusal is that allowance running out.
     *  - With a per-Business cap (Agency) a refusal of a Business-scoped call
     *    is most often that Business reaching its own allowance, which says
     *    nothing about the other Businesses. It belongs on that Business's row,
     *    not on the account headline — otherwise one client reaching its limit
     *    would tell the agency that AI is paused for everyone. Only a refusal
     *    of a Workspace-level call, which is checked against the Workspace cap
     *    alone, speaks for the Workspace here.
     *
     * The one case this leaves to committed usage is a Business-scoped call
     * refused by the Workspace cap while committed is still under it. That can
     * only happen within one request's estimate of the cap, where committed is
     * already far past the nearing-limit threshold, so the headline already
     * says the allowance is nearly used.
     */
    public function workspaceStanding(int $workspaceId, AiBudgetPolicy $policy): AiUsageStanding
    {
        $period = AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $workspaceId)
            ->where('period_key', $policy->periodKey)
            ->first(['cap_microusd', 'committed_microusd']);

        $refused = AiUsageLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('period_key', $policy->periodKey)
            ->where('status', AiUsageEntryStatus::Refused->value)
            ->where('refusal_reason', AiRefusalReason::BudgetExhausted->value)
            ->when($policy->businessCapMicrousd !== null, fn ($query) => $query->whereNull('business_id'))
            ->exists();

        return new AiUsageStanding(
            committedMicrousd: (int) ($period->committed_microusd ?? 0),
            capMicrousd: $period !== null ? (int) $period->cap_microusd : $policy->workspaceCapMicrousd,
            refusedForBudgetThisPeriod: $refused,
        );
    }

    /**
     * Each named Business's own standing against its per-Business cap, in two
     * statements however many Businesses there are. Only meaningful where the
     * policy has a Business sub-cap (Agency); the caller decides that.
     *
     * @param  array<int, int>  $businessIds
     * @return array<int, AiUsageStanding> keyed by Business id
     */
    public function businessStandings(int $workspaceId, array $businessIds, AiBudgetPolicy $policy): array
    {
        $businessIds = array_values(array_unique(array_map('intval', $businessIds)));

        if ($businessIds === [] || $policy->businessCapMicrousd === null) {
            return [];
        }

        $periods = AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_BUSINESS)
            ->whereIn('scope_id', $businessIds)
            ->where('workspace_id', $workspaceId)
            ->where('period_key', $policy->periodKey)
            ->get(['scope_id', 'cap_microusd', 'committed_microusd'])
            ->keyBy('scope_id');

        $refusedIds = AiUsageLedgerEntry::query()
            ->whereIn('business_id', $businessIds)
            ->where('workspace_id', $workspaceId)
            ->where('period_key', $policy->periodKey)
            ->where('status', AiUsageEntryStatus::Refused->value)
            ->where('refusal_reason', AiRefusalReason::BudgetExhausted->value)
            ->distinct()
            ->pluck('business_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $standings = [];

        foreach ($businessIds as $businessId) {
            $period = $periods->get($businessId);

            $standings[$businessId] = new AiUsageStanding(
                committedMicrousd: (int) ($period->committed_microusd ?? 0),
                capMicrousd: $period !== null ? (int) $period->cap_microusd : $policy->businessCapMicrousd,
                refusedForBudgetThisPeriod: in_array($businessId, $refusedIds, true),
            );
        }

        return $standings;
    }

    /**
     * Admin — the period counter rows for one period key, with the account
     * and Business they belong to.
     *
     * @param  array{period_key: string, workspace_id?: int|null}  $filters
     */
    public function adminPeriods(array $filters, int $perPage): LengthAwarePaginator
    {
        return AiUsagePeriod::query()
            ->leftJoin('workspaces', 'workspaces.id', '=', 'ai_usage_periods.workspace_id')
            ->leftJoin('businesses', function ($join): void {
                $join->on('businesses.id', '=', 'ai_usage_periods.scope_id')
                    ->where('ai_usage_periods.scope_type', '=', AiUsagePeriod::SCOPE_BUSINESS);
            })
            ->where('ai_usage_periods.period_key', $filters['period_key'])
            ->when(($filters['workspace_id'] ?? null) !== null, fn ($query) => $query->where('ai_usage_periods.workspace_id', $filters['workspace_id']))
            ->orderBy('ai_usage_periods.workspace_id')
            ->orderByDesc('ai_usage_periods.scope_type')
            ->orderBy('ai_usage_periods.scope_id')
            ->select([
                'ai_usage_periods.id',
                'ai_usage_periods.scope_type',
                'ai_usage_periods.scope_id',
                'ai_usage_periods.workspace_id',
                'ai_usage_periods.period_key',
                'ai_usage_periods.policy_key',
                'ai_usage_periods.policy_version',
                'ai_usage_periods.cap_microusd',
                'ai_usage_periods.reserved_microusd',
                'ai_usage_periods.committed_microusd',
                'ai_usage_periods.interactive_reserved_microusd',
                'ai_usage_periods.interactive_committed_microusd',
                'ai_usage_periods.updated_at',
                'workspaces.name as workspace_name',
                'businesses.name as business_name',
            ])
            ->paginate($this->clampPerPage($perPage), ['*'], 'periods_page');
    }

    /**
     * Admin — ledger entries, newest first, filtered. Never a dump: always a
     * page, never more than ADMIN_MAX_PER_PAGE rows.
     *
     * @param  array{period_key: string, workspace_id?: int|null, status?: string|null, category?: string|null}  $filters
     */
    public function adminLedger(array $filters, int $perPage): LengthAwarePaginator
    {
        return AiUsageLedgerEntry::query()
            ->leftJoin('workspaces', 'workspaces.id', '=', 'ai_usage_ledger.workspace_id')
            ->leftJoin('businesses', 'businesses.id', '=', 'ai_usage_ledger.business_id')
            ->where('ai_usage_ledger.period_key', $filters['period_key'])
            ->when(($filters['workspace_id'] ?? null) !== null, fn ($query) => $query->where('ai_usage_ledger.workspace_id', $filters['workspace_id']))
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('ai_usage_ledger.status', $filters['status']))
            ->when(($filters['category'] ?? null) !== null, fn ($query) => $query->where('ai_usage_ledger.category', $filters['category']))
            ->orderByDesc('ai_usage_ledger.id')
            ->select(array_merge(self::ADMIN_LEDGER_COLUMNS, [
                'workspaces.name as workspace_name',
                'businesses.name as business_name',
            ]))
            ->paginate($this->clampPerPage($perPage), ['*'], 'ledger_page');
    }

    private function clampPerPage(int $perPage): int
    {
        return max(1, min($perPage, self::ADMIN_MAX_PER_PAGE));
    }
}
