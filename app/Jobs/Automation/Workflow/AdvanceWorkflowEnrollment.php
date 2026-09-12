<?php

namespace App\Jobs\Automation\Workflow;

use App\Jobs\Base;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationEnrollment;

/**
 * Automations V2 §8.2 — run one enrollment forward.
 *
 * Carries an id, never a serialized model, so the advancer always works from
 * rows read at execution time rather than from whatever was true when the job was
 * queued.
 *
 * `$tries = 1` is inherited from Base and deliberately not raised (Lane F §6.1):
 * an automatic retry of a step that may already have called a provider is exactly
 * the duplicate this engine exists to prevent. Recovery of genuinely lost work is
 * the recovery sweep's job, and it decides by side-effect class whether re-running
 * is safe at all.
 *
 * Duplicate delivery of this job is harmless by design: two copies race for the
 * claim, one loses, and the loser does nothing.
 */
class AdvanceWorkflowEnrollment extends Base
{
    public function __construct(private readonly int $enrollmentId)
    {
        $this->onQueue('automation');
    }

    public function handle(WorkflowAdvancer $advancer): void
    {
        $enrollment = AutomationEnrollment::query()->find($this->enrollmentId);

        if ($enrollment === null) {
            return;
        }

        // The advancer runs a bounded number of steps and tells us whether there
        // is more to do; continuing in a fresh job keeps any single job well
        // inside the worker's timeout.
        if ($advancer->advance($enrollment)) {
            self::dispatch($this->enrollmentId);
        }
    }
}
