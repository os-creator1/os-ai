<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiAllowanceReader;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
        private readonly AssistantRetention $retention,
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

        if ($conversationUid !== null) {
            $conversation = $this->find($context, $conversationUid) ?? abort(404);
        } else {
            // No conversation named (a first message, or "new"): the message's own id decides. The same client_message_id from
            // the same actor in the same Business is the SAME message — a retry whose first response was lost must land in the
            // conversation it created, not make another one and use the model again. The find-or-create runs under a short lock
            // keyed by actor + Business + message id so two identical first requests cannot both create (the loser waits, then
            // finds the winner's message); and the user's message is stored before the lock is released, so that is what it finds.
            $first = Cache::lock('assistant:first:' . sha1($context->workspace->id . '|' . $context->business->id . '|' . $context->user->id . '|' . $clientMessageId), 20);

            if (! AssistantRetention::acquireWithin($first, max(1, (int) config('assistant.first_message_lock_wait_seconds')))) {
                return ['status' => 'busy', 'uid' => null, 'messages' => []];
            }

            try {
                $conversation = $this->conversationOfMessage($context, $clientMessageId)
                    ?? ($startNew ? null : $this->latest($context));
                $replay = $conversation !== null && $this->messageInConversation($conversation, $clientMessageId);

                if ($conversation === null || (! $replay && $conversation->message_count >= (int) config('assistant.conversation_max_messages'))) {
                    $conversation = $this->startConversation($context, $text);
                    $this->storeUserMessage($conversation, $clientMessageId, $text);
                }
            } finally {
                $first->release();
            }
        }

        // One turn at a time per conversation: ordering, and a duplicate in flight, are settled here. The lease is derived
        // from the turn's wall-clock budget so it always outlives the work it protects; the lock has an owner token, so
        // only this request can release it, and a request that has lost it writes nothing.
        $budget = max(10, (int) config('assistant.turn_budget_seconds'));
        $lock = AssistantRetention::turnLock((int) $conversation->id, $budget + max(15, (int) config('assistant.turn_lock_margin_seconds')));

        if (! $lock->get()) {
            return ['status' => 'busy'] + $this->presenter->conversation($context, $conversation);
        }

        try {
            // Deleted between being found and being locked (a customer's delete or the retention sweep): nothing to write to.
            if (! AssistantConversation::query()->whereKey($conversation->id)->exists()) {
                abort(404);
            }

            return $this->handle($context, $conversation, $text, $clientMessageId, $lock, Carbon::now()->getTimestamp() + $budget);
        } finally {
            $lock->release();
        }
    }

    /** The conversation this actor already put this client_message_id into, in this Business — or null. */
    private function conversationOfMessage(AssistantContext $context, string $clientMessageId): ?AssistantConversation
    {
        $conversationId = AssistantMessage::query()
            ->where('client_message_id', $clientMessageId)
            ->where('role', AssistantMessage::ROLE_USER)
            ->whereIn('conversation_id', AssistantConversation::query()
                ->where('workspace_id', $context->workspace->id)
                ->where('business_id', $context->business->id)
                ->where('user_id', $context->user->id)
                ->select('id'))
            ->orderBy('id')
            ->value('conversation_id');

        return $conversationId === null ? null : AssistantConversation::query()->find($conversationId);
    }

    private function messageInConversation(AssistantConversation $conversation, string $clientMessageId): bool
    {
        return AssistantMessage::query()->where('conversation_id', $conversation->id)->where('client_message_id', $clientMessageId)->exists();
    }

    private function startConversation(AssistantContext $context, string $text): AssistantConversation
    {
        $firstUse = $this->latest($context) === null;

        $conversation = AssistantConversation::create([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $context->workspace->id,
            'business_id' => $context->business->id,
            'user_id' => $context->user->id,
            'title' => Str::limit($text, 60, ''),
            'message_count' => 0,
        ]);

        if ($firstUse) {
            $this->recordNotice($conversation);
        }

        return $conversation;
    }

    private function storeUserMessage(AssistantConversation $conversation, string $clientMessageId, string $text): AssistantMessage
    {
        $message = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'uid' => (string) Str::uuid(),
            'client_message_id' => $clientMessageId,
            'role' => AssistantMessage::ROLE_USER,
            'kind' => 'text',
            'body' => $text,
            'created_at' => now(),
        ]);
        $conversation->increment('message_count');

        return $message;
    }

    /** The AI-data notice, as the first message of a customer's first conversation in a Business. */
    public function noticeText(): string
    {
        return __('ask_ai.data_notice.body', ['days' => AssistantRetention::retentionDays()]);
    }

    private function recordNotice(AssistantConversation $conversation): void
    {
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'uid' => (string) Str::uuid(),
            'role' => AssistantMessage::ROLE_ASSISTANT,
            'kind' => 'notice',
            'body' => $this->noticeText(),
            'payload' => ['title' => __('ask_ai.data_notice.title')],
            'created_at' => now(),
        ]);
        $conversation->increment('message_count');
    }

    /** Customer-requested deletion of one of THEIR conversations. @return bool false = an approved action is still running */
    public function delete(AssistantContext $context, string $uid): bool
    {
        $conversation = $this->find($context, $uid) ?? abort(404);

        return $this->retention->delete($conversation, 'customer_request', max(0, (int) config('assistant.delete_wait_seconds')));
    }

    /**
     * Writes what happened to a proposal into the conversation, so a refresh (and the model's next turn) both see it.
     * Only real transitions are recorded; a replay or a busy answer adds nothing.
     *
     * @param  array{state: string, proposal: \App\Models\AssistantActionProposal, message: ?string, url: ?string, transitioned?: bool}  $result
     */
    public function recordOutcome(AssistantContext $context, array $result): void
    {
        // Only a call that actually moved the proposal records anything: a repeated approve/decline on a final proposal,
        // a replay and a busy answer add no message.
        if (! ($result['transitioned'] ?? false) || in_array($result['state'], ['replayed', 'busy'], true)) {
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

        try {
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
        } catch (QueryException $e) {
            // The conversation was deleted in the instant between finding it and writing: the outcome has nowhere to go.
            if (AssistantConversation::query()->whereKey($conversation->id)->exists()) {
                throw $e;
            }
        }
    }

    /**
     * Has this user message actually been ANSWERED? Only an assistant reply counts — not an action outcome (an approval can be
     * recorded at any moment, concurrently with a turn) nor the first-use notice — and only one that comes before the next user
     * message, so a later turn's answer is never borrowed for an earlier question.
     */
    private function hasAnswer(AssistantConversation $conversation, AssistantMessage $userMessage): bool
    {
        $nextUser = AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AssistantMessage::ROLE_USER)
            ->where('id', '>', $userMessage->id)
            ->min('id');

        return AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', AssistantMessage::ROLE_ASSISTANT)
            ->whereNotIn('kind', ['outcome', 'notice'])
            ->where('id', '>', $userMessage->id)
            ->when($nextUser !== null, fn ($q) => $q->where('id', '<', $nextUser))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function handle(AssistantContext $context, AssistantConversation $conversation, string $text, string $clientMessageId, \Illuminate\Contracts\Cache\Lock $lock, int $deadline): array
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

        $replied = $this->hasAnswer($conversation, $existing);

        if (! $replied) {
            if (! $this->reply($context, $conversation, $existing, $existing->body, $lock, $deadline)) {
                return ['status' => 'busy'] + $this->presenter->conversation($context, $conversation->refresh());
            }
        }

        return ['status' => $replied ? 'replayed' : 'ok'] + $this->presenter->conversation($context, $conversation->refresh());
    }

    /** @return bool false when the turn lost its lock and therefore wrote nothing */
    private function reply(AssistantContext $context, AssistantConversation $conversation, AssistantMessage $userMessage, string $question, \Illuminate\Contracts\Cache\Lock $lock, int $deadline): bool
    {
        try {
            $turn = $context->viewingAsClient()
                ? new AssistantTurn(AssistantTurn::UNSUPPORTED, "Ask MotionGrove isn't available while you're viewing as a client. Switch back to your own account to use it.", ['links' => []])
                : $this->fast->match($context, $question);

            if ($turn === null) {
                $state = $this->allowance->forWorkspace($context->workspace, $context->business);

                if (! $state->usable()) {
                    $turn = $this->interpreter->limit($context);
                } else {
                    $this->compactor->maybeCompact($context, $conversation, $userMessage, $deadline);
                    $turn = $this->interpreter->interpret($context, $conversation, $userMessage, $question, $deadline);
                }
            }
        } catch (QueryException $e) {
            // A turn that outlived its own lock while the conversation was deleted (its proposal or reply has no parent any
            // more): nothing may be recreated, and it is not a server error.
            if (! AssistantConversation::query()->whereKey($conversation->id)->exists()) {
                abort(404);
            }

            throw $e;
        }

        // Ownership is re-checked immediately before anything is written: a turn whose lease lapsed (it should never) must
        // not write a reply a newer owner may also be writing.
        if (! $lock->isOwnedByCurrentProcess()) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($conversation, $turn): bool {
            // The conversation must still exist at the moment of writing (a deletion cannot run while we hold the lock, but a
            // stale turn must still never recreate anything beneath a deleted conversation).
            if (AssistantConversation::query()->whereKey($conversation->id)->lockForUpdate()->first() === null) {
                abort(404);
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

            return true;
        });
    }
}
