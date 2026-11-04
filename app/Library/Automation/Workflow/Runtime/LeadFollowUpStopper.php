<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Models\AutomationEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Revenue journey — ends a lead's in-flight FOLLOW-UP journeys the moment the
 * customer's own action makes the follow-up wrong: they replied, they booked,
 * or they opted out.
 *
 * SCOPE, DELIBERATELY NARROW. Only journeys that were started by a lead-ACQUISITION
 * fact (a form, a new contact, a new opportunity, a manual enrollment; tags are excluded because a booking can itself add one) are
 * stopped. A journey started by the very event that triggers this stop — a booking
 * confirmation, a reply-handling workflow — is never touched, because those trigger
 * types are not in {@see self::FOLLOW_UP_TRIGGERS} and because only enrollments that
 * began strictly before the outcome are eligible.
 *
 * It never sends anything, never reads a message body, and never widens past the
 * Business and Contact it is given: every update names both. A terminal enrollment
 * is never reopened, and the conditional UPDATE makes a replayed event a no-op.
 */
class LeadFollowUpStopper
{
    public const REPLIED = 'contact_replied';

    public const BOOKED = 'contact_booked';

    public const OPTED_OUT = 'contact_opted_out';

    /** @var list<WorkflowTriggerType> */
    public const FOLLOW_UP_TRIGGERS = [
        WorkflowTriggerType::FormSubmitted,
        WorkflowTriggerType::ContactCreated,
        WorkflowTriggerType::OpportunityCreated,
        WorkflowTriggerType::ManualEnrollment,
    ];

    /**
     * @param  array<int, int>  $contactIds
     * @return int how many journeys were stopped
     */
    public function stop(int $businessId, array $contactIds, string $reason, ?Carbon $outcomeAt = null): int
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));

        if ($contactIds === []) {
            return 0;
        }

        $cutoff = $outcomeAt ?? Carbon::now();
        $now = Carbon::now();

        return (int) DB::table('automation_enrollments')
            ->where('business_id', $businessId)
            ->whereIn('contact_id', $contactIds)
            ->whereIn('status', array_map(static fn (EnrollmentStatus $s): string => $s->value, EnrollmentStatus::occupyingStatuses()))
            ->whereIn('trigger_type', array_map(static fn (WorkflowTriggerType $t): string => $t->value, self::FOLLOW_UP_TRIGGERS))
            ->where('enrolled_at', '<=', $cutoff)
            ->update([
                'status' => EnrollmentStatus::Exited->value,
                'current_node_id' => null,
                'resume_at' => null,
                'completed_at' => $now,
                'exit_reason' => $reason,
                'updated_at' => $now,
            ]);
    }
}
