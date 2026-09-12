<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Jobs\Automation\Workflow\RedispatchHeldEnrollments;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automations V2 §6.3 — pause, resume, archive, stop-all.
 *
 * PAUSE AND RESUME ARE ASYMMETRIC, AND THAT IS THE POINT.
 *
 * Pause is passive: it flips the workflow's status under an exclusive row lock
 * and stops. Nothing is written to any enrollment. An advance job already in
 * flight either committed its claim before the pause (that one step finishes) or
 * meets the pause in its pre-check and exits WITHOUT claiming — which leaves the
 * journey untouched at its cursor. That is what "held" means: not a state, just
 * an active enrollment nobody is currently running.
 *
 * Resume is active: it must go and find those held journeys and start them again.
 * The contract is explicit that it may NOT rely on the stale-active recovery
 * sweep for this — 15 minutes is a safety net for lost jobs, not a resume
 * mechanism, and a customer who clicks Resume should not wait a quarter of an
 * hour. So resume dispatches a bounded, self-continuing re-dispatch job, AFTER its
 * own transaction commits, and never touches a `waiting` row.
 *
 * Re-dispatching is safe to do twice: it writes nothing, and every job it
 * enqueues has to win the claim before it can act.
 */
class WorkflowLifecycleService implements WorkflowLifecycle
{
    public function pause(AutomationWorkflow $workflow): void
    {
        DB::transaction(function () use ($workflow): void {
            // Exclusive, against the shared lock every step claim takes. A pause
            // therefore waits for in-flight claims to commit and then blocks the
            // next one — the two can never interleave.
            $locked = AutomationWorkflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== WorkflowStatus::Published) {
                return;
            }

            $locked->forceFill(['status' => WorkflowStatus::Paused])->save();
        });
    }

    public function resume(AutomationWorkflow $workflow): void
    {
        $resumed = DB::transaction(function () use ($workflow): bool {
            $locked = AutomationWorkflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== WorkflowStatus::Paused) {
                return false;
            }

            if ($locked->published_version_id === null) {
                // Nothing to resume onto. Paused without a published version can
                // only happen through an unusual sequence, and quietly marking it
                // published would leave a workflow that accepts enrollments with
                // no graph to run.
                return false;
            }

            $locked->forceFill(['status' => WorkflowStatus::Published])->save();

            return true;
        });

        if (! $resumed) {
            return;
        }

        // AFTER COMMIT, and outside the transaction: no step executes inside the
        // resume request, and no provider is ever called from it.
        RedispatchHeldEnrollments::dispatch((int) $workflow->getKey())->afterCommit();
    }

    public function archive(AutomationWorkflow $workflow): void
    {
        DB::transaction(function () use ($workflow): void {
            $locked = AutomationWorkflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status === WorkflowStatus::Archived) {
                return;
            }

            $locked->forceFill([
                'status' => WorkflowStatus::Archived,
                'archived_at' => Carbon::now(),
            ])->save();
        });

        $this->cancelInFlight($workflow, EnrollmentStatus::Cancelled, 'workflow_archived');
    }

    public function stopAllActive(AutomationWorkflow $workflow, string $exitReason): int
    {
        return $this->cancelInFlight($workflow, EnrollmentStatus::Cancelled, $exitReason);
    }

    /**
     * Close every journey still in flight, in bounded batches — the house
     * convention for anything that could touch an unbounded number of rows.
     */
    private function cancelInFlight(
        AutomationWorkflow $workflow,
        EnrollmentStatus $status,
        string $reason,
    ): int {
        $cancelled = 0;

        AutomationEnrollment::query()
            ->where('workflow_id', $workflow->getKey())
            ->whereIn('status', array_map(
                static fn (EnrollmentStatus $s): string => $s->value,
                EnrollmentStatus::occupyingStatuses(),
            ))
            ->orderBy('id')
            ->chunkById(WorkflowLimits::SWEEP_CHUNK_SIZE, function ($enrollments) use ($status, $reason, &$cancelled): void {
                foreach ($enrollments as $enrollment) {
                    $affected = AutomationEnrollment::query()
                        ->whereKey($enrollment->getKey())
                        ->whereIn('status', array_map(
                            static fn (EnrollmentStatus $s): string => $s->value,
                            EnrollmentStatus::occupyingStatuses(),
                        ))
                        ->update([
                            'status' => $status->value,
                            'current_node_id' => null,
                            'resume_at' => null,
                            'completed_at' => Carbon::now(),
                            'exit_reason' => Str::limit($reason, 60, ''),
                            'updated_at' => Carbon::now(),
                        ]);

                    $cancelled += $affected;
                }
            });

        return $cancelled;
    }
}
