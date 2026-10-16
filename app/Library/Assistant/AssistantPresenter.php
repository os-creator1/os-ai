<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiAllowance;
use App\Library\Ai\AiAllowanceReader;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;

/**
 * The JSON the Home panel renders. Pure presentation of stored rows plus the CURRENT allowance state — nothing here
 * decides anything, and nothing internal (cost, tokens, model, provider, ledger) can appear in it.
 */
final class AssistantPresenter
{
    public function __construct(
        private readonly AiAllowanceReader $allowance,
        private readonly AssistantAllowanceMessages $limitMessages,
    ) {
    }

    /** @return array<string, mixed> */
    /**
     * One page of a conversation: the newest `$limit` messages, or — with `$beforeUid` — the `$limit` messages immediately
     * older than that message. The cursor is a message's own uid (never a row number), resolved inside THIS conversation, so
     * a pager can neither skip, repeat nor wander into another conversation. `page.has_more` says whether anything older
     * remains; `page.before` is the cursor for the next (older) page. Reads only: no model is ever involved.
     *
     * @return array<string, mixed>
     */
    public function conversation(AssistantContext $context, AssistantConversation $conversation, int $limit = 40, ?string $beforeUid = null): array
    {
        $beforeId = null;

        if ($beforeUid !== null) {
            $beforeId = AssistantMessage::query()->where('conversation_id', $conversation->id)->where('uid', $beforeUid)->value('id');

            if ($beforeId === null) {
                abort(404);
            }
        }

        $fetched = AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $fetched->count() > $limit;
        $rows = $fetched->take($limit)->reverse()->values();

        $state = $this->allowance->forWorkspace($context->workspace, $context->business);

        $proposalUids = $rows->map(fn (AssistantMessage $m) => $m->payload['proposal_uid'] ?? null)->filter()->values()->all();
        $proposals = $proposalUids === [] ? collect() : AssistantActionProposal::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $context->user->id)
            ->whereIn('uid', $proposalUids)
            ->get()
            ->keyBy('uid');

        return [
            'uid' => $conversation->uid,
            'title' => $conversation->title,
            'messages' => $rows->map(fn (AssistantMessage $m): array => $this->message($m, $proposals->get($m->payload['proposal_uid'] ?? ''), $state))->all(),
            'page' => ['has_more' => $hasMore, 'before' => $rows->first()?->uid],
            'allowance' => $this->allowanceView($context, $state),
        ];
    }

    /** @return array<string, mixed> */
    public function allowanceView(AssistantContext $context, AiAllowance $state): array
    {
        $view = ['status' => $state->status, 'usable' => $state->usable(), 'nearing' => $state->status === AiAllowance::NEARING_LIMIT, 'blocked' => null];

        if (! $state->usable()) {
            $view['blocked'] = $this->limitMessages->blocked($state, $context);
        }

        return $view;
    }

    /** @return array<string, mixed> */
    public function message(AssistantMessage $m, ?AssistantActionProposal $proposal, AiAllowance $state): array
    {
        $payload = $m->payload ?? [];

        if ($m->kind === 'limit' && $state->usable()) {
            // AI is available again (e.g. after a confirmed upgrade): the old limit card stays as history, without its call to action.
            $payload['upgrade'] = null;
            $payload['ownerOnly'] = false;
            $payload['resolved'] = true;
        }

        unset($payload['proposal_uid']);

        // The AI-data notice is a standing disclosure, not part of the conversation's content: it always shows the retention policy in force NOW,
        // whatever the policy was when the row was written. The stored row itself is never changed.
        $body = $m->kind === 'notice' ? AssistantRetention::disclosure() : $m->body;

        return [
            'uid' => $m->uid,
            'role' => $m->role,
            'kind' => $m->kind,
            'body' => $body,
            'payload' => $payload === [] ? new \stdClass() : $payload,
            'proposal' => $proposal === null ? null : $this->proposal($proposal),
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function proposal(AssistantActionProposal $p): array
    {
        $status = $p->status === AssistantActionProposal::PENDING && $p->expires_at->isPast() ? AssistantActionProposal::EXPIRED : $p->status;
        $actionable = in_array($status, [AssistantActionProposal::PENDING, AssistantActionProposal::FAILED], true)
            && ($status === AssistantActionProposal::PENDING || $p->attempts < AssistantActionService::MAX_ATTEMPTS)
            && ! $p->expires_at->isPast();

        return [
            'uid' => $p->uid,
            'tool' => $p->tool_key,
            'status' => $status,
            'preview' => $p->preview,
            'canConfirm' => $actionable,
            'retry' => $status === AssistantActionProposal::FAILED && $actionable,
            'expiresAt' => $p->expires_at->toIso8601String(),
            'resultMessage' => $p->result['message'] ?? null,
            'resultUrl' => $p->result['url'] ?? null,
        ];
    }
}
