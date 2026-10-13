<?php

namespace App\Library\Assistant;

use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an assistant conversation — on the customer's request or when it ages out — and what survives it.
 *
 * REMOVED: the conversation row (its summary), every message (text, facts, links, options) and, for each prepared action,
 * its params, preview and result text (names, phone numbers, drafted message text). A prepared action that was still open
 * is declined first so it can never be approved after its conversation is gone.
 *
 * KEPT, as minimal operational audit facts and independent of the conversation: the proposal row (tool, status, who,
 * which Workspace/Business, when, attempts, failure code) and its audit events reduced to codes — no prompt text, no
 * names, no message text. Removing the whole account removes these too (workspace/user foreign keys cascade).
 *
 * Idempotent (a conversation already gone is a no-op) and always scoped to the conversation's own Workspace/Business.
 */
final class AssistantRetention
{
    private const AUDIT_DETAIL_KEYS = ['tool', 'mode', 'code', 'reason'];

    public static function retentionDays(): int
    {
        return max(1, min(365, (int) config('assistant.retention_days')));
    }

    /** @return bool false when an approved action is still executing (try again later); true when deleted or already gone */
    public function delete(AssistantConversation $conversation, string $reason): bool
    {
        return DB::transaction(function () use ($conversation, $reason): bool {
            $locked = AssistantConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($locked === null) {
                return true;
            }

            $proposals = AssistantActionProposal::query()->where('conversation_id', $locked->id)->lockForUpdate()->get();

            foreach ($proposals as $p) {
                if ($p->status === AssistantActionProposal::EXECUTING && $p->lease_expires_at !== null && $p->lease_expires_at->isFuture()) {
                    return false;
                }
            }

            foreach ($proposals as $p) {
                $open = in_array($p->status, [AssistantActionProposal::PENDING, AssistantActionProposal::FAILED, AssistantActionProposal::EXECUTING], true);

                $p->forceFill([
                    'status' => $open ? AssistantActionProposal::DECLINED : $p->status,
                    'failure_code' => $open ? 'conversation_deleted' : $p->failure_code,
                    'decided_at' => $open ? ($p->decided_at ?? Carbon::now()) : $p->decided_at,
                    'params' => [],
                    'preview' => [],
                    'params_hash' => str_repeat('0', 64),
                    'state_fingerprint' => str_repeat('0', 64),
                    'result' => $p->result === null ? null : ['ok' => (bool) ($p->result['ok'] ?? false)],
                    'lease_token' => null,
                    'scrubbed_at' => Carbon::now(),
                ])->save();

                foreach (AssistantActionAudit::query()->where('proposal_id', $p->id)->get() as $audit) {
                    $audit->forceFill(['detail' => $audit->detail === null ? null : array_intersect_key($audit->detail, array_flip(self::AUDIT_DETAIL_KEYS))])->save();
                }

                AssistantActionAudit::create([
                    'proposal_id' => $p->id,
                    'event' => 'content_deleted',
                    'actor_user_id' => null,
                    'detail' => ['reason' => $reason],
                    'created_at' => Carbon::now(),
                ]);
            }

            // Messages cascade; the proposals' conversation_id is set to NULL by the foreign key.
            $locked->delete();

            return true;
        });
    }

    /**
     * Delete every conversation whose last message is older than the retention period. Oldest first, in chunks; safe to run
     * repeatedly and to overlap with customer deletes. Optionally limited to one Workspace and/or Business.
     */
    public function purgeExpired(?int $workspaceId = null, ?int $businessId = null, int $chunk = 200): int
    {
        $cutoff = Carbon::now()->subDays(self::retentionDays());
        $deleted = 0;
        $skipped = [];

        do {
            $batch = AssistantConversation::query()
                ->whereRaw('COALESCE(last_message_at, created_at) < ?', [$cutoff])
                ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
                ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
                ->when($skipped !== [], fn ($q) => $q->whereNotIn('id', $skipped))
                ->orderBy('id')
                ->limit(max(1, $chunk))
                ->get();

            foreach ($batch as $conversation) {
                if ($this->delete($conversation, 'retention')) {
                    $deleted++;
                } else {
                    $skipped[] = $conversation->id;
                }
            }
        } while ($batch->count() === max(1, $chunk));

        return $deleted;
    }
}
