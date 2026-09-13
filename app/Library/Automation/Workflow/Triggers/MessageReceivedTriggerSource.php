<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Conversation\InboundMessageReceived;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Reports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2-F §9.1 — "a message is received".
 *
 * It runs from a queued listener on InboundMessageReceived, which exists only
 * once an inbound path has proved which Business the message belongs to. It
 * decides which published workflows are listening and whether each may take
 * this contact; it never creates an enrollment itself — EnrollmentService is the
 * only door (§7.5), and that door still pins the version, composes the key and
 * refuses duplicates.
 *
 * THE FOUR LOCKED RULES (D6), in the order they are applied:
 *
 *   1. EXACTLY ONE SUBSCRIBED CONTACT. Phone is unique per group, not per
 *      Business, so one number can be several contacts. Zero or several
 *      subscribed matches is `ambiguous_contact`, and nobody is enrolled —
 *      picking one would be a guess about which person wrote.
 *
 *   2. NEVER ITS OWN OUTPUT (T-WF-25). The message the contact is answering is
 *      the most recent one this Business sent to that number, by insert order.
 *      If that message carries `reports.automation_step_run_id` and the step run
 *      belongs to THIS workflow, the workflow does not trigger: it would be
 *      re-triggering off its own send. A message a person sent from the inbox,
 *      a campaign or an API call carries no mark and changes nothing. This is
 *      read from the durable mark, never from message text and never from how
 *      quickly the reply came.
 *
 *   3. CAUSATION DEPTH. If that preceding message was produced by a DIFFERENT
 *      workflow, this enrollment is one link further down a chain of automations
 *      answering automations, and inherits that journey's depth plus one.
 *      EnrollmentService refuses anything past WorkflowLimits::MAX_CAUSATION_DEPTH,
 *      so two workflows cannot ping-pong indefinitely through a contact.
 *
 *   4. THE COOLDOWN. A contact enrolled into a workflow by a received message
 *      cannot be enrolled into that SAME workflow by another received message for
 *      WorkflowLimits::MESSAGE_RECEIVED_COOLDOWN_HOURS. That is what stops a
 *      contact-side auto-responder from pulling a journey round and round. It is
 *      checked and the enrollment made while holding a lock on the contact row,
 *      so two messages processed at the same instant cannot both pass it.
 *
 * THE OCCURRENCE KEY is the message's own identity (`report:{id}` or
 * `operation:{id}`), so a redelivered event composes the same key and loses the
 * same unique claim — duplicate delivery enrolls once.
 */
class MessageReceivedTriggerSource implements TriggerSource
{
    public const SKIPPED_NO_BUSINESS = 'no_business';
    public const SKIPPED_AMBIGUOUS_CONTACT = 'ambiguous_contact';
    public const SKIPPED_SELF_REPLY = 'self_reply';
    public const SKIPPED_CAUSATION_DEPTH = 'causation_depth';
    public const SKIPPED_COOLDOWN = 'cooldown';
    public const SKIPPED_NOT_ENROLLED = 'not_enrolled';

    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function triggerType(): WorkflowTriggerType
    {
        return WorkflowTriggerType::MessageReceived;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The one phone normalization inbound attribution already uses, so a number
     * written by an inbound path, an outbound send and a contact row compare as
     * the same string. Deliberately not a second, "smarter" scheme.
     */
    public static function normalizePhone(string $raw): string
    {
        return str_replace(['(', ')', '+', '-', ' '], '', trim($raw));
    }

    /**
     * Handle one authoritatively attributed inbound message.
     *
     * @return array{contact_id: ?int, enrolled: int, skipped: array<string, int>}
     *         what happened, so the listener can record skips without logging
     *         the message or the number
     */
    public function handleInboundMessage(InboundMessageReceived $event): array
    {
        $result = ['contact_id' => null, 'enrolled' => 0, 'skipped' => []];

        $business = Business::query()->find($event->businessId);

        if ($business === null) {
            return $this->skip($result, self::SKIPPED_NO_BUSINESS);
        }

        $contact = $this->theOneSubscribedContact((int) $business->id, self::normalizePhone($event->senderPhone));

        if ($contact === null) {
            return $this->skip($result, self::SKIPPED_AMBIGUOUS_CONTACT);
        }

        $result['contact_id'] = (int) $contact->id;

        $workflows = $this->listeningWorkflows((int) $business->id);

        if ($workflows === []) {
            return $result;
        }

        // One read for the whole fan-out: what was this contact answering?
        [$producerWorkflowId, $producerDepth] = $this->precedingAutomationProducer(
            (int) $business->id,
            self::normalizePhone((string) $contact->phone),
            $event->inboundReportId,
        );

        foreach ($workflows as $workflow) {
            if ($producerWorkflowId !== null && $producerWorkflowId === (int) $workflow->getKey()) {
                $result = $this->skip($result, self::SKIPPED_SELF_REPLY);

                continue;
            }

            $depth = $producerWorkflowId === null ? 0 : $producerDepth + 1;

            if ($depth > WorkflowLimits::MAX_CAUSATION_DEPTH) {
                $result = $this->skip($result, self::SKIPPED_CAUSATION_DEPTH);

                continue;
            }

            [$enrollment, $reason] = $this->enrollOutsideCooldown($workflow, $contact, $event->occurrenceKey, $depth);

            if ($enrollment === null) {
                $result = $this->skip($result, $reason);

                continue;
            }

            $result['enrolled']++;

            // After the lock's transaction has committed, so the worker that
            // picks this up reads a real row.
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $result;
    }

    /**
     * Exactly one subscribed contact with this number in this Business, or null.
     * Limited to two rows: whether there is one or "more than one" is all this
     * needs to know.
     */
    private function theOneSubscribedContact(int $businessId, string $phone): ?Contacts
    {
        if ($phone === '') {
            return null;
        }

        $matches = Contacts::query()
            ->where('business_id', $businessId)
            ->where('phone', $phone)
            ->where('status', Contacts::STATUS_SUBSCRIBE)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The published workflows of one Business whose pinned trigger is
     * message_received, bounded by the Business's own published-workflow limit.
     *
     * @return list<AutomationWorkflow>
     */
    private function listeningWorkflows(int $businessId): array
    {
        return AutomationWorkflow::query()
            ->select('automation_workflows.*')
            ->join('automation_workflow_versions as v', 'v.id', '=', 'automation_workflows.published_version_id')
            ->where('automation_workflows.business_id', $businessId)
            ->where('automation_workflows.status', WorkflowStatus::Published->value)
            ->where('v.trigger_type', WorkflowTriggerType::MessageReceived->value)
            ->orderBy('automation_workflows.id')
            ->limit(WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS)
            ->get()
            ->all();
    }

    /**
     * Which workflow, if any, produced the message this contact is answering —
     * and at what causation depth.
     *
     * "The message being answered" is the most recent outbound message this
     * Business sent to that number. For the legacy path that is strictly before
     * the inbound Reports row itself, by id; the managed path writes no inbound
     * report, so it is the most recent one when the work runs. Order is the row
     * id, never a timestamp: ids cannot tie, and nothing here reasons about time.
     *
     * Served by `reports_business_to_direction_index`, so this is one step into
     * an index rather than a walk over everything the Business ever sent.
     *
     * @return array{0: ?int, 1: int} [producing workflow id or null, its causation depth]
     */
    private function precedingAutomationProducer(int $businessId, string $phone, ?int $inboundReportId): array
    {
        if ($phone === '') {
            return [null, 0];
        }

        $preceding = Reports::query()
            ->where('business_id', $businessId)
            ->where('to', $phone)
            ->where('direction', Reports::DIRECTION_OUTGOING)
            ->when($inboundReportId !== null, fn ($query) => $query->where('id', '<', $inboundReportId))
            ->orderByDesc('id')
            ->first(['id', 'automation_step_run_id']);

        if ($preceding === null || $preceding->automation_step_run_id === null) {
            // Nothing sent yet, or the last message came from a person, a
            // campaign or an API call. Not automation output.
            return [null, 0];
        }

        $producer = AutomationStepRun::query()
            ->join('automation_enrollments as e', 'e.id', '=', 'automation_step_runs.enrollment_id')
            ->where('automation_step_runs.id', (int) $preceding->automation_step_run_id)
            ->where('e.business_id', $businessId)
            ->first(['e.workflow_id', 'e.causation_depth']);

        if ($producer === null) {
            // A mark that no longer resolves inside this Business names no
            // workflow here, and is treated as no mark at all.
            return [null, 0];
        }

        return [(int) $producer->workflow_id, (int) $producer->causation_depth];
    }

    /**
     * Check the cooldown and enroll, as one serialized decision per contact.
     *
     * The lock on the contact row is what makes the cooldown a rule rather than
     * a hope: two different messages from the same person, processed at the same
     * moment by two workers, would otherwise both read "no recent enrollment" and
     * both enroll. With the lock, the second waits, then sees the first.
     *
     * @return array{0: ?AutomationEnrollment, 1: string} the enrollment, or null with the reason
     */
    private function enrollOutsideCooldown(
        AutomationWorkflow $workflow,
        Contacts $contact,
        string $occurrenceKey,
        int $depth,
    ): array {
        return DB::transaction(function () use ($workflow, $contact, $occurrenceKey, $depth): array {
            Contacts::query()->whereKey($contact->getKey())->lockForUpdate()->first();

            if ($this->inCooldown($workflow, $contact)) {
                return [null, self::SKIPPED_COOLDOWN];
            }

            $enrollment = $this->enrollments->enroll($workflow, $contact, $occurrenceKey, $depth);

            // Null here is EnrollmentService's own refusal: a redelivered
            // message losing its unique key, a contact still part-way through,
            // a workflow paused since the message arrived. All correct, all
            // silent.
            return [$enrollment, $enrollment === null ? self::SKIPPED_NOT_ENROLLED : ''];
        });
    }

    /**
     * Whether a received message already enrolled this contact into this
     * workflow inside the cooldown window. Only message-received enrollments
     * count: being enrolled by hand or by a date does not start a cooldown.
     */
    private function inCooldown(AutomationWorkflow $workflow, Contacts $contact): bool
    {
        return AutomationEnrollment::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('contact_id', $contact->getKey())
            ->where('trigger_type', WorkflowTriggerType::MessageReceived->value)
            ->where('enrolled_at', '>', Carbon::now()->subHours(WorkflowLimits::MESSAGE_RECEIVED_COOLDOWN_HOURS))
            ->exists();
    }

    /**
     * @param array{contact_id: ?int, enrolled: int, skipped: array<string, int>} $result
     * @return array{contact_id: ?int, enrolled: int, skipped: array<string, int>}
     */
    private function skip(array $result, string $reason): array
    {
        $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;

        return $result;
    }
}
