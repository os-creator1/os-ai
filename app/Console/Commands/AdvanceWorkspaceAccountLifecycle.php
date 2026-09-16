<?php

namespace App\Console\Commands;

use App\Library\Entitlement\EntitlementManager;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 03 §4/§6/§7 (Slice 4) — the two purely TIME-BASED account
 * lifecycle transitions, and only those two:
 *
 *   sweep 1  trial ran out without conversion  -> enterGracePeriod()   (§6 case B)
 *   sweep 2  Grace's 3 days elapsed unpaid     -> lockForNonPayment()  (§6 case D)
 *
 * Both are things this table's own data can prove on its own. Everything
 * that needs a payment-provider signal (case C's renewal failure, case E's
 * recovery) is deliberately NOT here: those writers exist and are callable,
 * but wiring a provider to them is a separate integration, not this
 * command's business.
 *
 * All transaction, locking, transition-row and event behavior lives in
 * EntitlementManager — this command only offers each candidate Workspace to
 * it and reports what happened, exactly the division
 * SweepExpiredOpportunitySnoozes already uses for its own sweep. It never
 * queries the entitlement tables itself (RFC-004 §15/§20).
 *
 * The candidate lists are read once, before any lock, and can go stale while
 * the run works through them: a customer can pay, or be suspended, between
 * the query and their turn. So every Workspace goes through
 * advanceExpiredTrialIntoGrace() / lockElapsedGracePeriod(), which re-check
 * §7's exact predicate under the Workspace row lock and write through the
 * contract's named writer only if it still holds. A Workspace that no longer
 * qualifies is counted as "no longer eligible" — the lock doing its job, not
 * a failure.
 *
 * Per-Workspace isolation (§7): each Workspace gets its own transaction, and
 * an exception on one is logged and counted as failed rather than aborting
 * the run — one bad row must not stop every other delinquent account from
 * locking. Nothing is skipped silently.
 *
 * Safely re-runnable: a Workspace a previous run advanced no longer matches
 * either predicate, so a second run writes nothing for it.
 */
class AdvanceWorkspaceAccountLifecycle extends Command
{
    /** Contract 03 §6 case B's exact reason string. */
    private const TRIAL_EXPIRED_REASON = 'Trial ended without conversion';

    /** Contract 03 §6 case D's exact reason string. */
    private const GRACE_ELAPSED_REASON = 'Grace period elapsed without payment';

    protected $signature = 'workspaces:advance-account-lifecycle';

    protected $description = 'Advance time-based Workspace account lifecycle: expired trials into Grace, elapsed Grace into Locked';

    public function handle(EntitlementManager $entitlementManager, WorkspaceRepository $workspaceRepository): int
    {
        // Sweep order is irrelevant to correctness — sweep 1 requires
        // grace_started_at IS NULL and sweep 2 requires it IS NOT NULL, and
        // sweep 2's candidate list is read only after sweep 1 has finished,
        // so no Workspace can be advanced twice in one run (§7).
        $trials = $this->sweep(
            'trial-expiry',
            $entitlementManager->findWorkspaceIdsWithExpiredOutstandingTrial(),
            $workspaceRepository,
            fn (Workspace $workspace): bool => $entitlementManager->advanceExpiredTrialIntoGrace($workspace, self::TRIAL_EXPIRED_REASON),
        );

        $graces = $this->sweep(
            'grace-elapsed',
            $entitlementManager->findWorkspaceIdsWithElapsedGracePeriod(),
            $workspaceRepository,
            fn (Workspace $workspace): bool => $entitlementManager->lockElapsedGracePeriod($workspace, self::GRACE_ELAPSED_REASON),
        );

        $this->info("Expired trials moved into grace: {$trials['advanced']} (no longer eligible: {$trials['ineligible']}, failed: {$trials['failed']}).");
        $this->info("Elapsed grace periods locked: {$graces['advanced']} (no longer eligible: {$graces['ineligible']}, failed: {$graces['failed']}).");

        return self::SUCCESS;
    }

    /**
     * One sweep: offer every candidate Workspace to one lock-checked sweep
     * step, isolating failures per Workspace.
     *
     * @param array<int, int> $workspaceIds
     * @param callable(Workspace): bool $advance true when it wrote, false when the Workspace no longer qualified
     * @return array{advanced: int, ineligible: int, failed: int}
     */
    private function sweep(string $sweep, array $workspaceIds, WorkspaceRepository $workspaceRepository, callable $advance): array
    {
        $counts = ['advanced' => 0, 'ineligible' => 0, 'failed' => 0];

        foreach ($workspaceIds as $workspaceId) {
            try {
                $workspace = $workspaceRepository->findById((int) $workspaceId);

                if ($workspace === null) {
                    $counts['failed']++;
                    Log::warning('Account lifecycle sweep could not load a candidate Workspace.', [
                        'sweep' => $sweep,
                        'workspace_id' => $workspaceId,
                    ]);

                    continue;
                }

                $counts[$advance($workspace) ? 'advanced' : 'ineligible']++;
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::warning('Account lifecycle sweep could not advance a Workspace.', [
                    'sweep' => $sweep,
                    'workspace_id' => $workspaceId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $counts;
    }
}
