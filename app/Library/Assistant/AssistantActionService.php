<?php

namespace App\Library\Assistant;

use App\Library\Assistant\Tools\ActionTool;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Approval and execution of a prepared action. The model is not involved: this is the customer pressing Confirm.
 *
 * What makes it safe:
 *  - SCOPE. A proposal is found only by uid AND workspace AND business AND the approving user. Another customer, another
 *    Business or another Workspace gets the same "not found" as a uid that never existed.
 *  - REVALIDATION immediately before execution, from this request's freshly resolved context: the tool must still be
 *    available to this actor (permission, entitlement, view-as, Location reach), the proposal must not have expired,
 *    and the live state the preview was computed against must still hold (the tool's fingerprint). Otherwise the
 *    proposal goes `stale` and nothing runs.
 *  - ONE EFFECT, by a FENCED LEASE. A claim takes a fresh `lease_token`, bumps `fence` and sets `lease_expires_at`, all
 *    under the proposal's row lock. The executor's own transaction starts by re-locking the row and verifying token,
 *    fence and `executing` status; if any differ it throws AssistantLeaseLost and writes NOTHING. That transaction holds
 *    the row lock until it commits, and the domain write and the recording of its result share it. So: a live executor
 *    can never be superseded (a second claimant blocks on the lock and is told "busy"); a dead one's transaction died
 *    with its connection, and its expired lease may then be re-claimed; and a superseded executor that wakes late finds
 *    a different token and aborts before touching anything. An expired timestamp alone never permits a second effect.
 *  - AUDIT. Every step appends an immutable row, and the result shown to the customer is read back from the domain
 *    mechanism, not assumed.
 */
final class AssistantActionService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly AssistantToolRegistry $tools)
    {
    }

    public function find(AssistantContext $context, string $uid): ?AssistantActionProposal
    {
        return AssistantActionProposal::query()
            ->where('uid', $uid)
            ->where('workspace_id', $context->workspace->id)
            ->where('business_id', $context->business->id)
            ->where('user_id', $context->user->id)
            ->first();
    }

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string, transitioned: bool} */
    public function approve(AssistantContext $context, string $uid): array
    {
        $proposal = $this->find($context, $uid);

        if ($proposal === null) {
            abort(404);
        }

        $claim = $this->claimForExecution($context, $proposal);

        if ($claim['kind'] !== 'claimed') {
            return $this->view($claim['kind'] === 'replay' ? 'replayed' : ($claim['kind'] === 'busy' ? 'busy' : $claim['proposal']->status), $claim['proposal'], $claim['transitioned']);
        }

        return $this->executeClaimed($context, $claim['proposal'], $claim['token'], $claim['fence']);
    }

    /**
     * Phase 1 — revalidate, then take (or refuse) the lease. Public so the protocol can be exercised step by step.
     *
     * @return array{kind: string, proposal: AssistantActionProposal, token: ?string, fence: ?int, transitioned: bool}
     */
    public function claimForExecution(AssistantContext $context, AssistantActionProposal $proposal): array
    {
        $tool = $this->tools->action($proposal->tool_key);

        try {
            return DB::transaction(function () use ($proposal, $context, $tool): array {
                $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();
                $out = fn (string $kind, bool $changed = false, ?string $token = null, ?int $fence = null): array => ['kind' => $kind, 'proposal' => $locked, 'token' => $token, 'fence' => $fence, 'transitioned' => $changed];

                if ($locked->status === AssistantActionProposal::COMPLETED || $locked->status === AssistantActionProposal::HANDED_OFF) {
                    return $out('replay');
                }

                if (in_array($locked->status, [AssistantActionProposal::DECLINED, AssistantActionProposal::EXPIRED, AssistantActionProposal::STALE], true)) {
                    return $out('final');
                }

                // A live lease is never taken over. An EXPIRED lease may be, but only because we hold the row lock here:
                // a live executor would still be holding it inside its own transaction.
                if ($locked->status === AssistantActionProposal::EXECUTING && $locked->lease_expires_at !== null && $locked->lease_expires_at->isFuture()) {
                    return $out('busy');
                }

                if ($locked->status === AssistantActionProposal::FAILED && $locked->attempts >= self::MAX_ATTEMPTS) {
                    return $out('final');
                }

                if ($locked->expires_at->isPast()) {
                    $this->finish($locked, AssistantActionProposal::EXPIRED, 'expired', 'expired', $context);

                    return $out('final', true);
                }

                $reason = $this->revalidate($context, $locked, $tool);

                if ($reason !== null) {
                    $this->finish($locked, AssistantActionProposal::STALE, $reason[0], 'revalidation_failed', $context, ['message' => $reason[1]]);
                    $locked->forceFill(['result' => ['ok' => false, 'message' => $reason[1]]])->save();

                    return $out('final', true);
                }

                $token = (string) Str::uuid();
                $fence = (int) $locked->fence + 1;
                $locked->forceFill([
                    'status' => AssistantActionProposal::EXECUTING,
                    'attempts' => $locked->attempts + 1,
                    'decided_at' => $locked->decided_at ?? now(),
                    'failure_code' => null,
                    'lease_token' => $token,
                    'fence' => $fence,
                    'lease_expires_at' => now()->addSeconds(max(5, (int) config('assistant.action_lease_seconds'))),
                ])->save();
                $this->audit($locked, $locked->attempts > 1 ? 'retried' : 'approved', $context, ['fence' => $fence]);

                return $out('claimed', true, $token, $fence);
            });
        } catch (QueryException $e) {
            // The proposal's row is locked by an executor that is running right now (lock wait timeout / deadlock):
            // that is exactly "busy", never a reason to proceed.
            if ($this->isLockContention($e)) {
                return ['kind' => 'busy', 'proposal' => $proposal->fresh() ?? $proposal, 'token' => null, 'fence' => null, 'transitioned' => false];
            }

            throw $e;
        }
    }

    /**
     * Phase 2 — execute through the domain mechanism, atomically with recording its result, only while the lease is still
     * ours. Public for the same reason as claimForExecution().
     *
     * @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string, transitioned: bool}
     */
    public function executeClaimed(AssistantContext $context, AssistantActionProposal $claimed, string $token, int $fence): array
    {
        $tool = $this->tools->action($claimed->tool_key);

        try {
            DB::transaction(function () use ($tool, $context, $claimed, $token, $fence): void {
                // FENCE: re-lock and verify before anything else. Superseded => throw, write nothing.
                $row = AssistantActionProposal::query()->whereKey($claimed->id)->lockForUpdate()->first();

                if ($row === null || $row->status !== AssistantActionProposal::EXECUTING || $row->lease_token !== $token || (int) $row->fence !== $fence) {
                    throw new AssistantLeaseLost('Execution lease lost.');
                }

                $row->forceFill(['lease_expires_at' => now()->addSeconds(max(5, (int) config('assistant.action_lease_seconds')))])->save();

                $outcome = $tool->execute($context, $row->params + ['_proposal_uid' => $row->uid]);
                $handoff = $tool->mode() === ActionTool::MODE_HANDOFF;

                $row->forceFill([
                    'status' => $handoff ? AssistantActionProposal::HANDED_OFF : AssistantActionProposal::COMPLETED,
                    'executed_at' => now(),
                    'lease_token' => null,
                    'result' => ['ok' => true, 'message' => $outcome->message, 'url' => $outcome->url, 'details' => $outcome->details],
                ])->save();
                $this->audit($row, $handoff ? 'handed_off' : 'executed', $context, ['message' => $outcome->message, 'fence' => $fence]);
            });
        } catch (AssistantLeaseLost) {
            $fresh = $claimed->fresh() ?? $claimed;

            return $this->view($fresh->status === AssistantActionProposal::EXECUTING ? 'busy' : $fresh->status, $fresh, false);
        } catch (AssistantActionRefused $e) {
            return $this->failed($claimed, $context, $token, $fence, $e->reason, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed($claimed, $context, $token, $fence, 'error', 'That could not be completed. Nothing was changed. You can try again.');
        }

        $fresh = $claimed->fresh();

        return $this->view($fresh->status, $fresh, true);
    }

    private function isLockContention(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[1] ?? ''), ['1205', '1213'], true) || str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), 'Deadlock');
    }

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string, transitioned: bool} */
    public function decline(AssistantContext $context, string $uid): array
    {
        $proposal = $this->find($context, $uid) ?? abort(404);
        $changed = false;

        DB::transaction(function () use ($proposal, $context, &$changed): void {
            $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            if ($locked->status === AssistantActionProposal::PENDING || ($locked->status === AssistantActionProposal::FAILED)) {
                $this->finish($locked, AssistantActionProposal::DECLINED, 'declined', 'declined', $context);
                $changed = true;
            }
        });

        return $this->view($proposal->fresh()->status, $proposal->fresh(), $changed);
    }

    /** @return array{0: string, 1: string}|null [code, customer message] when the proposal must not run */
    private function revalidate(AssistantContext $context, AssistantActionProposal $proposal, ?ActionTool $tool): ?array
    {
        // Writes are never made on a client's behalf while an agency is viewing as them. Proposal creation already refuses it
        // (AssistantToolRegistry::availableActions); the approval route is only BusinessScoped, so a proposal prepared with the
        // actor's own direct access must not be carried into a view-as session. Checked first, for every tool, before anything
        // about the proposal is looked at, and fails closed: nothing is claimed, executed or revealed.
        if ($context->viewingAsClient()) {
            return ['view_as_client', "Ask MotionGrove can't make changes while you're viewing as a client, so nothing was done. Switch back to your own account and ask again."];
        }

        if ($tool === null || ! $tool->available($context)) {
            return ['authorization_changed', "You no longer have access to do this, so nothing was changed."];
        }

        try {
            $current = $tool->fingerprint($context, $proposal->params);
        } catch (AssistantActionRefused $e) {
            return [$e->reason, $e->getMessage()];
        }

        if (! hash_equals($proposal->state_fingerprint, $current)) {
            return ['state_changed', 'Something changed since this was prepared, so nothing was done. Ask me again and I will prepare it from the current details.'];
        }

        return null;
    }

    private function failed(AssistantActionProposal $proposal, AssistantContext $context, string $token, int $fence, string $code, string $message): array
    {
        $changed = false;

        DB::transaction(function () use ($proposal, $context, $token, $fence, $code, $message, &$changed): void {
            $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            // Only the current lease holder may record a failure; a superseded executor records nothing.
            if ($locked->status !== AssistantActionProposal::EXECUTING || $locked->lease_token !== $token || (int) $locked->fence !== $fence) {
                return;
            }

            $locked->forceFill(['status' => AssistantActionProposal::FAILED, 'failure_code' => $code, 'lease_token' => null, 'result' => ['ok' => false, 'message' => $message]])->save();
            $this->audit($locked, 'failed', $context, ['code' => $code, 'message' => $message, 'fence' => $fence]);
            $changed = true;
        });

        return $this->view($proposal->fresh()->status, $proposal->fresh(), $changed);
    }

    /** @param array<string, mixed> $detail */
    private function finish(AssistantActionProposal $proposal, string $status, string $code, string $event, AssistantContext $context, array $detail = []): void
    {
        $proposal->forceFill(['status' => $status, 'failure_code' => $status === AssistantActionProposal::DECLINED ? null : $code, 'decided_at' => now()])->save();
        $this->audit($proposal, $event, $context, $detail + ['code' => $code]);
    }

    /** @param array<string, mixed> $detail */
    private function audit(AssistantActionProposal $proposal, string $event, AssistantContext $context, array $detail = []): void
    {
        AssistantActionAudit::create([
            'proposal_id' => $proposal->id,
            'event' => $event,
            'actor_user_id' => $context->user->id,
            'detail' => $detail === [] ? null : $detail,
            'created_at' => now(),
        ]);
    }

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string, transitioned: bool} */
    private function view(string $state, AssistantActionProposal $proposal, bool $transitioned = false): array
    {
        return [
            'transitioned' => $transitioned,
            'state' => $state,
            'proposal' => $proposal,
            'message' => $proposal->result['message'] ?? null,
            'url' => $proposal->result['url'] ?? null,
        ];
    }

    public static function newUid(): string
    {
        return (string) Str::uuid();
    }
}
