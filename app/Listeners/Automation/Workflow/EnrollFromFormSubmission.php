<?php

namespace App\Listeners\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Automation\Workflow\Triggers\FormSubmittedTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Automations V2 — hands a recorded form submission to its trigger source. The
 * Forms domain emits one FormSubmissionRecorded per immutable final submission;
 * Automations consumes it; neither calls the other.
 *
 * Queued on `automation`, after the submission's own commit. One try: a
 * redelivered event composes the same occurrence key and enrolls nobody twice.
 */
class EnrollFromFormSubmission implements ShouldQueue
{
    public string $queue = 'automation';

    public int $tries = 1;

    public function __construct(private readonly TriggerSourceRegistry $sources)
    {
    }

    public function handle(FormSubmissionRecorded $event): void
    {
        // One submission, two triggers: "a form is submitted" takes every form, and
        // "a questionnaire is submitted" only a form of two or more pages.
        foreach ([WorkflowTriggerType::FormSubmitted, WorkflowTriggerType::QuestionnaireSubmitted] as $type) {
            $source = $this->sources->for($type);

            if (! $source instanceof FormSubmittedTriggerSource) {
                continue;
            }

            $result = $source->handle($event);

            // A one-page form is simply not a questionnaire; that is not worth a log line.
            if ($result['skipped'] === [] || ($type === WorkflowTriggerType::QuestionnaireSubmitted && $result['enrolled'] === 0 && array_keys($result['skipped']) === [FormSubmittedTriggerSource::SKIPPED_NO_FACT])) {
                continue;
            }

            Log::info('automation.form_submission.skipped', [
                'business_id' => $event->businessId,
                'trigger_type' => $type->value,
                'occurrence_key' => $event->occurrenceKey,
                'enrolled' => $result['enrolled'],
                'skipped' => $result['skipped'],
            ]);
        }
    }
}
