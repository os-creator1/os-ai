<?php

namespace App\Library\Assistant;

use App\Library\Assistant\Tools\ActionTool;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantMessage;
use Carbon\CarbonImmutable;
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
 *  - ONE EFFECT. The pending -> executing claim is a compare-and-set under a row lock, so a double click, a retry or a
 *    concurrent request cannot execute twice; a replay of a completed proposal returns its recorded result. The domain
 *    write and the recording of its result share one transaction, so a failure leaves nothing half-done and a failed
 *    proposal may be retried (bounded) without duplicating anything.
 *  - AUDIT. Every step appends an immutable row, and the result shown to the customer is read back from the domain
 *    mechanism, not assumed.
 */
final class AssistantActionService
{
    public const MAX_ATTEMPTS = 3;

    /** An `executing` claim older than this belonged to a request that died; its transaction was rolled back. */
    private const CLAIM_STALE_SECONDS = 120;

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

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string} */
    public function approve(AssistantContext $context, string $uid): array
    {
        $proposal = $this->find($context, $uid);

        if ($proposal === null) {
            abort(404);
        }

        $tool = $this->tools->action($proposal->tool_key);

        // Phase 1 — claim, under a lock, after revalidation.
        $claim = DB::transaction(function () use ($proposal, $context, $tool): array {
            $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            if ($locked->status === AssistantActionProposal::COMPLETED || $locked->status === AssistantActionProposal::HANDED_OFF) {
                return ['replay', $locked];
            }

            if (in_array($locked->status, [AssistantActionProposal::DECLINED, AssistantActionProposal::EXPIRED, AssistantActionProposal::STALE], true)) {
                return ['final', $locked];
            }

            if ($locked->status === AssistantActionProposal::EXECUTING && $locked->updated_at->gt(now()->subSeconds(self::CLAIM_STALE_SECONDS))) {
                return ['busy', $locked];
            }

            if ($locked->status === AssistantActionProposal::FAILED && $locked->attempts >= self::MAX_ATTEMPTS) {
                return ['final', $locked];
            }

            if ($locked->expires_at->isPast()) {
                $this->finish($locked, AssistantActionProposal::EXPIRED, 'expired', 'expired', $context);

                return ['final', $locked];
            }

            $reason = $this->revalidate($context, $locked, $tool);

            if ($reason !== null) {
                $this->finish($locked, AssistantActionProposal::STALE, $reason[0], 'revalidation_failed', $context, ['message' => $reason[1]]);
                $locked->forceFill(['result' => ['ok' => false, 'message' => $reason[1]]])->save();

                return ['final', $locked];
            }

            $locked->forceFill(['status' => AssistantActionProposal::EXECUTING, 'attempts' => $locked->attempts + 1, 'decided_at' => $locked->decided_at ?? now(), 'failure_code' => null])->save();
            $this->audit($locked, $locked->attempts > 1 ? 'retried' : 'approved', $context);

            return ['claimed', $locked];
        });

        [$kind, $locked] = $claim;

        if ($kind === 'replay') {
            return $this->view('replayed', $locked);
        }

        if ($kind === 'busy') {
            return $this->view('busy', $locked);
        }

        if ($kind === 'final') {
            return $this->view($locked->status, $locked);
        }

        // Phase 2 — execute through the domain mechanism, atomically with recording its result.
        $resolved = $locked->params + ['_proposal_uid' => $locked->uid];

        try {
            $outcome = DB::transaction(function () use ($tool, $context, $resolved, $locked): ActionOutcome {
                $outcome = $tool->execute($context, $resolved);
                $handoff = $tool->mode() === ActionTool::MODE_HANDOFF;

                $locked->forceFill([
                    'status' => $handoff ? AssistantActionProposal::HANDED_OFF : AssistantActionProposal::COMPLETED,
                    'executed_at' => now(),
                    'result' => ['ok' => true, 'message' => $outcome->message, 'url' => $outcome->url, 'details' => $outcome->details],
                ])->save();
                $this->audit($locked, $handoff ? 'handed_off' : 'executed', $context, ['message' => $outcome->message]);

                return $outcome;
            });
        } catch (AssistantActionRefused $e) {
            return $this->failed($locked, $context, $e->reason, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed($locked, $context, 'error', 'That could not be completed. Nothing was changed. You can try again.');
        }

        return $this->view($locked->fresh()->status, $locked->fresh());
    }

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string} */
    public function decline(AssistantContext $context, string $uid): array
    {
        $proposal = $this->find($context, $uid) ?? abort(404);

        DB::transaction(function () use ($proposal, $context): void {
            $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            if ($locked->status === AssistantActionProposal::PENDING || $locked->status === AssistantActionProposal::FAILED) {
                $this->finish($locked, AssistantActionProposal::DECLINED, 'declined', 'declined', $context);
            }
        });

        return $this->view($proposal->fresh()->status, $proposal->fresh());
    }

    /** @return array{0: string, 1: string}|null [code, customer message] when the proposal must not run */
    private function revalidate(AssistantContext $context, AssistantActionProposal $proposal, ?ActionTool $tool): ?array
    {
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

    private function failed(AssistantActionProposal $proposal, AssistantContext $context, string $code, string $message): array
    {
        DB::transaction(function () use ($proposal, $context, $code, $message): void {
            $locked = AssistantActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->first();
            $locked->forceFill(['status' => AssistantActionProposal::FAILED, 'failure_code' => $code, 'result' => ['ok' => false, 'message' => $message]])->save();
            $this->audit($locked, 'failed', $context, ['code' => $code, 'message' => $message]);
        });

        return $this->view(AssistantActionProposal::FAILED, $proposal->fresh());
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

    /** @return array{state: string, proposal: AssistantActionProposal, message: ?string, url: ?string} */
    private function view(string $state, AssistantActionProposal $proposal): array
    {
        return [
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
