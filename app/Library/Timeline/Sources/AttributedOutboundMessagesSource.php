<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use App\Models\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Texts this Business sent the person OUTSIDE the conversation's own history —
 * by an automation or a campaign — read from `reports`, the canonical record of
 * every sent message.
 *
 * WHY ONLY THESE, AND WHY NEVER TWICE. An inbox send writes a `reports` row AND
 * a conversation message; reading every outbound report would show each inbox
 * reply twice. A row is read here only when a column proves it did not come
 * from the inbox:
 *
 *   automation_step_run_id   stamped by AutomationSendContext on an Automations
 *                            V2 send (V2-F), which uses a Sender ID originator
 *                            and so never writes a conversation message;
 *   automation_id            a legacy automation send, made through the
 *                            campaign path;
 *   campaign_id              a real campaign — an inbox or quick send runs on an
 *                            unsaved Campaigns instance and records no id.
 *
 * A managed campaign send ALSO gets a conversation message now
 * (ConversationHistoryWriter). One managed operation can be tracked by more
 * than one campaign job — each with its own report — and every one of those
 * reports carries `business_messaging_operation_id`. Here, in SQL and by that
 * identity — never by comparing text or times — only the first report of an
 * operation is read, and not even that one when the operation's conversation
 * message is in the open conversation. So the send is one bubble in every
 * conversation. (The message also `represents` the one report its operation
 * names, which stays harmless.)
 *
 * Every join is pinned to the same Business, so a stamp that no longer resolves
 * inside it names nothing (the message still shows, attributed generically).
 */
final class AttributedOutboundMessagesSource implements TimelineSource
{
    public static function reportKey(int $reportId): string
    {
        return 'sent_report:' . $reportId;
    }

    /** "Sent by automation: Welcome flow", or the kind alone when the name is gone. */
    public static function sentBy(string $kind, ?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? 'Sent by ' . $kind : 'Sent by ' . $kind . ': ' . $name;
    }

    public function recent(TimelineSubject $subject, int $limit): array
    {
        $variants = $subject->numberVariants();

        if ($variants === []) {
            return [];
        }

        $businessId = (int) $subject->business->id;
        $conversationId = $subject->conversation?->id;

        return DB::table('reports as r')
            ->leftJoin('automation_step_runs as asr', function (JoinClause $join) use ($businessId): void {
                $join->on('asr.id', '=', 'r.automation_step_run_id')->where('asr.business_id', $businessId);
            })
            ->leftJoin('automation_enrollments as ae', function (JoinClause $join) use ($businessId): void {
                $join->on('ae.id', '=', 'asr.enrollment_id')->where('ae.business_id', $businessId);
            })
            ->leftJoin('automation_workflows as aw', function (JoinClause $join) use ($businessId): void {
                $join->on('aw.id', '=', 'ae.workflow_id')->where('aw.business_id', $businessId);
            })
            ->leftJoin('automations as a', function (JoinClause $join) use ($businessId): void {
                $join->on('a.id', '=', 'r.automation_id')->where('a.business_id', $businessId);
            })
            ->leftJoin('campaigns as c', function (JoinClause $join) use ($businessId): void {
                $join->on('c.id', '=', 'r.campaign_id')->where('c.business_id', $businessId);
            })
            ->where('r.business_id', $businessId)
            ->whereIn('r.to', $variants)
            ->where('r.direction', Reports::DIRECTION_OUTGOING)
            ->where(function ($marked): void {
                $marked->whereNotNull('r.automation_step_run_id')
                    ->orWhereNotNull('r.automation_id')
                    ->orWhereNotNull('r.campaign_id');
            })
            // One managed send can have several reports (one per campaign job
            // that tracked it), all carrying its operation. Only the first of
            // them ever stands for the send here, by that identity — so the
            // send is one bubble in every conversation, including one that does
            // not hold its message. A report with no operation (every legacy
            // and non-managed report) compares NULL and is always kept.
            ->whereNotExists(function ($earlier) use ($businessId): void {
                $earlier->selectRaw('1')
                    ->from('reports as r2')
                    ->whereColumn('r2.business_messaging_operation_id', 'r.business_messaging_operation_id')
                    ->whereColumn('r2.id', '<', 'r.id')
                    ->where('r2.business_id', $businessId);
            })
            // And where the send's conversation message IS in this
            // conversation, the message is the one bubble.
            ->when($conversationId !== null, function ($query) use ($conversationId): void {
                $query->whereNotExists(function ($carried) use ($conversationId): void {
                    $carried->selectRaw('1')
                        ->from('chat_box_messages as m')
                        ->whereColumn('m.business_messaging_operation_id', 'r.business_messaging_operation_id')
                        ->where('m.box_id', $conversationId);
                });
            })
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->limit($limit)
            ->get([
                'r.id', 'r.message', 'r.media_url', 'r.status', 'r.customer_status', 'r.created_at',
                'r.automation_step_run_id', 'r.automation_id', 'r.campaign_id',
                'asr.id as step_run_id', 'aw.name as workflow_name', 'a.name as automation_name', 'c.campaign_name',
            ])
            ->map(function (object $row): TimelineItem {
                $undelivered = self::isUndelivered($row->customer_status ?? $row->status);

                return new TimelineItem(
                    key: self::reportKey((int) $row->id),
                    kind: TimelineItemKind::Message,
                    at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                    body: trim((string) $row->message) === '' ? null : (string) $row->message,
                    direction: TimelineDirection::Outbound,
                    media: ConversationMessagesSource::mediaList($row->media_url),
                    via: $this->via($row),
                    detail: $undelivered ? 'Not delivered' : null,
                    tone: $undelivered ? TimelineTone::Warning : TimelineTone::Neutral,
                    represents: $row->step_run_id !== null ? [AutomationActivitySource::stepKey((int) $row->step_run_id)] : [],
                    sequence: (int) $row->id,
                );
            })
            ->all();
    }

    private function via(object $row): string
    {
        if ($row->automation_step_run_id !== null) {
            return self::sentBy('automation', $row->workflow_name);
        }

        if ($row->automation_id !== null) {
            return self::sentBy('automation', $row->automation_name);
        }

        return self::sentBy('campaign', $row->campaign_name);
    }

    /**
     * Only a status that says the message did not arrive is worth a line. A
     * pending or sent message is simply shown; "Delivered|provider-id" is the
     * legacy delivered spelling.
     */
    private static function isUndelivered(?string $status): bool
    {
        $status = strtolower(trim((string) $status));

        if ($status === '' || str_starts_with($status, 'delivered')) {
            return false;
        }

        foreach (['fail', 'undeliver', 'reject', 'expire', 'error'] as $word) {
            if (str_contains($status, $word)) {
                return true;
            }
        }

        return false;
    }
}
