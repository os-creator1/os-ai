<?php

namespace App\Library\Automation\Workflow\Contracts;

use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;

/**
 * Automations V2 §7.5 — the one place an enrollment may be created.
 *
 * Implemented by V2-A. Declared here so the trigger sources (V2-C) and the
 * manual-enrollment endpoint (V2-E) can be built against it in parallel.
 *
 * CONTRACT FOR THE IMPLEMENTATION:
 *
 *   1. The claim is an INSERT guarded by `UNIQUE(enrollment_key)`, with the
 *      duplicate caught. A pre-check is allowed as a fast path but is never the
 *      guarantee — two concurrent triggers must not both enroll.
 *   2. The key is composed by EnrollmentPolicy::enrollmentKey() from the PINNED
 *      version's policy, never from the workflow row and never from request
 *      input.
 *   3. `version_id` is the workflow's published version at this moment, and is
 *      never changed afterwards.
 *   4. The contact must belong to the workflow's Business; a mismatch enrolls
 *      nobody. Tenancy is re-verified here even though callers check it.
 *   5. A workflow that is not `published` enrolls nobody.
 *   6. Returns null — not an exception — when the claim was already taken, so a
 *      duplicate trigger is an ordinary no-op rather than an error.
 *   7. Location run-scope foundation (Blueprint §13, Addendum §5): a NEW
 *      enrollment MUST carry exactly one `business_location_id`, and this is
 *      the door that proves it — re-verified here even though every trigger
 *      source already resolves and pre-filters one, exactly like tenancy
 *      (rule 4). No provable Location, a foreign-Business Location, an
 *      archived one, or one the pinned version's scope does not admit
 *      (`WorkflowLocationAdmission`, §10) all enroll nobody. Once written it
 *      is never reassigned by any code path (§6/§13).
 */
interface EnrollmentService
{
    /**
     * @param int|null $businessLocationId the run's candidate Location,
     *        server-derived from the authoritative triggering subject (the
     *        Contact, the CRM Opportunity, the inbound conversation) —
     *        never client input, never guessed, never a "first" or
     *        "primary" fallback. Null means "no Location could be proven",
     *        which enrolls nobody.
     * @param string $triggerOccurrenceKey server-derived: a year in the Business
     *        timezone, a provider message id, a manual-request uid. Never client
     *        input.
     * @param int $causationDepth how many automations deep this chain already
     *        is; refused beyond WorkflowLimits::MAX_CAUSATION_DEPTH.
     */
    public function enroll(
        AutomationWorkflow $workflow,
        Contacts $contact,
        ?int $businessLocationId,
        string $triggerOccurrenceKey,
        int $causationDepth = 0,
    ): ?AutomationEnrollment;
}
