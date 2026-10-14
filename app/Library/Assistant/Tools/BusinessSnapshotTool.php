<?php

namespace App\Library\Assistant\Tools;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Conversations\BusinessConversationReadModel;

/** How the Business is doing: new contacts, messages and automation runs, this period against the one before. */
final class BusinessSnapshotTool implements ReadTool
{
    public function __construct(
        private readonly BusinessDashboardAnalyticsPresenter $analytics,
        private readonly BusinessConversationReadModel $conversations,
    ) {
    }

    public function key(): string
    {
        return 'business_snapshot';
    }

    public function description(): string
    {
        return 'Measured results: new contacts/leads, messages in and out, conversations waiting for a reply, automation runs — for a period and the period before it. args: period = last_7_days|last_30_days|this_month|last_month (default last_30_days).';
    }

    public function rules(): array
    {
        return ['period' => ['sometimes', 'string', 'in:last_7_days,last_30_days,this_month,last_month']];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->can('view_reports');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $preset = $args['period'] ?? AnalyticsDateRange::PRESET_LAST_30_DAYS;
        $range = AnalyticsDateRange::preset($preset, $context->timezone());
        $cmp = $this->analytics->comparison($context->business, $range);

        $cur = $cmp['current'];
        $prev = $cmp['previous'];
        $label = $cur['range']->label();
        $prevLabel = $prev['range']->label();

        $data = [
            'period' => $label,
            'previous_period' => $prevLabel,
            'new_contacts' => ['current' => $cur['contacts']->newInRange, 'previous' => $prev['contacts']->newInRange],
            'total_contacts_now' => $cur['contacts']->totalNow,
            'messages_received' => ['current' => $cur['messages']->inbound, 'previous' => $prev['messages']->inbound],
            'messages_sent' => ['current' => $cur['messages']->outbound, 'previous' => $prev['messages']->outbound],
        ];
        $facts = [
            ['label' => 'New contacts · ' . $label, 'value' => $cur['contacts']->newInRange . ' (before: ' . $prev['contacts']->newInRange . ')'],
            ['label' => 'Messages received · ' . $label, 'value' => $cur['messages']->inbound . ' (before: ' . $prev['messages']->inbound . ')'],
        ];
        $limitations = [];

        if ($context->can('chat_box') && $context->entitled('conversations')) {
            $awaiting = $this->conversations->awaitingReplyCount($context->business, null, (int) $context->user->id);
            $data['conversations_waiting_for_reply'] = $awaiting;
            $facts[] = ['label' => 'Waiting for your reply', 'value' => (string) $awaiting];
        }

        if ($cur['automations'] !== null) {
            $data['automation_runs'] = ['current' => $cur['automations']->executionsInRange, 'failed' => $cur['automations']->failed()];
            $facts[] = ['label' => 'Automation runs · ' . $label, 'value' => $cur['automations']->executionsInRange . ' (' . $cur['automations']->failed() . ' failed)'];
        }

        $data['note'] = 'Counts are what MotionGrove recorded; they do not include sales made outside it.';
        return new ToolResult($this->key(), $data, $facts, [], $limitations);
    }
}
