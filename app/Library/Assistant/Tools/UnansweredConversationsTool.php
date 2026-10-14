<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Assistant\ToolResult;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\ChatBox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Customers whose latest message is still waiting for a reply. The conversation read model applies the inbox's Location
 * rule.
 *
 * DATA MINIMISATION. The model is told HOW MANY are waiting and, per conversation, an opaque handle and how long it has
 * waited — no name, phone number or message text. Names and snippets live in `display`/`facts`/`links`, which are shown
 * to the customer by the server and never sent to the provider. A draft reply that genuinely needs the text asks for it
 * through `waiting_message`, one conversation at a time.
 */
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
        return 'How many customers/leads are waiting for a reply, and for each an opaque handle and how long it has waited (no names or message text; use waiting_message to read one when drafting a reply). No args.';
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

        $model = [];
        $display = [];
        $links = [];

        foreach ($rows as $r) {
            $name = (string) ($names[$r['id']] ?? $r['to']);
            $model[] = ['handle' => AssistantHandles::for($context, 'conversation', (string) $r['uid']), 'waiting_for' => CarbonImmutable::parse($r['at'])->diffForHumans(null, true)];
            $display[] = ['name' => $name, 'last_message' => Str::limit(trim((string) $r['body']), 160), 'at' => (string) $r['at']];
            $links[] = ['label' => 'Open ' . $name, 'url' => route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) . '?conversation=' . $r['uid']];
        }

        $limitations = $total > count($model) ? ['Showing ' . count($model) . ' of ' . $total . ' waiting conversations.'] : [];

        return new ToolResult(
            $this->key(),
            ['waiting_total' => $total, 'longest_waiting' => $model],
            [['label' => 'Waiting for a reply', 'value' => (string) $total]],
            $links,
            $limitations,
            true,
            $display,
        );
    }
}
