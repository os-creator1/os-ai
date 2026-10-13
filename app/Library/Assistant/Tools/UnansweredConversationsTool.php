<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\ChatBox;
use Illuminate\Support\Str;

/** Customers whose latest message is still waiting for a reply. The conversation read model applies the inbox's Location rule. */
final class UnansweredConversationsTool implements ReadTool
{
    public function __construct(private readonly BusinessConversationReadModel $conversations)
    {
    }

    public function key(): string
    {
        return 'unanswered_conversations';
    }

    public function description(): string
    {
        return 'Customers/leads whose latest message has not been replied to yet (name, last message, when). No args.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->can('chat_box') && $context->entitled('conversations');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $rows = $this->conversations->awaitingReplyConversations($context->business, (int) $context->user->id, 5);
        $total = $this->conversations->awaitingReplyCount($context->business, null, (int) $context->user->id);
        $names = ChatBox::displayNamesFor($context->business, array_map(fn (array $r): object => (object) ['id' => $r['id'], 'to' => $r['to']], $rows));
        [$workspaceUid, $businessUid] = $context->scoped;

        $items = [];
        $links = [];

        foreach ($rows as $r) {
            $name = $names[$r['id']] ?? $r['to'];
            $items[] = [
                'ref' => $r['uid'],
                'name' => (string) $name,
                'last_message' => Str::limit(trim((string) $r['body']), 160),
                'at' => (string) $r['at'],
            ];
            $links[] = ['label' => 'Open ' . $name, 'url' => route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) . '?conversation=' . $r['uid']];
        }

        $limitations = $total > count($items) ? ['Showing ' . count($items) . ' of ' . $total . ' waiting conversations.'] : [];

        return new ToolResult(
            $this->key(),
            ['waiting_total' => $total, 'shown' => $items],
            [['label' => 'Waiting for a reply', 'value' => (string) $total]],
            $links,
            $limitations,
        );
    }
}
