<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;

/**
 * Automations V2 §9 — "I enroll someone by hand".
 *
 * Domain layer only. V2-E owns the HTTP action, its permission and its request
 * validation; this class is what that action ends up calling, and what the
 * queued EnrollWorkflowContact job calls.
 *
 * TENANCY IS PROVED HERE, NOT ASSUMED. The caller passes models, but this is
 * background work: by the time it runs, the workflow may have been archived and
 * the contact may have been moved or deleted. So both sides are checked against
 * each other and against the same Business before anything is enrolled, and
 * EnrollmentService checks tenancy AGAIN at the row it creates (§7.5). Two
 * checks is not redundancy — the second one is the boundary that actually
 * creates data, and the first one is what stops the job from doing work it
 * should never have been given.
 *
 * The occurrence key is the manual request's own uid, so clicking "enroll"
 * twice is two deliberate requests, while a duplicate DELIVERY of one request
 * composes the same key and is refused by the claim (§7.5).
 */
class ManualEnrollmentTriggerSource implements TriggerSource
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function triggerType(): WorkflowTriggerType
    {
        return WorkflowTriggerType::ManualEnrollment;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param string $requestUid server-derived identifier for the one manual
     *        request being honoured. Never client input.
     */
    public function enrollByHand(
        AutomationWorkflow $workflow,
        Contacts $contact,
        string $requestUid,
    ): ?AutomationEnrollment {
        if (! $this->sameBusiness($workflow, $contact)) {
            return null;
        }

        $enrollment = $this->enrollments->enroll($workflow, $contact, $requestUid);

        if ($enrollment !== null) {
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $enrollment;
    }

    /**
     * Both rows must name a Business, and the same one. A contact with no
     * Business never enrolls a Business workflow, and a workflow can never
     * reach across accounts (§14.2).
     */
    private function sameBusiness(AutomationWorkflow $workflow, Contacts $contact): bool
    {
        if ($workflow->business_id === null || $contact->business_id === null) {
            return false;
        }

        return (int) $workflow->business_id === (int) $contact->business_id;
    }
}
