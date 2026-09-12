<?php

namespace App\Jobs\Automation\Workflow;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Jobs\Base;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;

/**
 * Automations V2 §8.2 — the queued door to the two contact-shaped triggers.
 *
 * Two named constructors, one job, because both cases are the same shape of
 * work (take an id, re-read it, ask a trigger source to enroll) and both must
 * obey the same rules:
 *
 *   forContactCreated()    dispatched after commit beside B4's own dispatch; the
 *                          source fans out over every workflow listening for
 *                          that creation source.
 *   forManualEnrollment()  one workflow, one contact, one deliberate request.
 *
 * IDS, NEVER MODELS. Everything is re-read at execution time, so a contact
 * deleted between dispatch and execution is a silent no-op, an archived
 * workflow enrolls nobody, and a job sitting in the queue through a republish
 * pins whatever is published when it actually runs. Serialized models would
 * carry a stale snapshot of exactly the state these rules depend on.
 *
 * NO ACTOR. There is no Auth, session or request state here, and no permission
 * decision: the HTTP layer (V2-E) authorizes the request that dispatches a
 * manual enrollment, and this job re-proves only what data allows — the
 * workflow and the contact belonging to the same Business. A queued job that
 * asked "who is logged in?" would be asking about whoever happens to be running
 * the worker.
 *
 * `$tries = 1` from Base is deliberate, and duplicate delivery is harmless
 * anyway: both copies compose the same enrollment key and the second loses the
 * unique claim (§7.5).
 */
class EnrollWorkflowContact extends Base
{
    private function __construct(
        private readonly int $contactId,
        private readonly ?int $workflowId,
        private readonly ?string $creationSource,
        private readonly ?string $requestUid,
    ) {
        $this->onQueue('automation');
    }

    /**
     * A Contact was created. Dispatch this AFTER COMMIT — the job re-reads the
     * row, and an uncommitted one does not exist yet.
     */
    public static function forContactCreated(int $contactId, ContactCreationSource $source): self
    {
        return new self($contactId, null, $source->value, null);
    }

    /**
     * Someone enrolled a contact by hand. `$requestUid` is the server-derived
     * identity of that one request, and becomes the occurrence key, so a
     * redelivered job cannot enroll twice.
     */
    public static function forManualEnrollment(int $workflowId, int $contactId, string $requestUid): self
    {
        return new self($contactId, $workflowId, null, $requestUid);
    }

    public function handle(
        ContactCreatedTriggerSource $contactCreated,
        ManualEnrollmentTriggerSource $manual,
    ): void {
        $contact = Contacts::query()->find($this->contactId);

        if ($contact === null || $contact->business_id === null) {
            // Deleted, or never Business-scoped. Either way there is no
            // Business workflow this contact could legitimately enter.
            return;
        }

        if ($this->workflowId === null) {
            $source = ContactCreationSource::tryFrom((string) $this->creationSource);

            if ($source === null) {
                // A source outside the closed vocabulary cannot be honoured:
                // it would have to be treated as "any", and a filtered
                // workflow would fire on a creation nobody vouched for.
                return;
            }

            $contactCreated->handleContactCreated($contact, $source);

            return;
        }

        $workflow = AutomationWorkflow::query()->find($this->workflowId);

        if ($workflow === null) {
            return;
        }

        // Re-proved here even though the trigger source and
        // EnrollmentService both check it: this is the point where a stale
        // queued job could otherwise carry ids that no longer belong together.
        if ($workflow->business_id === null || (int) $workflow->business_id !== (int) $contact->business_id) {
            return;
        }

        $manual->enrollByHand($workflow, $contact, (string) $this->requestUid);
    }
}
