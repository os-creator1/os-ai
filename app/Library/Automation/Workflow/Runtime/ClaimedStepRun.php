<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflowNode;

/**
 * The step run the advancer claimed for a node before calling its executor.
 *
 * Found by `UNIQUE(enrollment_id, node_id)`, so it is exactly one row and exactly
 * the claim an executor's side effect belongs to. The advancer always creates it
 * first; null is only possible for a caller that invokes an executor outside the
 * advancer (a simulation's transient, unsaved objects have no key at all).
 *
 * It is the identity every deterministic side effect keys on — the Send SMS
 * Reports mark, the Send email operation key, the tag actions' causation
 * reference — so replaying the same node of the same journey composes the same
 * identity and a domain seam recognises the replay. Lifted out of
 * SendSmsNodeExecutor unchanged.
 */
final class ClaimedStepRun
{
    public static function idFor(AutomationWorkflowNode $node, AutomationEnrollment $enrollment): ?int
    {
        if ($enrollment->getKey() === null || $node->getKey() === null) {
            return null;
        }

        $id = AutomationStepRun::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('node_id', $node->getKey())
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
