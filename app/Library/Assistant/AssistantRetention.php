<?php

namespace App\Library\Assistant;

use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * COORDINATION. A deletion first takes the conversation's TURN LOCK — the same owner-token lock a customer's message holds for
 * the whole of its (provider-calling) turn — so it can never remove rows underneath a running turn, and no database row lock is
 * held while a provider is being called. A customer's delete waits a few seconds for it and then answers "busy" (retryable);
 * the scheduled sweep does not wait at all and simply leaves a busy conversation for its next run. Inside the lock the
 * proposals are taken with SKIP LOCKED: one that an executor holds (an approved action mid-flight, even past its lease) means
 * "busy", never a lock-wait. A turn that nevertheless outlived its own lease finds the conversation gone and writes nothing.
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

    /** The one lock a turn holds while it works on a conversation, and a deletion must hold to remove it. */
    public static function turnLock(int $conversationId, int $seconds): Lock
    {
        return Cache::lock('assistant:conversation:' . $conversationId, $seconds);
    }

    /**
     * Take a lock, waiting at most `$seconds` (0 = try once). The wait is measured on the real monotonic clock, never the
     * application clock: a frozen or adjusted clock must not be able to stall a request forever.
     */
    public static function acquireWithin(Lock $lock, int $seconds): bool
    {
        $deadline = hrtime(true) + max(0, $seconds) * 1_000_000_000;

        while (! $lock->get()) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(25_000);
        }

        return true;
    }

    /**
     * @param  int  $waitSeconds  how long to wait for a running turn to finish (0 = do not wait)
     * @return bool false when a turn or an approved action is still running (try again later); true when deleted or already gone
     */
    public function delete(AssistantConversation $conversation, string $reason, int $waitSeconds = 0): bool
    {
        $lock = self::turnLock((int) $conversation->id, 30);

        if (! self::acquireWithin($lock, $waitSeconds)) {
            return false;
        }

        try {
            return $this->deleteLocked($conversation, $reason);
        } finally {
            $lock->release();
        }
    }

    private function deleteLocked(AssistantConversation $conversation, string $reason): bool
    {
        return DB::transaction(function () use ($conversation, $reason): bool {
            $locked = AssistantConversation::query()->whereKey($conversation->id)->lock('for update skip locked')->first();

            if ($locked === null) {
                // Gone already (idempotent), or its row is held by someone else right now (busy).
                return ! AssistantConversation::query()->whereKey($conversation->id)->exists();
            }

            $existing = AssistantActionProposal::query()->where('conversation_id', $locked->id)->pluck('id');
            $proposals = AssistantActionProposal::query()->where('conversation_id', $locked->id)->lock('for update skip locked')->get();

            // A proposal an executor holds is not ours to touch, and never worth a lock wait.
            if ($proposals->count() !== $existing->count()) {
                return false;
            }

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
                if ($this->delete($conversation, 'retention', 0)) {
                    $deleted++;
                } else {
                    $skipped[] = $conversation->id;
                }
            }
        } while ($batch->count() === max(1, $chunk));

        return $deleted;
    }
}
