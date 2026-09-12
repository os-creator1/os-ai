<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflowNode;
use Illuminate\Support\Carbon;

/**
 * Automations V2 §7.4 — the safety net for work that was lost, not held.
 *
 * WHAT THIS IS NOT: it is not how Resume works. Resume re-dispatches held
 * journeys immediately (§6.3, WorkflowLifecycleService), and this sweep pointedly
 * skips paused workflows so it can never be mistaken for that path or churn
 * through journeys that are deliberately held.
 *
 * WHAT IT IS: after WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES an `active`
 * enrollment of a PUBLISHED workflow that has not moved is one of exactly two
 * things, and the cursor's step run tells them apart:
 *
 *   NO STEP RUN AT THE CURSOR — the advance job was lost (a dropped queue, a
 *   deploy killed mid-flight). Nothing has happened, so simply dispatch it again;
 *   the claim protects against a duplicate.
 *
 *   A `started` STEP RUN AT THE CURSOR — a process died mid-step, and what may be
 *   done depends entirely on the node's side-effect class:
 *     • None or IdempotentDatabase — safe to re-derive. The step run is reopened
 *       and the journey re-dispatched.
 *     • External — NEVER re-executed. A text message may already have left the
 *       building, and B4 §5.1 rule 4 accepts losing the action over risking a
 *       duplicate. The step is failed with an outcome-unknown reason and the
 *       journey follows its failure policy.
 */
class WorkflowRecoveryService
{
    public function __construct(private readonly WorkflowAdvancer $advancer)
    {
    }

    /**
     * @return array{redispatched: int, failed: int, expired: int}
     */
    public function recoverStalled(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $threshold = $now->copy()->subMinutes(WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES);

        $counts = ['redispatched' => 0, 'failed' => 0, 'expired' => 0];
        $processed = 0;

        AutomationEnrollment::query()
            ->where('automation_enrollments.status', EnrollmentStatus::Active->value)
            // Never a paused workflow: those enrollments are held, and Resume owns
            // them.
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('automation_workflows')
                ->whereColumn('automation_workflows.id', 'automation_enrollments.workflow_id')
                ->where('automation_workflows.status', WorkflowStatus::Published->value))
            ->where(fn ($query) => $query
                ->where('last_advanced_at', '<', $threshold)
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('last_advanced_at')
                    ->where('enrolled_at', '<', $threshold)))
            ->orderBy('id')
            ->chunkById(WorkflowLimits::SWEEP_CHUNK_SIZE, function ($enrollments) use (&$counts, &$processed, $now): bool {
                foreach ($enrollments as $enrollment) {
                    if ($processed >= WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN) {
                        return false;
                    }

                    $processed++;
                    $this->recoverOne($enrollment, $counts, $now);
                }

                return true;
            });

        return $counts;
    }

    /** @param array{redispatched: int, failed: int, expired: int} $counts */
    private function recoverOne(AutomationEnrollment $enrollment, array &$counts, Carbon $now): void
    {
        // A journey that has outlived its cap is closed rather than resurrected.
        if ($enrollment->enrolled_at !== null
            && $enrollment->enrolled_at->diffInDays($now) > WorkflowLimits::MAX_ENROLLMENT_LIFETIME_DAYS) {
            $this->advancer->finish($enrollment, EnrollmentStatus::Exited, 'lifetime_exceeded');
            $counts['expired']++;

            return;
        }

        $stepRun = AutomationStepRun::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('node_id', $enrollment->current_node_id)
            ->first();

        if ($stepRun === null) {
            // Lost job: nothing ran, so just ask again.
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
            $counts['redispatched']++;

            return;
        }

        if (! $stepRun->isInterrupted()) {
            // The step finished but the cursor did not move — the advance died
            // between the two. Re-dispatching is safe: the claim will find the
            // step already run and the advancer will not repeat it.
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
            $counts['redispatched']++;

            return;
        }

        $node = AutomationWorkflowNode::query()->find($enrollment->current_node_id);

        if ($node === null) {
            $this->advancer->finish($enrollment, EnrollmentStatus::Exited, 'node_missing');
            $counts['expired']++;

            return;
        }

        if ($node->node_type->sideEffectClass()->isSafeToReExecute()) {
            // Pure or idempotent: reopen the claim so the advancer can run it
            // again from a clean state.
            AutomationStepRun::query()
                ->whereKey($stepRun->getKey())
                ->where('status', StepRunStatus::Started->value)
                ->delete();

            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
            $counts['redispatched']++;

            return;
        }

        // External, and its outcome is unknown. It is never re-run.
        AutomationStepRun::query()
            ->whereKey($stepRun->getKey())
            ->where('status', StepRunStatus::Started->value)
            ->update([
                'status' => StepRunStatus::Failed->value,
                'completed_at' => Carbon::now(),
                'safe_error_summary' => 'interrupted_outcome_unknown',
                'updated_at' => Carbon::now(),
            ]);

        $this->advancer->finish($enrollment, EnrollmentStatus::Failed, 'interrupted_outcome_unknown');
        $counts['failed']++;
    }
}
