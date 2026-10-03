<?php

namespace App\Library\Automation\Workflow\Triggers;

use Illuminate\Support\Facades\DB;

/**
 * The causation reference an automation attaches to a domain write it makes, and
 * how a trigger source reads it back.
 *
 * When a workflow step changes something another domain announces — a tag, a
 * deal's stage, a document sent — that domain's after-commit event carries an opaque
 * `origin` (`automation_step_run:{id}`). A trigger source resolves it, INSIDE the
 * event's Business, to the workflow and causation depth of the step that made the
 * change, so it can (a) never re-trigger the producing workflow off its own output
 * and (b) enroll any other workflow at depth + 1, which EnrollmentService refuses
 * beyond MAX_CAUSATION_DEPTH. A person's own change has no origin and is depth 0.
 *
 * One mechanism for every domain; the Tags foundation introduced it and the CRM and
 * Documents seams reuse it unchanged.
 */
final class TriggerCause
{
    public const ORIGIN_PREFIX = 'automation_step_run:';

    /** The causation reference for one claimed step run. */
    public static function originFor(int $stepRunId): string
    {
        return self::ORIGIN_PREFIX . $stepRunId;
    }

    /**
     * The workflow and depth of the automation step that made this change, or null
     * for a person's own change — or for a reference that does not resolve to a step
     * run inside this Business, which is treated as no mark at all.
     *
     * @return array{workflow_id: int, depth: int}|null
     */
    public static function resolve(?string $origin, int $businessId): ?array
    {
        $origin = (string) $origin;

        if (! str_starts_with($origin, self::ORIGIN_PREFIX)) {
            return null;
        }

        $stepRunId = substr($origin, strlen(self::ORIGIN_PREFIX));

        if (! ctype_digit($stepRunId) || (int) $stepRunId <= 0) {
            return null;
        }

        $producer = DB::table('automation_step_runs as s')
            ->join('automation_enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->where('s.id', (int) $stepRunId)
            ->where('e.business_id', $businessId)
            ->first(['e.workflow_id', 'e.causation_depth']);

        return $producer === null
            ? null
            : ['workflow_id' => (int) $producer->workflow_id, 'depth' => (int) $producer->causation_depth];
    }
}
