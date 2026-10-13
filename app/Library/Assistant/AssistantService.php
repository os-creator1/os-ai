<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiAllowanceReader;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One customer message in, one persisted reply out. Owns the conversation's storage and ordering; leaves language
 * understanding to AssistantInterpreter, every read to a ReadTool and every write to AssistantActionService.
 *
 * Order of resolution, cheapest first:
 *   1. a replay (same client message id) returns the stored reply — no second model call, ever;
 *   2. an exact well-known phrase is answered from the module's reader with NO model call;
 *   3. if the included AI is not available, the customer is told the truth about the state — no model call, and no
 *      invented summary;
 *   4. otherwise (after an optional, cost-accounted compaction of old turns) the interpreter runs.
 */
final class AssistantService
{
    public function __construct(
        private readonly AssistantFastPath $fast,
        private readonly AssistantInterpreter $interpreter,
        private readonly AssistantCompactor $compactor,
        private readonly AiAllowanceReader $allowance,
        private readonly AssistantPresenter $presenter,
    ) {
    }

    /** The newest conversation of THIS user in THIS Business, or null. Never another's. */
    public function latest(AssistantContext $context): ?AssistantConversation
    {
        return AssistantConversation::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('business_id', $context->business->id)
            ->where('user_id', $context->user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }

    public function find(AssistantContext $context, string $uid): ?AssistantConversation
    {
        return AssistantConversation::query()
            ->where('uid', $uid)
            ->where('workspace_id', $context->workspace->id)
            ->where('business_id', $context->business->id)
            ->where('user_id', $context->user->id)
            ->first();
    }

    /** @return array<string, mixed> */
    public function send(AssistantContext $context, ?string $conversationUid, string $text, string $clientMessageId, bool $startNew = false): array
    {
        $text = trim(Str::limit(trim($text), (int) config('assistant.question_max_chars'), ''));

        $conversation = $conversationUid !== null
            ? ($this->find($context, $conversationUid) ?? abort(404))
            : ($startNew ? null : $this->latest($context));

        if ($conversation === null || $conversation->message_count >= (int) config('assistant.conversation_max_messages')) {
            $conversation = AssistantConversation::create([
                'uid' => (string) Str::uuid(),
                'workspace_id' => $context->workspace->id,
                'business_id' => $context->business->id,
                'user_id' => $context->user->id,
                'title' => Str::limit($text, 60, ''),
                'message_count' => 0,
            ]);
        }

        // One message at a time per conversation: ordering, and a duplicate in flight, are settled here.
        $lock = Cache::lock('assistant:conversation:' . $conversation->id, 90);

        if (! $lock->get()) {
            return ['status' => 'busy'] + $this->presenter->conversation($context, $conversation);
        }

        try {
            return $this->handle($context, $conversation, $text, $clientMessageId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Writes what happened to a proposal into the conversation, so a refresh (and the model's next turn) both see it.
     * Only real transitions are recorded; a replay or a busy answer adds nothing.
     *
     * @param  array{state: string, proposal: \App\Models\AssistantActionProposal, message: ?string, url: ?string}  $result
     */
    public function recordOutcome(AssistantContext $context, array $result): void
    {
        if (in_array($result['state'], ['replayed', 'busy'], true)) {
            return;
        }

        $proposal = $result['proposal'];
        $conversation = $this->find($context, (string) AssistantConversation::query()->whereKey($proposal->conversation_id)->value('uid'));

        if ($conversation === null) {
            return;
        }

        $body = match ($result['state']) {
            'completed', 'handed_off' => (string) $result['message'],
            'declined' => 'Okay — I have not done that.',
            'expired' => 'That expired before it was confirmed, so nothing was done.',
            default => (string) ($result['message'] ?? 'That was not done.'),
        };

        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'uid' => (string) Str::uuid(),
            'role' => AssistantMessage::ROLE_ASSISTANT,
            'kind' => 'outcome',
            'body' => $body,
            'payload' => ['proposal_uid' => $proposal->uid, 'state' => $result['state'], 'url' => $result['url']],
            'created_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->increment('message_count');
    }

    /** @return array<string, mixed> */
    private function handle(AssistantContext $context, AssistantConversation $conversation, string $text, string $clientMessageId): array
    {
        $existing = AssistantMessage::query()->where('conversation_id', $conversation->id)->where('client_message_id', $clientMessageId)->first();

        if ($existing === null) {
            try {
                $existing = AssistantMessage::create([
                    'conversation_id' => $conversation->id,
                    'uid' => (string) Str::uuid(),
                    'client_message_id' => $clientMessageId,
                    'role' => AssistantMessage::ROLE_USER,
                    'kind' => 'text',
                    'body' => $text,
                    'created_at' => now(),
                ]);
                $conversation->increment('message_count');
            } catch (QueryException) {
                // Lost a race for the same client id: the winner's row is the one message.
                $existing = AssistantMessage::query()->where('conversation_id', $conversation->id)->where('client_message_id', $clientMessageId)->firstOrFail();
            }
        }

        $replied = AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AssistantMessage::ROLE_ASSISTANT)
            ->where('id', '>', $existing->id)
            ->exists();

        if (! $replied) {
            $this->reply($context, $conversation, $existing, $existing->body);
        }

        return ['status' => $replied ? 'replayed' : 'ok'] + $this->presenter->conversation($context, $conversation->refresh());
    }

    private function reply(AssistantContext $context, AssistantConversation $conversation, AssistantMessage $userMessage, string $question): void
    {
        $turn = $context->viewingAsClient()
            ? new AssistantTurn(AssistantTurn::UNSUPPORTED, "Ask MotionGrove isn't available while you're viewing as a client. Switch back to your own account to use it.", ['links' => []])
            : $this->fast->match($context, $question);

        if ($turn === null) {
            $state = $this->allowance->forWorkspace($context->workspace, $context->business);

            if (! $state->usable()) {
                $turn = $this->interpreter->limit($context);
            } else {
                $this->compactor->maybeCompact($context, $conversation, $userMessage);
                $turn = $this->interpreter->interpret($context, $conversation, $userMessage, $question);
            }
        }

        $payload = $turn->payload;

        if ($turn->proposal !== null) {
            $payload['proposal_uid'] = $turn->proposal->uid;
            $turn->proposal->forceFill(['conversation_id' => $conversation->id])->save();
        }

        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'uid' => (string) Str::uuid(),
            'role' => AssistantMessage::ROLE_ASSISTANT,
            'kind' => $turn->kind,
            'body' => $turn->body,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->increment('message_count');
    }
}
