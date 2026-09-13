<?php

namespace App\Console\Commands\Automation;

use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use Illuminate\Console\Command;

/**
 * Automations V2 §8.2 — waking workflow journeys whose wait is over.
 *
 * Runs every minute, which is the resolution the contract promises: a "wait 5
 * minutes" resumes between five and six minutes later, and sub-minute precision
 * is an explicit non-goal (§8.3).
 *
 * THE SPLIT WITH RECOVERY IS INTENTIONAL. §8.2 sketches one command doing both
 * this and stale-active recovery. The runtime core shipped recovery separately
 * as `automation:workflows-recover-stalled`, and that proven architecture is
 * kept: the two sweeps select disjoint sets — `status = waiting` here,
 * `status = active` there — so nothing is processed twice, and none of
 * recovery's semantics (the fifteen-minute threshold, lost-job versus
 * interrupted-step, never re-running an external side effect, skipping paused
 * workflows) had to be touched or re-implemented to add this.
 *
 * Waking executes nothing by itself. It moves a journey past a wait that really
 * elapsed and asks the advancer to look; the checkpoint still decides whether
 * anything may run, which is why a paused workflow's waits can wake safely.
 */
class ResumeDueWorkflowEnrollments extends Command
{
    protected $signature = 'automation:workflows-resume-due';

    protected $description = 'Wake workflow enrollments whose wait has elapsed';

    public function handle(WorkflowWakeService $wake): int
    {
        $counts = $wake->wakeDue();

        $this->info(sprintf(
            'Due waits examined: %d. %d woken, %d already claimed by another worker.',
            $counts['examined'],
            $counts['woken'],
            $counts['lost'],
        ));

        return self::SUCCESS;
    }
}
