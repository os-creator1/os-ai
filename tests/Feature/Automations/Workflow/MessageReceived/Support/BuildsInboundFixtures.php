<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived\Support;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Conversation\InboundMessageReceived;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Reports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures for V2-F: message-received workflows, the canonical message rows the
 * trigger and the subject read, and real step runs to mark automation output
 * with.
 *
 * Every row is written in the exact shape and orientation the real paths write
 * it — `from` is the Business's own number, `to` is the external contact, on
 * inbound and outbound alike — because a fixture in another shape would prove
 * nothing about the production queries.
 */
trait BuildsInboundFixtures
{
    protected const BUSINESS_NUMBER = '14155550100';

    protected function messageSource(): MessageReceivedTriggerSource
    {
        return app(MessageReceivedTriggerSource::class);
    }

    /** A published workflow whose pinned trigger is message_received. */
    protected function messageReceivedWorkflow(Business $business, ?array $steps = null, string $name = 'Reply handler'): AutomationWorkflow
    {
        [$workflow] = $this->publishWorkflow(
            $business,
            $steps ?? [$this->endStep()],
            WorkflowTriggerType::MessageReceived,
            $name . ' ' . uniqid(),
        );

        return $workflow;
    }

    /**
     * An inbound message as the legacy path records it — a real incoming
     * Reports row — and the event the path emits for it.
     */
    protected function legacyInbound(Business $business, string $contactPhone): InboundMessageReceived
    {
        $report = Reports::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => self::BUSINESS_NUMBER,
            'to' => $contactPhone,
            'message' => 'inbound',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_INCOMING,
            'cost' => 0,
            'sms_count' => 1,
        ]);

        return InboundMessageReceived::fromLegacyReport((int) $business->id, $contactPhone, (int) $report->id);
    }

    /** An inbound message as the managed path records it. */
    protected function managedInbound(Business $business, string $contactPhone, int $operationId): InboundMessageReceived
    {
        return InboundMessageReceived::fromManagedOperation((int) $business->id, '+' . $contactPhone, $operationId);
    }

    /**
     * An outbound message the Business sent to a contact. With a step run id it
     * is automation output; without one it is what a person, a campaign or an
     * API call sends.
     */
    protected function outbound(Business $business, string $contactPhone, ?int $stepRunId = null): Reports
    {
        return Reports::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => self::BUSINESS_NUMBER,
            'to' => $contactPhone,
            'message' => 'outbound',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => 0,
            'sms_count' => 1,
            'automation_step_run_id' => $stepRunId,
        ]);
    }

    /** A real step run on an enrollment's current node — what the advancer claims. */
    protected function stepRunOn(AutomationEnrollment $enrollment): int
    {
        $enrollment = $enrollment->fresh();

        return (int) DB::table('automation_step_runs')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $enrollment->current_node_id,
            'node_type' => 'trigger',
            'status' => StepRunStatus::Succeeded->value,
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * A message in the Business's conversation with a contact, at a given time —
     * the canonical inbox history `contact.replied_since_enrollment` reads.
     */
    protected function conversationMessage(Business $business, string $contactPhone, string $direction, Carbon $at): void
    {
        $box = ChatBox::query()->firstOrNew([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => self::BUSINESS_NUMBER,
            'to' => $contactPhone,
        ]);

        if (! $box->exists) {
            $box->uid = (string) Str::uuid();
            $box->save();
        }

        DB::table('chat_box_messages')->insert([
            'box_id' => $box->id,
            'message' => $direction . ' message',
            'sms_type' => 'plain',
            'direction' => $direction,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Move an enrollment's enrolled_at, to put the cooldown or the reply boundary where a test needs it. */
    protected function enrolledAt(AutomationEnrollment $enrollment, Carbon $at): void
    {
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['enrolled_at' => $at]);
    }

    protected function enrollmentsFor(AutomationWorkflow $workflow): int
    {
        return AutomationEnrollment::query()->where('workflow_id', $workflow->getKey())->count();
    }
}
