<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Merge\MergeContext;
use App\Models\Appointment;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Library\Automation\Workflow\Triggers\CrmOpportunityTriggerContext;
use Illuminate\Support\Facades\DB;

/**
 * Builds the explicit MergeContext for one automation run.
 *
 * Location: the enrolled Contact's OWN Location (never a guess). Opportunity and
 * Appointment: present only when the enrollment's trigger IS an Opportunity /
 * Appointment trigger, read back from the enrollment's persisted occurrence key
 * (the same fact the trigger fired with). Every other trigger yields neither, so
 * those tokens resolve to the documented missing-value behaviour.
 *
 * Cross-Business ids cannot get through: the Appointment must belong to a
 * Location of the Business and to this Contact, the Opportunity to this Business
 * (MergeFieldResolver re-proves both).
 */
class AutomationMergeContextFactory
{
    public function for(AutomationEnrollment $enrollment, Business $business, Contacts $contact): ?MergeContext
    {
        $base = MergeContext::forContact($contact, $business);

        if ($base === null) {
            return null;
        }

        $type = $enrollment->trigger_type instanceof WorkflowTriggerType
            ? $enrollment->trigger_type
            : WorkflowTriggerType::tryFrom((string) $enrollment->trigger_type);
        $key = (string) $enrollment->trigger_occurrence_key;

        $appointment = null;
        $opportunity = null;

        if (in_array($type, [
            WorkflowTriggerType::AppointmentScheduled,
            WorkflowTriggerType::AppointmentCancelled,
            WorkflowTriggerType::AppointmentRescheduled,
        ], true) && preg_match('/^' . preg_quote($type->value, '/') . ':(\d+)(?::\d+)?$/', $key, $matches) === 1) {
            $appointment = Appointment::query()
                ->whereKey((int) $matches[1])
                ->where('contact_id', (int) $contact->id)
                ->first();
        }

        if (in_array($type, [
            WorkflowTriggerType::OpportunityCreated,
            WorkflowTriggerType::OpportunityStageChanged,
            WorkflowTriggerType::OpportunityWon,
            WorkflowTriggerType::OpportunityLost,
        ], true) && str_starts_with($key, CrmOpportunityTriggerContext::OCCURRENCE_PREFIX)) {
            $historyId = substr($key, strlen(CrmOpportunityTriggerContext::OCCURRENCE_PREFIX));

            $opportunityId = ctype_digit($historyId)
                ? DB::table('crm_opportunity_history')
                    ->where('id', (int) $historyId)
                    ->where('business_id', (int) $business->id)
                    ->value('opportunity_id')
                : null;

            if ($opportunityId !== null) {
                $opportunity = CrmOpportunity::query()
                    ->whereKey((int) $opportunityId)
                    ->where('business_id', (int) $business->id)
                    ->first();
            }
        }

        return new MergeContext($business, $contact, $base->location, $opportunity, $appointment);
    }
}
