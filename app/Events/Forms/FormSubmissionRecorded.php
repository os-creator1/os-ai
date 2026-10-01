<?php

namespace App\Events\Forms;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor's form submission is now a committed fact — the durable seam a
 * later Automations lane (a `FormSubmitted` trigger) consumes.
 *
 * Nothing in the Forms domain calls Automations, and this is deliberately NOT
 * yet a `WorkflowTriggerType`: registering the trigger is that lane's job.
 *
 * AFTER COMMIT. Dispatched from inside the submission transaction, so a
 * submission that rolls back (a blacklisted phone, a failed Opportunity)
 * produces no event. A REPLAYED submission (the same operation token again)
 * never reaches the dispatch line — it returns the original row — so one
 * logical submission yields exactly one event.
 *
 * IDS ONLY, EXPLICITLY SCOPED. `businessId` and `locationId` are read from the
 * persisted submission, never inferred; a listener re-reads the rows. `contactId`
 * / `opportunityId` are null when the submission did not (or could not) produce
 * them, and `contactResolution` (a FormContactResolution value) says why.
 *
 * `occurrenceKey` is `form_submission:<submission uid>`, a function of the
 * persisted row alone, so any redelivery composes the same key and a consumer can
 * refuse the duplicate.
 */
final class FormSubmissionRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const NAME = 'form_submission_recorded';

    public function __construct(
        public readonly int $businessId,
        public readonly int $locationId,
        public readonly int $formId,
        public readonly int $formVersionId,
        public readonly int $submissionId,
        public readonly ?int $contactId,
        public readonly ?int $opportunityId,
        public readonly string $contactResolution,
        public readonly string $occurrenceKey,
    ) {
    }

    public static function occurrenceKeyFor(string $submissionUid): string
    {
        return 'form_submission:'.$submissionUid;
    }
}
