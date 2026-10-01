<?php

namespace App\Events\Forms;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `form_submitted` — a visitor's form submission is now a committed fact.
 *
 * The canonical occurrence a later Automations lane consumes. Nothing in
 * this domain calls Automations, and this is deliberately NOT yet a
 * WorkflowTriggerType: registering the trigger is that lane's job.
 *
 * AFTER COMMIT. Raised from inside the submission transaction, so a
 * submission that rolls back (a blacklisted phone, a failed Opportunity)
 * produces no event. A replayed submission (the same idempotency token
 * posted again) never reaches this line — it returns the original row —
 * so one logical submission yields exactly one event.
 *
 * IDS ONLY, EXPLICITLY SCOPED. `businessId` and `locationId` come from the
 * persisted submission, never inferred; a listener re-reads the rows.
 * `contactId` / `opportunityId` are null when the submission did not (or
 * could not) produce them — `contactResolution` says why (`created`,
 * `matched`, `ambiguous` when several same-Location Contacts share the
 * phone and none is picked arbitrarily, `none` when no phone was given).
 *
 * `occurrenceKey` is built from the persisted submission row, so a replayed
 * delivery composes the same key and the consumer can refuse the duplicate.
 */
final class FormSubmissionRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const NAME = 'form_submitted';

    public function __construct(
        public readonly int $businessId,
        public readonly int $locationId,
        public readonly int $formId,
        public readonly string $formUid,
        public readonly int $submissionId,
        public readonly string $submissionUid,
        public readonly ?int $contactId,
        public readonly ?int $opportunityId,
        public readonly string $contactResolution,
        public readonly string $occurrenceKey,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public static function occurrenceKeyFor(int $submissionId): string
    {
        return self::NAME.':'.$submissionId;
    }
}
