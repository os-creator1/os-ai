<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automations V2 §7.5 — the one door into a workflow.
 *
 * Every trigger source, and the manual-enrollment endpoint, come through here.
 * Concentrating it matters because four separate rules have to hold together on
 * every entry, and four callers each half-remembering them is how duplicates and
 * cross-tenant journeys happen:
 *
 *   THE PIN      the version is the workflow's published version AT THIS MOMENT,
 *                recorded once and never changed. That is what makes a republish
 *                invisible to journeys already running.
 *   THE CLAIM    the enrollment key is composed from the PINNED version's policy
 *                and inserted under UNIQUE(enrollment_key). A duplicate trigger
 *                loses the insert and returns null — an ordinary no-op, not an
 *                error.
 *   THE GUARD    `active_contact_guard` separately forbids one contact occupying
 *                one workflow twice at once, whatever the policy says.
 *   TENANCY      the contact must belong to the workflow's Business, re-checked
 *                here even though callers check it, because this is the boundary
 *                that actually creates the row.
 */
class WorkflowEnrollmentService implements EnrollmentService
{
    public function enroll(
        AutomationWorkflow $workflow,
        Contacts $contact,
        string $triggerOccurrenceKey,
        int $causationDepth = 0,
    ): ?AutomationEnrollment {
        if ($causationDepth > WorkflowLimits::MAX_CAUSATION_DEPTH) {
            // One automation triggering another, too many levels deep. Refusing
            // at the door is cheaper and safer than unwinding later.
            return null;
        }

        // Re-read rather than trust the caller's copy: a workflow paused or
        // archived between the trigger firing and this call must not enroll.
        $fresh = AutomationWorkflow::query()->find($workflow->getKey());

        if ($fresh === null || ! $fresh->acceptsNewEnrollments()) {
            return null;
        }

        if ($contact->business_id === null || (int) $contact->business_id !== (int) $fresh->business_id) {
            return null;
        }

        $version = AutomationWorkflowVersion::query()->find($fresh->published_version_id);

        if ($version === null || $version->isDraft()) {
            // Only a published version may be pinned. A draft would mean an
            // editable definition running underneath a live contact.
            return null;
        }

        $rootNodeId = $this->rootNodeId($version);

        if ($rootNodeId === null) {
            return null;
        }

        $policy = $version->enrollment_policy ?? EnrollmentPolicy::OnceEver;
        $key = $policy->enrollmentKey(
            (int) $fresh->getKey(),
            (int) $contact->getKey(),
            $triggerOccurrenceKey,
        );

        try {
            return DB::transaction(function () use ($fresh, $version, $contact, $key, $triggerOccurrenceKey, $causationDepth, $rootNodeId): AutomationEnrollment {
                $enrollment = new AutomationEnrollment([
                    'business_id' => $fresh->business_id,
                    'workflow_id' => $fresh->getKey(),
                    'version_id' => $version->getKey(),
                    'contact_id' => $contact->getKey(),
                    'status' => EnrollmentStatus::Active,
                    'current_node_id' => $rootNodeId,
                    'trigger_type' => $version->trigger_type,
                    'trigger_occurrence_key' => Str::limit($triggerOccurrenceKey, 190, ''),
                    'enrollment_key' => $key,
                    'causation_depth' => $causationDepth,
                    'step_count' => 0,
                    'enrolled_at' => Carbon::now(),
                ]);
                $enrollment->uid = (string) Str::uuid();
                $enrollment->save();

                return $enrollment;
            });
        } catch (UniqueConstraintViolationException) {
            // Either this contact already entered (the key), or they are already
            // part-way through (the guard). Both are correct refusals, and both
            // are silent: a repeated trigger is not a problem to report.
            return null;
        }
    }

    /**
     * The compiled root of the pinned version — where every journey starts.
     * Read from the node rows, never from the definition document.
     */
    private function rootNodeId(AutomationWorkflowVersion $version): ?int
    {
        $id = DB::table('automation_workflow_nodes')
            ->where('version_id', $version->getKey())
            ->where('node_type', 'trigger')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
