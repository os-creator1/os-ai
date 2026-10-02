<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Models\AutomationEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — "A form is submitted".
 *
 * THE FORMS DOMAIN DOES NOT CALL AUTOMATIONS. FormSubmissionService emits exactly
 * one FormSubmissionRecorded per immutable submission, after commit, at FINAL
 * submission only — a questionnaire's intermediate pages are held in
 * `form_sessions` and fire nothing — and a queued listener hands it here.
 *
 * WHAT IS TRUSTED. The event is ids only, so the submission is re-read from
 * `form_submissions` by its id, Business, Location, Form and FormVersion all at
 * once, and its stored occurrence key must equal the event's. A submission that
 * does not match on every one of those, or one of another Business, is no fact at
 * all. The Contact is the submission's OWN link (the Forms domain resolved it,
 * Location-locally, inside the submission transaction) and must still be a
 * contact of that Business; a submission that produced no Contact enrolls nobody,
 * because a journey is always one contact's.
 *
 * THE OCCURRENCE KEY is the Forms domain's own (`form_submission:{uid}`), so one
 * immutable submission is one occurrence: redelivery loses the same unique claim.
 *
 * NOTHING IS WRITTEN to the FormSession or the FormSubmission, and no answer is
 * read from the Form's current version.
 */
class FormSubmittedTriggerSource extends FoundationTriggerSource
{
    protected function assertServes(WorkflowTriggerType $triggerType): void
    {
        if ($triggerType !== WorkflowTriggerType::FormSubmitted) {
            throw new \InvalidArgumentException('The form trigger source serves only the form-submitted trigger.');
        }
    }

    /** @return array{enrolled: int, skipped: array<string, int>} */
    public function handle(FormSubmissionRecorded $event): array
    {
        $result = $this->emptyResult();

        $context = $this->contextFor(
            $event->businessId,
            fn ($query) => $query->where('s.id', $event->submissionId),
        );

        if ($context === null
            || $context->occurrenceKey() !== $event->occurrenceKey
            || $context->locationId !== $event->locationId
            || $context->formId !== $event->formId
            || $context->formVersionId !== $event->formVersionId
            || $context->contactId !== $event->contactId) {
            return $this->skip($result, self::SKIPPED_NO_FACT);
        }

        $formId = $context->formId;

        return $this->enrollListening(
            $result,
            $context->businessId,
            $context->contactId,
            $context->occurrenceKey(),
            // "Any form" when the filter is absent; otherwise exactly that form.
            fn (array $config): bool => ($wanted = self::filterId($config['form_id'] ?? null)) === null || $wanted === $formId,
            null,
            // The immutable submission's own Location, read from its row above.
            $context->locationId,
        );
    }

    /**
     * The trigger facts a journey was started with, read back from its
     * enrollment's occurrence key — the same submission row, so the same answer
     * as when it fired. Null for an enrollment another trigger started, or one
     * whose submission no longer resolves inside its Business.
     */
    public function contextForEnrollment(AutomationEnrollment $enrollment): ?FormSubmittedTriggerContext
    {
        $key = (string) $enrollment->trigger_occurrence_key;

        if ($enrollment->trigger_type !== WorkflowTriggerType::FormSubmitted
            || ! str_starts_with($key, FormSubmittedTriggerContext::OCCURRENCE_PREFIX)) {
            return null;
        }

        $uid = substr($key, strlen(FormSubmittedTriggerContext::OCCURRENCE_PREFIX));

        return $this->contextFor((int) $enrollment->business_id, fn ($query) => $query->where('s.uid', $uid));
    }

    /**
     * One submission inside one Business, as the immutable row recorded it.
     *
     * @param \Closure(\Illuminate\Database\Query\Builder): mixed $locate
     */
    private function contextFor(int $businessId, \Closure $locate): ?FormSubmittedTriggerContext
    {
        $query = DB::table('form_submissions as s')->where('s.business_id', $businessId);
        $locate($query);

        $row = $query->first([
            's.id', 's.uid', 's.business_id', 's.business_location_id', 's.form_id', 's.form_version_id',
            's.contact_id', 's.crm_opportunity_id',
        ]);

        if ($row === null) {
            return null;
        }

        return new FormSubmittedTriggerContext(
            businessId: (int) $row->business_id,
            locationId: (int) $row->business_location_id,
            formId: (int) $row->form_id,
            formVersionId: (int) $row->form_version_id,
            submissionId: (int) $row->id,
            submissionUid: (string) $row->uid,
            contactId: $row->contact_id === null ? null : (int) $row->contact_id,
            opportunityId: $row->crm_opportunity_id === null ? null : (int) $row->crm_opportunity_id,
        );
    }
}
