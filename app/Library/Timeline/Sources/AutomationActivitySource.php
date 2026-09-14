<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What automations did with this person that a person would want to know —
 * never the machinery.
 *
 * Automations V2 (`automation_enrollments`, `automation_step_runs`):
 *   - joining an automation, and how the journey ended;
 *   - the outcome of a step that acts on the person or the team: sending a
 *     text, updating contact details, notifying the team.
 * Wait, If/Else, End and the trigger itself are internal and never appear.
 *
 * B4 Business Automations (`automation_executions`): the outcome of each run.
 *
 * CONTACT-KEYED. Every one of these rows names a contact, so nothing is shown
 * unless the conversation resolves to exactly one Contact of this Business.
 *
 * NEVER TWICE. A V2 text that was stamped on its `reports` row shows as that
 * message (AttributedOutboundMessagesSource represents the step), so its card
 * is dropped. A failed or skipped step always ends the journey, and its card
 * already says why, so it represents that journey's ending too.
 *
 * Stored reason codes are translated into plain words when they are known and
 * left out when they are not: a raw code is never shown.
 */
final class AutomationActivitySource implements TimelineSource
{
    private const OUTCOME_STEP_TYPES = [
        WorkflowNodeType::SendSms->value,
        WorkflowNodeType::UpdateContactField->value,
        WorkflowNodeType::InternalNotification->value,
    ];

    /** Known reason codes → what a person reads. Prefix matched: some codes carry a suffix. */
    private const REASONS = [
        'contact_unsubscribed' => 'This person is unsubscribed',
        'contact_phone_invalid' => 'The phone number is not valid',
        'no_business_sending_path' => 'No sending number is set up',
        'channel_unavailable' => 'No sending number is set up',
        'sender_rejected' => 'The sender was not accepted',
        'country_not_covered' => 'This country is not covered by the plan',
        'no_active_subscription' => 'There is no active plan',
        'plan_inactive' => 'There is no active plan',
        'send_config_invalid' => 'The automation message is not set up',
        'mms_media_missing' => 'The automation message is not set up',
        'send_failed' => 'The message could not be sent',
        'send_exception' => 'The message could not be sent',
    ];

    public static function stepKey(int $stepRunId): string
    {
        return 'automation_step:' . $stepRunId;
    }

    public static function enrollmentEndKey(int $enrollmentId): string
    {
        return 'automation_enrollment_end:' . $enrollmentId;
    }

    public function recent(TimelineSubject $subject, int $limit): array
    {
        if ($subject->contact === null || (int) $subject->contact->business_id !== (int) $subject->business->id) {
            return [];
        }

        $businessId = (int) $subject->business->id;
        $contactId = (int) $subject->contact->id;

        // Three ledgers, one bound: each read takes its newest $limit rows, so
        // the newest $limit items across all three are always among them.
        $items = array_merge(
            $this->enrollments($businessId, $contactId, $limit),
            $this->steps($businessId, $contactId, $limit),
            $this->executions($businessId, $contactId, $limit),
        );

        usort($items, static fn (TimelineItem $a, TimelineItem $b): int => [$b->at->getTimestamp(), $b->sequence] <=> [$a->at->getTimestamp(), $a->sequence]);

        return array_slice($items, 0, $limit);
    }

    /** @return list<TimelineItem> */
    private function enrollments(int $businessId, int $contactId, int $limit): array
    {
        $items = [];

        $rows = DB::table('automation_enrollments as e')
            ->join('automation_workflows as w', function (JoinClause $join) use ($businessId): void {
                $join->on('w.id', '=', 'e.workflow_id')->where('w.business_id', $businessId);
            })
            ->where('e.business_id', $businessId)
            ->where('e.contact_id', $contactId)
            // By the journey's latest event, so a long journey that only just
            // ended is not pushed out by newer enrollments that have not.
            ->orderByRaw('COALESCE(e.completed_at, e.enrolled_at) DESC')
            ->orderByDesc('e.id')
            ->limit($limit)
            ->get(['e.id', 'e.status', 'e.enrolled_at', 'e.completed_at', 'w.name']);

        foreach ($rows as $row) {
            $name = self::name($row->name);

            $items[] = new TimelineItem(
                key: 'automation_enrollment:' . $row->id,
                kind: TimelineItemKind::Activity,
                at: self::time($row->enrolled_at),
                title: 'Added to automation ' . $name,
                icon: 'zap',
                sequence: (int) $row->id,
            );

            if ($row->completed_at === null) {
                continue;
            }

            $ended = match ($row->status) {
                EnrollmentStatus::Completed->value => ['Finished automation ' . $name, TimelineTone::Neutral],
                EnrollmentStatus::Exited->value => ['Left automation ' . $name, TimelineTone::Neutral],
                EnrollmentStatus::Cancelled->value => ['Removed from automation ' . $name, TimelineTone::Neutral],
                EnrollmentStatus::Failed->value => ['Automation ' . $name . ' stopped', TimelineTone::Warning],
                default => null,
            };

            if ($ended !== null) {
                $items[] = new TimelineItem(
                    key: self::enrollmentEndKey((int) $row->id),
                    kind: TimelineItemKind::Activity,
                    at: self::time($row->completed_at),
                    title: $ended[0],
                    tone: $ended[1],
                    icon: 'zap',
                    sequence: (int) $row->id,
                );
            }
        }

        return $items;
    }

    /** @return list<TimelineItem> */
    private function steps(int $businessId, int $contactId, int $limit): array
    {
        return DB::table('automation_step_runs as s')
            ->join('automation_enrollments as e', function (JoinClause $join) use ($businessId): void {
                $join->on('e.id', '=', 's.enrollment_id')->where('e.business_id', $businessId);
            })
            ->join('automation_workflows as w', function (JoinClause $join) use ($businessId): void {
                $join->on('w.id', '=', 'e.workflow_id')->where('w.business_id', $businessId);
            })
            ->where('s.business_id', $businessId)
            ->where('e.contact_id', $contactId)
            ->whereIn('s.node_type', self::OUTCOME_STEP_TYPES)
            ->whereIn('s.status', [StepRunStatus::Succeeded->value, StepRunStatus::Failed->value, StepRunStatus::Skipped->value])
            ->orderByDesc('s.id')
            ->limit($limit)
            ->get(['s.id', 's.enrollment_id', 's.node_type', 's.status', 's.completed_at', 's.updated_at', 's.safe_error_summary', 'w.name'])
            ->map(function (object $row): TimelineItem {
                $succeeded = $row->status === StepRunStatus::Succeeded->value;

                return new TimelineItem(
                    key: self::stepKey((int) $row->id),
                    kind: TimelineItemKind::Activity,
                    at: self::time($row->completed_at ?? $row->updated_at),
                    title: self::outcomeSentence(self::name($row->name), self::stepAction($row->node_type), $succeeded),
                    detail: $succeeded ? null : self::reason($row->safe_error_summary),
                    tone: $succeeded ? TimelineTone::Neutral : TimelineTone::Warning,
                    icon: 'zap',
                    // A failed or skipped step always ends the journey; its card is the ending.
                    represents: $succeeded ? [] : [self::enrollmentEndKey((int) $row->enrollment_id)],
                    sequence: (int) $row->id,
                );
            })
            ->all();
    }

    /** @return list<TimelineItem> */
    private function executions(int $businessId, int $contactId, int $limit): array
    {
        return DB::table('automation_executions as x')
            ->join('automations as a', function (JoinClause $join) use ($businessId): void {
                $join->on('a.id', '=', 'x.automation_id')->where('a.business_id', $businessId);
            })
            ->where('x.business_id', $businessId)
            ->where('x.contact_id', $contactId)
            ->whereIn('x.status', [AutomationExecutionStatus::Succeeded->value, AutomationExecutionStatus::Failed->value, AutomationExecutionStatus::Skipped->value])
            ->orderByDesc('x.id')
            ->limit($limit)
            ->get(['x.id', 'x.status', 'x.completed_at', 'x.updated_at', 'x.safe_error_summary', 'a.name', 'a.action_type'])
            ->map(function (object $row): TimelineItem {
                $succeeded = $row->status === AutomationExecutionStatus::Succeeded->value;

                return new TimelineItem(
                    key: 'automation_execution:' . $row->id,
                    kind: TimelineItemKind::Activity,
                    at: self::time($row->completed_at ?? $row->updated_at),
                    title: self::outcomeSentence(self::name($row->name), $row->action_type === 'update_contact_field' ? 'update' : 'text', $succeeded),
                    detail: $succeeded ? null : self::reason($row->safe_error_summary),
                    tone: $succeeded ? TimelineTone::Neutral : TimelineTone::Warning,
                    icon: 'zap',
                    sequence: (int) $row->id,
                );
            })
            ->all();
    }

    private static function stepAction(string $nodeType): string
    {
        return match ($nodeType) {
            WorkflowNodeType::UpdateContactField->value => 'update',
            WorkflowNodeType::InternalNotification->value => 'notify',
            default => 'text',
        };
    }

    private static function outcomeSentence(string $name, string $action, bool $succeeded): string
    {
        return match ([$action, $succeeded]) {
            ['text', true] => "Automation {$name} sent a text",
            ['text', false] => "Automation {$name} did not send a text",
            ['update', true] => "Automation {$name} updated contact details",
            ['update', false] => "Automation {$name} did not update contact details",
            ['notify', true] => "Automation {$name} notified your team",
            default => "Automation {$name} did not notify your team",
        };
    }

    private static function reason(?string $code): ?string
    {
        $code = trim((string) $code);

        foreach (self::REASONS as $prefix => $words) {
            if ($code !== '' && str_starts_with($code, $prefix)) {
                return $words;
            }
        }

        return null;
    }

    private static function name(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '(unnamed)' : '“' . $name . '”';
    }

    private static function time(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, config('app.timezone'));
    }
}
