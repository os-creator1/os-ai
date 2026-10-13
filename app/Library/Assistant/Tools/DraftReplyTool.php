<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Models\ChatBox;
use Illuminate\Support\Str;

/**
 * Prepare a reply to a customer who is waiting. The assistant NEVER sends it: sending belongs to the inbox's own
 * reply endpoint (messaging readiness, funding, opt-out and idempotency are its). Approving hands the exact text to
 * the Follow-ups drawer, pre-filled, where the customer presses Send on the module's own form.
 */
final class DraftReplyTool implements ActionTool
{
    public function __construct(private readonly BusinessConversationReadModel $conversations)
    {
    }

    public function key(): string
    {
        return 'draft_reply';
    }

    public function description(): string
    {
        return 'Prepare a reply to a waiting customer (it is NOT sent; the customer reviews and sends it themselves). args: conversation_ref (the "ref" from unanswered_conversations), message (the reply text, written in the business\'s voice, max 600 chars).';
    }

    public function rules(): array
    {
        return [
            'conversation_ref' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'min:2', 'max:600'],
        ];
    }

    public function mode(): string
    {
        return self::MODE_HANDOFF;
    }

    public function available(AssistantContext $context): bool
    {
        $drawer = $context->action('follow-ups');

        return $drawer !== null && $drawer['drawerUrl'] !== null;
    }

    public function prepare(AssistantContext $context, array $args): ActionPreview
    {
        $row = $this->waiting($context, (string) $args['conversation_ref']);
        $names = ChatBox::displayNamesFor($context->business, [(object) ['id' => $row['id'], 'to' => $row['to']]]);
        $name = (string) ($names[$row['id']] ?? $row['to']);

        return new ActionPreview(
            title: 'Reply to ' . $name,
            lines: [
                ['label' => 'To', 'value' => $name . ($name !== $row['to'] ? ' · ' . $row['to'] : '')],
                ['label' => 'They wrote', 'value' => Str::limit(trim((string) $row['body']), 160)],
                ['label' => 'Your reply', 'value' => trim((string) $args['message'])],
            ],
            effects: [
                'Nothing is sent yet. Confirming opens this reply in Follow-ups, filled in exactly as shown.',
                'You press Send there. Sending uses your messaging as usual, including any messaging charges shown at that point.',
            ],
            recipients: [$name],
            cost: null,
            resolved: ['conversation_uid' => (string) $row['uid'], 'message' => trim((string) $args['message']), 'at' => (string) $row['at']],
            confirmLabel: 'Review and send in Follow-ups',
        );
    }

    public function fingerprint(AssistantContext $context, array $resolved): string
    {
        $row = $this->waiting($context, (string) $resolved['conversation_uid']);

        // Changes if the customer wrote again or somebody replied in the meantime (the row would then be gone or newer).
        return hash('sha256', json_encode([(string) $row['uid'], (string) $row['at']]));
    }

    public function execute(AssistantContext $context, array $resolved): ActionOutcome
    {
        $drawer = $context->action('follow-ups');

        if ($drawer === null || $drawer['drawerUrl'] === null) {
            throw new AssistantActionRefused('no_longer_available', 'Follow-ups is no longer available to you, so the reply was not opened.');
        }

        return new ActionOutcome(
            'Your reply is ready in Follow-ups. Nothing has been sent — review it there and press Send.',
            $drawer['drawerUrl'] . (str_contains($drawer['drawerUrl'], '?') ? '&' : '?') . 'proposal=' . urlencode((string) ($resolved['_proposal_uid'] ?? '')),
            ['handoff' => 'follow-ups'],
        );
    }

    /** @return array<string, mixed> the awaiting-reply row, only if THIS actor can see it (the inbox's own Location rule) */
    private function waiting(AssistantContext $context, string $uid): array
    {
        foreach ($this->conversations->awaitingReplyConversations($context->business, (int) $context->user->id, 25) as $row) {
            if ((string) $row['uid'] === $uid) {
                return $row;
            }
        }

        throw new AssistantActionRefused('not_waiting', 'That customer is no longer waiting for a reply (or is not one you can see), so there is nothing to prepare.');
    }
}
