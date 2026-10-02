<?php

namespace App\Library\Automation\Workflow\Triggers;

/**
 * The facts a "Form submitted" trigger hands a workflow run — identifiers only,
 * all belonging to one Business.
 *
 * Built from the persisted `form_submissions` row, which is write-once: the
 * Business, Location, Form, FormVersion and submission it names, and the Contact
 * and Opportunity the submission itself linked, read the same when a queued job
 * runs late, when the event is replayed, and when a journey asks again weeks
 * later from its enrollment's occurrence key (`form_submission:{uid}`).
 *
 * NO ANSWERS ARE CARRIED. The submitted values live on the immutable submission
 * row and are never re-derived from the Form's current version; nothing in the
 * Automation vocabulary reads them yet, and when a condition needs one it reads
 * that row by `submissionId`, never the Form definition.
 */
final readonly class FormSubmittedTriggerContext
{
    /** Matches App\Events\Forms\FormSubmissionRecorded::occurrenceKeyFor(). */
    public const OCCURRENCE_PREFIX = 'form_submission:';

    public function __construct(
        public int $businessId,
        public int $locationId,
        public int $formId,
        public int $formVersionId,
        public int $submissionId,
        public string $submissionUid,
        public ?int $contactId,
        public ?int $opportunityId,
    ) {
    }

    /** The Forms domain's own occurrence key for this submission. */
    public function occurrenceKey(): string
    {
        return self::OCCURRENCE_PREFIX . $this->submissionUid;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'business_id' => $this->businessId,
            'location_id' => $this->locationId,
            'form_id' => $this->formId,
            'form_version_id' => $this->formVersionId,
            'submission_id' => $this->submissionId,
            'contact_id' => $this->contactId,
            'opportunity_id' => $this->opportunityId,
            'occurrence_key' => $this->occurrenceKey(),
        ];
    }
}
