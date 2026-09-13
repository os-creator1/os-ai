<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflowNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 §8.2 — waking journeys whose wait is over.
 *
 * THIS IS NOT RECOVERY, AND DELIBERATELY SHARES NOTHING WITH IT. The contract
 * originally described one command doing both; the runtime core shipped stale-
 * ACTIVE recovery as WorkflowRecoveryService, and that architecture is kept
 * rather than consolidated. The two cannot collide or double-process, and the
 * reason is structural rather than a matter of care: recovery queries
 * `status = active` and this queries `status = waiting`. An enrollment is one or
 * the other, never both, so no row is ever eligible for both sweeps in the same
 * instant. Recovery's fifteen-minute threshold, its lost-job/interrupted-step
 * distinction, its refusal to re-run external side effects and its skipping of
 * paused workflows are all untouched by this class.
 *
 * WORKFLOW STATUS IS NOT FILTERED, on purpose. A paused workflow's waiting
 * journeys still wake, because waking EXECUTES NOTHING — it moves the cursor
 * past a wait that genuinely elapsed and asks the advancer to look. The
 * advancer's checkpoint is the single place that decides whether a step may
 * actually run, and for a paused workflow it holds. Filtering here instead would
 * mean a month-long pause silently swallowed every wait that expired during it.
 *
 * THE WAKE IS CLAIMED, exactly like a step is. The transition
 * `waiting → active` is a conditional UPDATE predicated on the row still being
 * `waiting` with a due `resume_at`; MySQL reports one affected row to exactly one
 * worker, and every other concurrent sweep sees zero and moves on. Because the
 * claim, the step-run completion and the cursor move are one transaction, a
 * loser can never execute the successor and a crash can never leave a journey
 * active but still pointing at the wait it already finished.
 */
class WorkflowWakeService
{
    public function __construct(private readonly WorkflowAdvancer $advancer)
    {
    }

    /**
     * Wake every due waiting enrollment, bounded.
     *
     * `$maxRows` exists so the per-run cap can be PROVEN rather than asserted:
     * production always passes null and gets §8.4's 2,000, but a test can drive
     * the same code path with a small number instead of manufacturing two
     * thousand enrollments to reach the real bound. The mechanism under test is
     * identical; only the number differs.
     *
     * @return array{woken: int, lost: int, examined: int} `lost` counts rows another
     *         worker claimed first — normal under concurrency, not an error
     */
    public function wakeDue(?Carbon $now = null, ?int $maxRows = null): array
    {
        $now ??= Carbon::now();
        $maxRows ??= WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN;

        $counts = ['woken' => 0, 'lost' => 0, 'examined' => 0];

        // Served by the (status, resume_at) index the foundation already
        // created, so this is a range read of due rows and never a table scan.
        AutomationEnrollment::query()
            ->where('status', EnrollmentStatus::Waiting->value)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(WorkflowLimits::SWEEP_CHUNK_SIZE, function ($enrollments) use (&$counts, $now, $maxRows): bool {
                foreach ($enrollments as $enrollment) {
                    if ($counts['examined'] >= $maxRows) {
                        // The per-run cap. Whatever is left stays due and is
                        // taken by the next minute's sweep, so a large backlog
                        // drains steadily instead of one run trying to do it all.
                        return false;
                    }

                    $counts['examined']++;

                    if ($this->wakeOne($enrollment, $now)) {
                        $counts['woken']++;
                    } else {
                        $counts['lost']++;
                    }
                }

                return true;
            });

        return $counts;
    }

    /**
     * Claim and wake one enrollment.
     *
     * @return bool whether THIS caller won the wake
     */
    private function wakeOne(AutomationEnrollment $enrollment, Carbon $now): bool
    {
        $won = DB::transaction(function () use ($enrollment, $now): bool {
            // THE CLAIM. One affected row, one winner. Everything after this
            // line runs only for the worker that won it, inside the same
            // transaction, so there is no window in which a second worker can
            // see an already-claimed journey as still wakeable.
            $claimed = AutomationEnrollment::query()
                ->whereKey($enrollment->getKey())
                ->where('status', EnrollmentStatus::Waiting->value)
                ->whereNotNull('resume_at')
                ->where('resume_at', '<=', $now)
                ->update([
                    'status' => EnrollmentStatus::Active->value,
                    'resume_at' => null,
                    'last_advanced_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($claimed !== 1) {
                return false;
            }

            $nodeId = $enrollment->current_node_id === null ? null : (int) $enrollment->current_node_id;

            if ($nodeId === null) {
                // A waiting journey with no cursor is not something that can be
                // continued; close it honestly rather than leaving it active
                // with nowhere to go.
                $this->advancer->finish($enrollment->fresh(), EnrollmentStatus::Exited, 'node_missing');

                return true;
            }

            // The wait is over, so its step run is genuinely finished.
            AutomationStepRun::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('node_id', $nodeId)
                ->where('status', StepRunStatus::Waiting->value)
                ->update([
                    'status' => StepRunStatus::Succeeded->value,
                    'completed_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            $node = AutomationWorkflowNode::query()->find($nodeId);

            if ($node === null) {
                $this->advancer->finish($enrollment->fresh(), EnrollmentStatus::Exited, 'node_missing');

                return true;
            }

            $successorId = $this->advancer->successorOf($node);

            if ($successorId === null) {
                // A wait at the end of a path: the journey is simply over.
                $this->advancer->finish($enrollment->fresh(), EnrollmentStatus::Completed, null);

                return true;
            }

            // Move past the wait. Conditional on the cursor for the same reason
            // every other advance is: never overwrite a newer decision.
            AutomationEnrollment::query()
                ->whereKey($enrollment->getKey())
                ->where('current_node_id', $nodeId)
                ->where('status', EnrollmentStatus::Active->value)
                ->update([
                    'current_node_id' => $successorId,
                    'step_count' => DB::raw('step_count + 1'),
                    'last_advanced_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            return true;
        });

        if ($won) {
            // Dispatched only after the transaction commits, so the worker that
            // picks it up cannot read a half-woken journey.
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $won;
    }
}
