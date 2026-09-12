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
 */
interface EnrollmentService
{
    /**
     * @param string $triggerOccurrenceKey server-derived: a year in the Business
     *        timezone, a provider message id, a manual-request uid. Never client
     *        input.
     * @param int $causationDepth how many automations deep this chain already
     *        is; refused beyond WorkflowLimits::MAX_CAUSATION_DEPTH.
     */
    public function enroll(
        AutomationWorkflow $workflow,
        Contacts $contact,
        string $triggerOccurrenceKey,
        int $causationDepth = 0,
    ): ?AutomationEnrollment;
}
