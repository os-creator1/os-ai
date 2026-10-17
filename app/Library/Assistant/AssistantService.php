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

    /**
     * The history list: THIS user's conversations in THIS Business, newest activity first, one keyset page at a time. The cursor is a
     * snapshot of where the previous page's LAST row sat — (last_message_at, id) at the moment it was listed — never a live lookup of
     * that conversation: one that receives a message between two pages therefore cannot make the next page restart, repeat rows or
     * skip them. The cursor carries no authority; every page is scoped to the caller's Workspace, Business and user, so it can only ever
     * position a read inside the caller's own list. A conversation that has no message yet is not listed. Reads only; no model call.
     * A conversation that moves to the top while the customer is paging is not shown again below; it is at the top on the next refresh.
     *
     * @return array{conversations: array<int, array<string, mixed>>, has_more: bool, next: ?string}
     */
    public function history(AssistantContext $context, ?string $after = null): array
    {
        $size = max(1, min(50, (int) config('assistant.history_page_size')));
        $scope = fn () => AssistantConversation::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('business_id', $context->business->id)
            ->where('user_id', $context->user->id)
            ->whereNotNull('last_message_at');

        $query = $scope();

        if ($after !== null) {
            [$at, $id] = self::decodeHistoryCursor($after) ?? abort(422);
            $query->where(function ($q) use ($at, $id) {
                $q->where('last_message_at', '<', $at)
                    ->orWhere(fn ($q2) => $q2->where('last_message_at', $at)->where('id', '<', $id));
            });
        }

        $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size);

        // The preview is the last thing actually said (a question or an answer), never the AI-data notice or an action outcome.
        $latest = $rows->isEmpty() ? collect() : AssistantMessage::query()
            ->whereIn('conversation_id', $rows->pluck('id'))
            ->whereIn('kind', ['text', 'answer', 'clarify', 'unsupported', 'navigate', 'error', 'limit'])
            ->selectRaw('conversation_id, max(id) as last_id')
            ->groupBy('conversation_id')
            ->pluck('last_id', 'conversation_id');
        $bodies = $latest->isEmpty() ? collect() : AssistantMessage::query()->whereIn('id', $latest->values())->pluck('body', 'id');

        return [
            'conversations' => $rows->map(fn (AssistantConversation $c): array => [
                'uid' => $c->uid,
                'title' => (string) ($c->title ?: 'Conversation'),
                'preview' => Str::limit(trim((string) ($bodies[$latest[$c->id] ?? 0] ?? '')), 110),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'message_count' => (int) $c->message_count,
            ])->values()->all(),
            'has_more' => $hasMore,
            'next' => $hasMore ? self::encodeHistoryCursor($rows->last()) : null,
        ];
    }

    private static function encodeHistoryCursor(AssistantConversation $c): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$c->last_message_at->format('Y-m-d H:i:s'), (int) $c->id])), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int}|null null when the token is not one this list issued */
    private static function decodeHistoryCursor(string $token): ?array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        $parts = $raw === false ? null : json_decode($raw, true);

        if (! is_array($parts) || count($parts) !== 2 || ! is_string($parts[0]) || ! is_int($parts[1]) || $parts[1] < 1
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $parts[0])) {
            return null;
        }

        return [$parts[0], $parts[1]];
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

        // Which conversation does this message belong to? One short, serialized step per actor + Business, for addressed and
        // unaddressed requests alike:
        //   1. a message id this actor has already put into a conversation in this Business is a REPLAY: it belongs where its
        //      original result is, whatever uid the (possibly stale) browser names and however full that conversation now is;
        //   2. otherwise the named conversation (or, when none is named, the latest — or a new one for "new") is used;
        //   3. a conversation that has reached `conversation_max_messages` is not grown: a genuinely new turn starts a new one
        //      (the full one is kept untouched). A twin request that already rolled it over is joined, not duplicated.
        // The lock covers only this resolution and the creation of a replacement (with its first user message stored before the
        // lock is released, which is what a duplicate then finds); the turn itself runs under the conversation's own lock.
        $resolve = Cache::lock('assistant:resolve:' . sha1($context->workspace->id . '|' . $context->business->id . '|' . $context->user->id), 20);

        if (! AssistantRetention::acquireWithin($resolve, max(1, (int) config('assistant.first_message_lock_wait_seconds')))) {
            return ['status' => 'busy', 'uid' => null, 'messages' => []];
        }

        try {
            $conversation = $this->conversationOfMessage($context, $clientMessageId);

            if ($conversation === null) {
                $conversation = $conversationUid !== null
                    ? ($this->find($context, $conversationUid) ?? abort(404))
                    : ($startNew ? null : $this->latest($context));

                if ($conversation !== null && $conversation->message_count >= (int) config('assistant.conversation_max_messages')) {
                    // The newest conversation of this actor in this Business, by creation (a replacement that is still being
                    // answered has no last-message time yet, so the "latest" ordering cannot be relied on to see it).
                    $newer = AssistantConversation::query()
                        ->where('workspace_id', $context->workspace->id)
                        ->where('business_id', $context->business->id)
                        ->where('user_id', $context->user->id)
                        ->orderByDesc('id')
                        ->first();
                    $conversation = $newer !== null && $newer->id > $conversation->id && $newer->message_count < (int) config('assistant.conversation_max_messages')
                        ? $newer
                        : null;
                }

                if ($conversation === null) {
                    $conversation = $this->startConversation($context, $text);
                    $this->storeUserMessage($conversation, $clientMessageId, $text);
                }
            }
        } finally {
            $resolve->release();
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
        // The conversation is ordered by its last message, and History and "latest" only know conversations that have one: a saved customer
        // message must make it discoverable at once, so a turn that fails or is interrupted before the reply never hides it.
        $conversation->increment('message_count', 1, ['last_message_at' => now()]);

        return $message;
    }

    /** The AI-data notice, as the first message of a customer's first conversation in a Business. */
    public function noticeText(): string
    {
        return AssistantRetention::disclosure();
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
