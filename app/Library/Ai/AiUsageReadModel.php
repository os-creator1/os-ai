<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiRefusalScope;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
        'ai_usage_ledger.refusal_scope',
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
     * refusal as the allowance being used up — but only the refusals the
     * Workspace allowance actually caused. AiUsageLedgerManager::reserve()
     * records that on every refusal as `refusal_scope`, from the locked
     * figures it refused on, and this reads it; nothing here guesses.
     *
     * So a Business-scoped call refused because the Workspace cap was the
     * limit makes the headline Limit reached even while committed is still
     * just under 100% (the next request would not fit), and an Agency client
     * reaching its own per-Business cap does not.
     *
     * Rows written before the scope was recorded have none. One of those
     * still speaks for the Workspace only where the Workspace cap is the only
     * cap that call could have met: the policy has no per-Business cap, or the
     * call carried no Business, so there was no Business row to refuse it.
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
            ->where(function (Builder $query) use ($policy): void {
                $query->whereIn('refusal_scope', self::scopesIncludingWorkspace())
                    ->orWhere(function (Builder $legacy) use ($policy): void {
                        $legacy->whereNull('refusal_scope');

                        if ($policy->businessCapMicrousd !== null) {
                            $legacy->whereNull('business_id');
                        }
                    });
            })
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
     * A row describes that Business's OWN allowance, so only refusals its own
     * cap caused count here (`business`, or `workspace_and_business`). A
     * refusal the Workspace cap alone caused belongs to the account headline;
     * counting it on the row would say a client's allowance is spent when it
     * is not. A pre-scope refusal of this Business's call has no recorded
     * scope and is kept on the row, where it was always shown.
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
            ->where(function (Builder $query): void {
                $query->whereIn('refusal_scope', self::scopesIncludingBusiness())
                    ->orWhereNull('refusal_scope');
            })
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

    /** @return array<int, string> */
    private static function scopesIncludingWorkspace(): array
    {
        return array_values(array_map(
            fn (AiRefusalScope $scope): string => $scope->value,
            array_filter(AiRefusalScope::cases(), fn (AiRefusalScope $scope): bool => $scope->includesWorkspace()),
        ));
    }

    /** @return array<int, string> */
    private static function scopesIncludingBusiness(): array
    {
        return array_values(array_map(
            fn (AiRefusalScope $scope): string => $scope->value,
            array_filter(AiRefusalScope::cases(), fn (AiRefusalScope $scope): bool => $scope->includesBusiness()),
        ));
    }

    private function clampPerPage(int $perPage): int
    {
        return max(1, min($perPage, self::ADMIN_MAX_PER_PAGE));
    }
}
