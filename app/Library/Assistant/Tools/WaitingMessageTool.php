<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Assistant\ToolResult;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\ChatBox;
use Illuminate\Support\Str;

/**
 * The ONE place a waiting customer's message text reaches the model, and only for the one conversation the model names
 * by handle, because drafting a reply to it needs the words. First name only; no phone number. The handle is resolved
 * over the conversations THIS actor may see (the inbox's Location rule); anything else is "not available".
 */
final class WaitingMessageTool implements ReadTool
{
    public function __construct(private readonly BusinessConversationReadModel $conversations)
    {
    }

    public function key(): string
    {
        return 'waiting_message';
    }

    public function description(): string
    {
        return 'Read ONE waiting customer\'s latest message (and first name) so you can draft a reply. args: handle (from unanswered_conversations). Use only when drafting a reply.';
    }

    public function rules(): array
    {
        return ['handle' => ['required', 'string', 'max:32']];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->can('chat_box') && $context->entitled('conversations');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        foreach ($this->conversations->awaitingReplyConversations($context->business, (int) $context->user->id, 25) as $row) {
            if (! hash_equals(AssistantHandles::for($context, 'conversation', (string) $row['uid']), (string) $args['handle'])) {
                continue;
            }

            $names = ChatBox::displayNamesFor($context->business, [(object) ['id' => $row['id'], 'to' => $row['to']]]);

            return new ToolResult($this->key(), [
                'first_name' => AssistantHandles::firstName($names[$row['id']] ?? null, (string) $row['to']),
                'latest_message' => Str::limit(trim((string) $row['body']), 240),
            ]);
        }

        return ToolResult::unavailable($this->key(), 'That conversation is not waiting for a reply, or is not one you can see.');
    }
}
