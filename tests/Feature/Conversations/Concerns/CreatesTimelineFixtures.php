<?php

namespace Tests\Feature\Conversations\Concerns;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\Reports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversations contact activity timeline — persisted rows exactly as their
 * owning producers write them: a conversation and its messages, sent reports
 * with or without an automation or campaign mark, block-list entries, named
 * contacts, and Automations V2 journeys on a really published workflow (so
 * every step run points at a node the compiler produced).
 *
 * Needs BuildsWorkflows and BuildsActionWorkflows alongside it.
 */
trait CreatesTimelineFixtures
{
    protected const BUSINESS_NUMBER = '18005550100';

    /**
     * A contact of this Business with a name (and optionally an email) in its
     * group's custom fields — the only place names are stored.
     */
    protected function namedContact(Business $business, string $phone, string $first, string $last, ?string $email = null, string $groupName = 'Clients'): Contacts
    {
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $groupName,
            'status' => true,
        ]);

        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        foreach (array_filter(['FIRST_NAME' => $first, 'LAST_NAME' => $last, 'EMAIL' => $email]) as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );

            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        return $contact->fresh();
    }

    protected function conversationWith(Business $business, string $phone): ChatBox
    {
        $box = new ChatBox([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => self::BUSINESS_NUMBER,
            'to' => $phone,
            'reply_by_customer' => true,
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box->fresh();
    }

    protected function message(ChatBox $box, string $direction, ?string $text, Carbon $at, ?string $mediaUrl = null): int
    {
        return DB::table('chat_box_messages')->insertGetId([
            'box_id' => $box->id,
            'message' => $text,
            'media_url' => $mediaUrl,
            'sms_type' => 'plain',
            'direction' => $direction,
            'send_by' => $direction === Reports::DIRECTION_INCOMING ? 'to' : 'from',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * An outbound `reports` row. With no mark it is what an inbox send writes
     * beside its conversation message; with one it is automation or campaign
     * output.
     *
     * @param  array{automation_step_run_id?: int, automation_id?: int, campaign_id?: int, status?: string}  $marks
     */
    protected function sentReport(Business $business, string $phone, string $text, Carbon $at, array $marks = []): int
    {
        return DB::table('reports')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => self::BUSINESS_NUMBER,
            'to' => $phone,
            'message' => $text,
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => '1',
            'created_at' => $at,
            'updated_at' => $at,
        ], $marks));
    }

    /** A managed outbound operation row, optionally naming its report. */
    protected function managedOperation(Business $business, Carbon $at, ?int $reportId = null): int
    {
        return DB::table('business_messaging_operations')->insertGetId([
            'business_id' => $business->id,
            'transport_mode' => 'managed',
            'provider' => 'telnyx',
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => (string) Str::uuid(),
            'status' => 'accepted',
            'report_id' => $reportId,
            'occurred_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * A managed outbound message as ConversationHistoryWriter records it: the
     * managed operation row it is the history of (linked to a campaign report
     * when there is one), and the message carrying that operation, the sending
     * automation step and its source.
     */
    protected function managedMessage(Business $business, ChatBox $box, string $text, Carbon $at, ?int $reportId = null, ?int $stepRunId = null, ?string $source = null): int
    {
        $operationId = $this->managedOperation($business, $at, $reportId);

        return DB::table('chat_box_messages')->insertGetId([
            'box_id' => $box->id,
            'message' => $text,
            'sms_type' => 'plain',
            'direction' => Reports::DIRECTION_OUTGOING,
            'send_by' => 'from',
            'business_messaging_operation_id' => $operationId,
            'automation_step_run_id' => $stepRunId,
            'source' => $source,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    protected function campaignNamed(Business $business, string $name): Campaigns
    {
        return Campaigns::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'campaign_name' => $name,
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ]);
    }

    protected function blockListEntry(Business $business, string $number, string $reason, Carbon $at): int
    {
        return DB::table('blacklists')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'number' => $number,
            'reason' => $reason,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * A contact's journey through a published Automations V2 workflow, with
     * `$steps` compiled nodes to hang step runs on.
     *
     * @return array{enrollment: int, nodes: list<int>}
     */
    protected function journey(Business $business, Contacts $contact, string $workflowName, EnrollmentStatus $status, Carbon $enrolledAt, ?Carbon $completedAt = null, int $steps = 4): array
    {
        $body = [];

        for ($i = 0; $i < $steps; $i++) {
            $body[] = $this->smsStep('Step ' . $i);
        }

        [$workflow, $version] = $this->publishWorkflow($business, [...$body, $this->endStep()], name: $workflowName);

        $nodes = DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)
            ->where('node_type', 'send_sms')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $enrollment = DB::table('automation_enrollments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workflow_id' => $workflow->id,
            'version_id' => $version->id,
            'contact_id' => $contact->id,
            'status' => $status->value,
            'trigger_type' => 'contact_created',
            'trigger_occurrence_key' => (string) Str::uuid(),
            'enrollment_key' => (string) Str::uuid(),
            'enrolled_at' => $enrolledAt,
            'completed_at' => $completedAt,
            'created_at' => $enrolledAt,
            'updated_at' => $completedAt ?? $enrolledAt,
        ]);

        return ['enrollment' => $enrollment, 'nodes' => $nodes];
    }

    protected function stepRun(Business $business, int $enrollmentId, int $nodeId, string $nodeType, StepRunStatus $status, Carbon $at, ?string $error = null): int
    {
        return DB::table('automation_step_runs')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'enrollment_id' => $enrollmentId,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'status' => $status->value,
            'started_at' => $at,
            'completed_at' => $status->isTerminal() ? $at : null,
            'safe_error_summary' => $error,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
