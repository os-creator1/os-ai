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
 *
 * Correction 7 — every settlement path takes the SAME locks in the SAME
 * order: the ledger entry first, then the Workspace period, then the
 * Business period. Settlement used to adjust the period rows first while
 * the expiry sweep locked the entry first, which is a textbook lock-order
 * inversion; and settlement never re-checked the entry's status, so a
 * provider call completing while the sweep released its reservation could
 * adjust the same counters twice. Both paths now go through
 * settle(), which claims the entry under lock, refuses to act on an entry
 * that is no longer `reserved`, and only then touches the periods. That
 * makes "commit once, release once" true no matter which side wins the
 * race.
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
     * Correction 10 — the cap this Workspace period is actually enforced
     * against.
     *
     * A period snapshots its cap when it opens, and reserve() checks
     * against that snapshot for the rest of the period. Route headroom must
     * therefore ask the same question: reading the freshly configured cap
     * instead would let routing believe there is room reserve() will refuse
     * when a cap was lowered mid-period, and withhold reasoning the period
     * could still afford when one was raised. A period that has not opened
     * yet has no snapshot, so the configured cap is the honest answer for
     * it — and is what that period will snapshot when it opens.
     */
    public function enforcedWorkspaceCapMicrousd(int $workspaceId, AiBudgetPolicy $policy): int
    {
        $period = AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $workspaceId)
            ->where('period_key', $policy->periodKey)
            ->first();

        return $period === null ? $policy->workspaceCapMicrousd : (int) $period->cap_microusd;
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
        return $this->settle($entry, AiUsageEntryStatus::Committed, $actualCostMicrousd, [
            'provider_model' => $providerModel,
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
        ]);
    }

    /**
     * Provider failure (§10.1 step 6 — "release everything unless the
     * provider reported billable usage").
     *
     * Correction 3: usage the provider reported before failing is real
     * money already spent, so it is committed exactly once and only the
     * remainder of the reservation goes back. A failure that reported
     * nothing releases the whole hold, as before.
     */
    public function releaseAsFailed(
        AiUsageLedgerEntry $entry,
        int $actualCostMicrousd = 0,
        ?string $providerModel = null,
        int $inputTokens = 0,
        int $cachedInputTokens = 0,
        int $outputTokens = 0,
    ): AiUsageLedgerEntry {
        return $this->settle($entry, AiUsageEntryStatus::Failed, $actualCostMicrousd, [
            'provider_model' => $providerModel ?? $entry->provider_model,
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
        ]);
    }

    /**
     * Correction 7 — the one path that ever settles an entry, and the one
     * lock order every caller uses.
     *
     * The entry is claimed first, under `lockForUpdate`. An entry that is
     * no longer `reserved` has already been settled or expired by somebody
     * else, and is returned untouched: the counters must move exactly once
     * whichever side wins. Only after that claim are the period rows
     * locked, always Workspace before Business.
     *
     * The committed amount is bounded by what was reserved, so a provider
     * that reported more than its own ceiling implied can never push a
     * period past the cap the customer was promised.
     *
     * @param  array<string, mixed>  $usage
     */
    private function settle(AiUsageLedgerEntry $entry, AiUsageEntryStatus $status, int $actualCostMicrousd, array $usage): AiUsageLedgerEntry
    {
        return DB::transaction(function () use ($entry, $status, $actualCostMicrousd, $usage): AiUsageLedgerEntry {
            $claimed = AiUsageLedgerEntry::query()->lockForUpdate()->find($entry->id);

            if ($claimed === null || $claimed->status !== AiUsageEntryStatus::Reserved) {
                // Already settled or already expired. Adjusting the periods
                // again would release the same reservation twice.
                return $claimed ?? $entry;
            }

            $reserved = (int) $claimed->estimated_cost_microusd;
            $committed = max(0, min($actualCostMicrousd, $reserved));

            $this->adjustPeriods($claimed, releaseReserved: $reserved, addCommitted: $committed);

            $claimed->forceFill(array_merge($usage, [
                'status' => $status,
                'actual_cost_microusd' => $committed,
                'settled_at' => Carbon::now(),
            ]))->save();

            return $claimed;
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

        $released = 0;

        foreach ($stale as $entry) {
            // Correction 7 — the same settle() path, and therefore the same
            // lock order, as a provider call completing. Whichever of the
            // two claims the entry first wins; the other finds it no longer
            // `reserved` and changes nothing.
            $settled = $this->settle($entry, AiUsageEntryStatus::Released, 0, []);

            if ($settled->status === AiUsageEntryStatus::Released && $settled->settled_at !== null) {
                $released++;
            }
        }

        // The number actually released, not the number that looked stale:
        // a sweep racing live settlements releases fewer than it found, and
        // saying otherwise would misreport the work done.
        return $released;
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
