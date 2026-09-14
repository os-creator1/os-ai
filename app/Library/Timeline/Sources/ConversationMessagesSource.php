<?php

namespace App\Library\Timeline\Sources;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Timeline\TimelineDirection;
use App\Enums\Timeline\TimelineItemKind;
use App\Enums\Timeline\TimelineTone;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Conversations\ConversationSendFailureReason;
use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;
use App\Models\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The conversation's own messages — `chat_box_messages`, the canonical
 * conversation history: every inbound path (legacy webhooks, managed inbound
 * since #285), every two-way inbox send, and every accepted managed send
 * (ConversationHistoryWriter).
 *
 * Tenancy is the conversation's: the ChatBox was already resolved by uid AND
 * business_id before a timeline is built, and its messages carry no tenancy of
 * their own (Slice 2B §3).
 *
 * WHO SENT IT, IN THE SAME QUERY. A managed send's history row carries its
 * provenance, and one set of joins — each pinned to this Business — reads it:
 *
 *   automation_step_run_id   → the V2 workflow         "Sent by automation: …"
 *   operation → report       → the campaign            "Sent by campaign: …"
 *   source = conversations   → a person in the inbox   "Sent manually"
 *
 * and the managed operation's own delivery status ("Not delivered"). No query
 * per message. The row `represents` the automation step and the campaign report
 * it stands for, so neither also appears as a card or a second bubble.
 */
final class ConversationMessagesSource implements TimelineSource
{
    public function recent(TimelineSubject $subject, int $limit): array
    {
        $conversation = $subject->conversation;

        if ($conversation === null || (int) $conversation->business_id !== (int) $subject->business->id) {
            return [];
        }

        $businessId = (int) $subject->business->id;

        return DB::table('chat_box_messages as m')
            ->leftJoin('business_messaging_operations as bmo', function (JoinClause $join) use ($businessId): void {
                $join->on('bmo.id', '=', 'm.business_messaging_operation_id')->where('bmo.business_id', $businessId);
            })
            ->leftJoin('reports as r', function (JoinClause $join) use ($businessId): void {
                $join->on('r.id', '=', 'bmo.report_id')->where('r.business_id', $businessId);
            })
            ->leftJoin('campaigns as c', function (JoinClause $join) use ($businessId): void {
                $join->on('c.id', '=', 'r.campaign_id')->where('c.business_id', $businessId);
            })
            ->leftJoin('automation_step_runs as asr', function (JoinClause $join) use ($businessId): void {
                $join->on('asr.id', '=', 'm.automation_step_run_id')->where('asr.business_id', $businessId);
            })
            ->leftJoin('automation_enrollments as ae', function (JoinClause $join) use ($businessId): void {
                $join->on('ae.id', '=', 'asr.enrollment_id')->where('ae.business_id', $businessId);
            })
            ->leftJoin('automation_workflows as aw', function (JoinClause $join) use ($businessId): void {
                $join->on('aw.id', '=', 'ae.workflow_id')->where('aw.business_id', $businessId);
            })
            ->where('m.box_id', $conversation->id)
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->limit($limit)
            ->get([
                'm.id', 'm.message', 'm.media_url', 'm.direction', 'm.send_by', 'm.created_at',
                'm.automation_step_run_id', 'm.source',
                'm.send_uid', 'm.send_status', 'm.send_failure_reason',
                'bmo.status as operation_status', 'r.id as report_id', 'r.campaign_id',
                'asr.id as step_run_id', 'aw.name as workflow_name', 'c.campaign_name',
            ])
            ->map(function (object $row): TimelineItem {
                $direction = $this->direction($row);
                $outbound = $direction === TimelineDirection::Outbound;

                $represents = [];

                if ($row->step_run_id !== null) {
                    $represents[] = AutomationActivitySource::stepKey((int) $row->step_run_id);
                }

                if ($row->report_id !== null) {
                    $represents[] = AttributedOutboundMessagesSource::reportKey((int) $row->report_id);
                }

                [$detail, $tone, $retrySendUid, $retryable] = $this->sendState($row, $outbound);

                return new TimelineItem(
                    key: 'conversation_message:' . $row->id,
                    kind: TimelineItemKind::Message,
                    at: CarbonImmutable::parse((string) $row->created_at, config('app.timezone')),
                    body: trim((string) $row->message) === '' ? null : (string) $row->message,
                    direction: $direction,
                    media: self::mediaList($row->media_url),
                    via: $outbound ? $this->via($row) : null,
                    detail: $detail,
                    tone: $tone,
                    represents: $represents,
                    sequence: (int) $row->id,
                    retrySendUid: $retrySendUid,
                    retryable: $retryable,
                );
            })
            ->all();
    }

    /**
     * Conversations failed-send/retry (item 2/4/5) — the ONE customer-visible
     * bubble's current state.
     *
     * `send_status` is authoritative whenever the row carries one: it is set
     * only on a Conversations manual send this feature tracks, and it is
     * always kept current across however many attempts a retry takes,
     * including a later delivery-status failure (item 5) — so a tracked row
     * never also falls into the older, coarser `operation_status` check
     * below. Every other row — every legacy send, every automation and
     * campaign send, every quick send outside Conversations — carries no
     * `send_status` at all and keeps EXACTLY the "Not delivered" behaviour
     * this already had, unchanged.
     *
     * @return array{0: ?string, 1: TimelineTone, 2: ?string, 3: bool}
     */
    private function sendState(object $row, bool $outbound): array
    {
        if (! $outbound) {
            return [null, TimelineTone::Neutral, null, false];
        }

        if ($row->send_status !== null) {
            return match ($row->send_status) {
                'sending' => [__('locale.conversations.sending_label'), TimelineTone::Neutral, null, false],
                'failed', 'delivery_failed' => [
                    $this->failureDetail($row),
                    TimelineTone::Warning,
                    $row->send_uid,
                    true,
                ],
                // Correction round 4, item 1 — visible, truthfully labelled,
                // and deliberately NEVER retryable: the provider's own
                // acceptance was never conclusively disproven, so offering
                // Retry here could mint a second, genuinely new send for a
                // message that may already have gone out.
                'ambiguous' => [
                    __('locale.conversations.ambiguous_label') . ' — ' . ConversationSendFailureReason::Ambiguous->customerMessage(),
                    TimelineTone::Warning,
                    null,
                    false,
                ],
                default => [null, TimelineTone::Neutral, null, false],
            };
        }

        $undelivered = $row->operation_status === MessagingOperationStatus::Failed->value;

        return [$undelivered ? 'Not delivered' : null, $undelivered ? TimelineTone::Warning : TimelineTone::Neutral, null, false];
    }

    private function failureDetail(object $row): string
    {
        $reason = $row->send_failure_reason !== null
            ? ConversationSendFailureReason::tryFrom((string) $row->send_failure_reason)
            : null;

        $reason ??= ConversationSendFailureReason::SendFailed;

        $label = $row->send_status === 'delivery_failed'
            ? __('locale.conversations.delivery_failed_label')
            : __('locale.conversations.send_failed_label');

        return $label . ' — ' . $reason->customerMessage();
    }

    /**
     * Only what the row proves. A legacy outbound row with no provenance could
     * be an inbox reply or a keyword auto-reply, so it claims nothing.
     */
    private function via(object $row): ?string
    {
        if ($row->automation_step_run_id !== null) {
            return AttributedOutboundMessagesSource::sentBy('automation', $row->workflow_name);
        }

        if ($row->campaign_id !== null || $row->source === ConversationHistoryWriter::SOURCE_CAMPAIGN) {
            return AttributedOutboundMessagesSource::sentBy('campaign', $row->campaign_name);
        }

        if ($row->source === ConversationHistoryWriter::SOURCE_CONVERSATIONS) {
            return 'Sent manually';
        }

        return null;
    }

    /**
     * `direction` is the canonical field. A historical row written before it
     * existed falls back to `send_by` — 'to' is the external side — exactly as
     * ContactDirectory reads the same table.
     */
    private function direction(object $row): TimelineDirection
    {
        if ($row->direction !== null) {
            return $row->direction === Reports::DIRECTION_INCOMING ? TimelineDirection::Inbound : TimelineDirection::Outbound;
        }

        return $row->send_by === 'to' ? TimelineDirection::Inbound : TimelineDirection::Outbound;
    }

    /**
     * Managed inbound joins several media URLs with a comma; everything else
     * stores one.
     *
     * @return list<string>
     */
    public static function mediaList(?string $mediaUrl): array
    {
        if ($mediaUrl === null || trim($mediaUrl) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $mediaUrl)), static fn (string $url): bool => $url !== ''));
    }
}
