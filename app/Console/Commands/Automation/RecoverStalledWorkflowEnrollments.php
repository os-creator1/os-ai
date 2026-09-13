<?php

namespace App\Console\Commands\Automation;

use App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService;
use App\Library\Automation\Workflow\WorkflowLimits;
use Illuminate\Console\Command;

/**
 * Automations V2 §7.4/§8.2 — the lost-and-interrupted-work safety net.
 *
 * NAMING, DELIBERATELY DIFFERENT FROM THE CONTRACT. §8.2 describes one command,
 * `automation:workflows-resume-due`, doing two jobs: waking `waiting` enrollments
 * whose time has come, and recovering stalled `active` ones. This command is the
 * recovery half only.
 *
 * The wake half now exists beside it, as `automation:workflows-resume-due`
 * (ResumeDueWorkflowEnrollments), and the two were kept apart rather than merged.
 * They select disjoint sets — that command takes `status = waiting`, this one
 * takes `status = active` — so no enrollment is ever eligible for both, and none
 * of the semantics below had to change to make waiting journeys wake.
 *
 * It is emphatically NOT how Resume works. Resume re-dispatches held journeys
 * immediately through RedispatchHeldEnrollments, and this sweep skips paused
 * workflows entirely so the two can never be confused.
 */
class RecoverStalledWorkflowEnrollments extends Command
{
    protected $signature = 'automation:workflows-recover-stalled';

    protected $description = 'Recover workflow enrollments whose advance job was lost or interrupted';

    public function handle(WorkflowRecoveryService $recovery): int
    {
        $counts = $recovery->recoverStalled();

        $this->info(sprintf(
            'Recovered stalled enrollments older than %d minutes: %d re-dispatched, %d failed as interrupted, %d expired.',
            WorkflowLimits::STALE_ACTIVE_RECOVERY_MINUTES,
            $counts['redispatched'],
            $counts['failed'],
            $counts['expired'],
        ));

        return self::SUCCESS;
    }
}
