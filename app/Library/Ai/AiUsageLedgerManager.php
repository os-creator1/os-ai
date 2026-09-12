<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Contract §10.1 steps 4, 6 and 7; §10.2 schema. The only writer of
 * `ai_usage_periods` and `ai_usage_ledger`. Concurrency safety comes from
 * `lockForUpdate()` on the period row(s) inside one short transaction —
 * never from the ledger table, which is append-only and only ever
 * updated by its own primary key.
 *
 * Lock order is always Workspace row before Business row, so two
 * concurrent calls against different Businesses in the same Workspace
 * never deadlock against each other.
 */
final class AiUsageLedgerManager
{
    /**
     * An unlocked read of the Workspace's current reserved+committed
     * total for one period, used only by AiModelRouter's headroom
     * heuristic to decide whether `reasoning` is worth attempting before
     * the authoritative, locked check inside reserve() below.
     */
    public function peekWorkspaceCommittedAndReserved(int $workspaceId, string $periodKey): int
    {
        $period = AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $workspaceId)
            ->where('period_key', $periodKey)
            ->first();

        if ($period === null) {
            return 0;
        }

        return $period->reserved_microusd + $period->committed_microusd;
    }

    /**
     * @return array{entry: AiUsageLedgerEntry, refused: bool}
     */
    public function reserve(AiRequest $request, AiBudgetPolicy $policy, AiUsageCostEstimate $estimate, bool $bypassCapEnforcement): array
    {
        return DB::transaction(function () use ($request, $policy, $estimate, $bypassCapEnforcement): array {
            $workspacePeriod = $this->lockOrCreatePeriod(
                AiUsagePeriod::SCOPE_WORKSPACE,
                $request->workspace->id,
                $request->workspace->id,
                $policy,
                $policy->workspaceCapMicrousd,
            );

            $businessPeriod = null;

            if ($request->business !== null && $policy->businessCapMicrousd !== null) {
                $businessPeriod = $this->lockOrCreatePeriod(
                    AiUsagePeriod::SCOPE_BUSINESS,
                    $request->business->id,
                    $request->workspace->id,
                    $policy,
                    $policy->businessCapMicrousd,
                );
            }

            $isInteractive = $request->lane === AiLane::Interactive;

            $projectedWorkspace = $workspacePeriod->reserved_microusd + $workspacePeriod->committed_microusd + $estimate->costMicrousd;
            $exceedsWorkspace = $projectedWorkspace > $workspacePeriod->cap_microusd;

            $exceedsBusiness = false;
            if ($businessPeriod !== null) {
                $projectedBusiness = $businessPeriod->reserved_microusd + $businessPeriod->committed_microusd + $estimate->costMicrousd;
                $exceedsBusiness = $projectedBusiness > $businessPeriod->cap_microusd;
            }

            $exceedsInteractive = false;
            if ($isInteractive) {
                $interactiveCap = intdiv($workspacePeriod->cap_microusd * $policy->interactiveShareBps, 10_000);
                $projectedInteractive = $workspacePeriod->interactive_reserved_microusd + $workspacePeriod->interactive_committed_microusd + $estimate->costMicrousd;
                $exceedsInteractive = $projectedInteractive > $interactiveCap;
            }

            $mustRefuse = ($exceedsWorkspace || $exceedsBusiness || $exceedsInteractive) && ! $bypassCapEnforcement;

            if ($mustRefuse) {
                $reason = $exceedsInteractive && ! $exceedsWorkspace && ! $exceedsBusiness
                    ? AiRefusalReason::InteractiveShareExhausted
                    : AiRefusalReason::BudgetExhausted;

                $entry = AiUsageLedgerEntry::create([
                    'workspace_id' => $request->workspace->id,
                    'business_id' => $request->business?->id,
                    'category' => $request->category,
                    'lane' => $request->lane,
                    'model_route' => $estimate->route,
                    'provider' => $estimate->provider,
                    'provider_model' => null,
                    'price_version' => $estimate->priceVersion,
                    'status' => AiUsageEntryStatus::Refused,
                    'refusal_reason' => $reason,
                    'estimated_cost_microusd' => $estimate->costMicrousd,
                    'actual_cost_microusd' => null,
                    'period_key' => $policy->periodKey,
                    'idempotency_key' => $request->idempotencyKey,
                    'actor_user_id' => $request->actorUserId,
                    'created_at' => Carbon::now(),
                    'settled_at' => Carbon::now(),
                ]);

                return ['entry' => $entry, 'refused' => true];
            }

            $workspacePeriod->increment('reserved_microusd', $estimate->costMicrousd);
            if ($isInteractive) {
                $workspacePeriod->increment('interactive_reserved_microusd', $estimate->costMicrousd);
            }

            if ($businessPeriod !== null) {
                $businessPeriod->increment('reserved_microusd', $estimate->costMicrousd);
                if ($isInteractive) {
                    $businessPeriod->increment('interactive_reserved_microusd', $estimate->costMicrousd);
                }
            }

            $entry = AiUsageLedgerEntry::create([
                'workspace_id' => $request->workspace->id,
                'business_id' => $request->business?->id,
                'category' => $request->category,
                'lane' => $request->lane,
                'model_route' => $estimate->route,
                'provider' => $estimate->provider,
                'provider_model' => null,
                'price_version' => $estimate->priceVersion,
                'status' => AiUsageEntryStatus::Reserved,
                'refusal_reason' => null,
                'estimated_cost_microusd' => $estimate->costMicrousd,
                'actual_cost_microusd' => null,
                'period_key' => $policy->periodKey,
                'idempotency_key' => $request->idempotencyKey,
                'actor_user_id' => $request->actorUserId,
                'created_at' => Carbon::now(),
            ]);

            return ['entry' => $entry, 'refused' => false];
        });
    }

    public function commitActual(AiUsageLedgerEntry $entry, string $providerModel, int $inputTokens, int $cachedInputTokens, int $outputTokens, int $actualCostMicrousd): AiUsageLedgerEntry
    {
        return DB::transaction(function () use ($entry, $providerModel, $inputTokens, $cachedInputTokens, $outputTokens, $actualCostMicrousd): AiUsageLedgerEntry {
            $this->adjustPeriods($entry, releaseReserved: $entry->estimated_cost_microusd, addCommitted: $actualCostMicrousd);

            $entry->forceFill([
                'status' => AiUsageEntryStatus::Committed,
                'provider_model' => $providerModel,
                'input_tokens' => $inputTokens,
                'cached_input_tokens' => $cachedInputTokens,
                'output_tokens' => $outputTokens,
                'actual_cost_microusd' => $actualCostMicrousd,
                'settled_at' => Carbon::now(),
            ])->save();

            return $entry;
        });
    }

    /**
     * Provider failure: release the entire reservation, nothing is
     * committed (§10.1 step 6 — "On provider failure, release everything
     * unless the provider reported billable usage").
     */
    public function releaseAsFailed(AiUsageLedgerEntry $entry): AiUsageLedgerEntry
    {
        return DB::transaction(function () use ($entry): AiUsageLedgerEntry {
            $this->adjustPeriods($entry, releaseReserved: $entry->estimated_cost_microusd, addCommitted: 0);

            $entry->forceFill([
                'status' => AiUsageEntryStatus::Failed,
                'actual_cost_microusd' => 0,
                'settled_at' => Carbon::now(),
            ])->save();

            return $entry;
        });
    }

    /**
     * §10.1 step 7 — reservations older than
     * `config('ai.reservation_expiry_minutes')` are released, never
     * auto-committed. Mirrors ExpireStaleUsageReservations's shape:
     * find-then-release, bounded by $limit.
     */
    public function expireStaleReservations(int $limit = 500): int
    {
        $cutoff = Carbon::now()->subMinutes((int) config('ai.reservation_expiry_minutes'));

        $stale = AiUsageLedgerEntry::query()
            ->where('status', AiUsageEntryStatus::Reserved)
            ->where('created_at', '<', $cutoff)
            ->limit($limit)
            ->get();

        foreach ($stale as $entry) {
            DB::transaction(function () use ($entry): void {
                // Re-check under lock: another process may have already
                // settled this exact entry between the query above and
                // this transaction starting.
                $fresh = AiUsageLedgerEntry::query()->lockForUpdate()->find($entry->id);

                if ($fresh === null || $fresh->status !== AiUsageEntryStatus::Reserved) {
                    return;
                }

                $this->adjustPeriods($fresh, releaseReserved: $fresh->estimated_cost_microusd, addCommitted: 0);

                $fresh->forceFill([
                    'status' => AiUsageEntryStatus::Released,
                    'actual_cost_microusd' => 0,
                    'settled_at' => Carbon::now(),
                ])->save();
            });
        }

        return $stale->count();
    }

    private function adjustPeriods(AiUsageLedgerEntry $entry, int $releaseReserved, int $addCommitted): void
    {
        $isInteractive = $entry->lane === AiLane::Interactive;

        $workspacePeriod = AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $entry->workspace_id)
            ->where('period_key', $entry->period_key)
            ->lockForUpdate()
            ->first();

        if ($workspacePeriod !== null) {
            $workspacePeriod->decrement('reserved_microusd', min($releaseReserved, $workspacePeriod->reserved_microusd));
            if ($addCommitted > 0) {
                $workspacePeriod->increment('committed_microusd', $addCommitted);
            }
            if ($isInteractive) {
                $workspacePeriod->decrement('interactive_reserved_microusd', min($releaseReserved, $workspacePeriod->interactive_reserved_microusd));
                if ($addCommitted > 0) {
                    $workspacePeriod->increment('interactive_committed_microusd', $addCommitted);
                }
            }
        }

        if ($entry->business_id !== null) {
            $businessPeriod = AiUsagePeriod::query()
                ->where('scope_type', AiUsagePeriod::SCOPE_BUSINESS)
                ->where('scope_id', $entry->business_id)
                ->where('period_key', $entry->period_key)
                ->lockForUpdate()
                ->first();

            if ($businessPeriod !== null) {
                $businessPeriod->decrement('reserved_microusd', min($releaseReserved, $businessPeriod->reserved_microusd));
                if ($addCommitted > 0) {
                    $businessPeriod->increment('committed_microusd', $addCommitted);
                }
                if ($isInteractive) {
                    $businessPeriod->decrement('interactive_reserved_microusd', min($releaseReserved, $businessPeriod->interactive_reserved_microusd));
                    if ($addCommitted > 0) {
                        $businessPeriod->increment('interactive_committed_microusd', $addCommitted);
                    }
                }
            }
        }
    }

    private function lockOrCreatePeriod(string $scopeType, int $scopeId, int $workspaceId, AiBudgetPolicy $policy, int $capMicrousd): AiUsagePeriod
    {
        $existing = AiUsagePeriod::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('period_key', $policy->periodKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return AiUsagePeriod::create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'workspace_id' => $workspaceId,
                'period_key' => $policy->periodKey,
                'policy_key' => $policy->policyKey,
                'policy_version' => $policy->policyVersion,
                'cap_microusd' => $capMicrousd,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the create race to a concurrent request. The row now
            // exists; lock and return it (never retry the insert).
            return AiUsagePeriod::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('period_key', $policy->periodKey)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
