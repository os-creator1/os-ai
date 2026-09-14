<?php

namespace App\Library\Conversations;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\ChatBoxMessage;
use Illuminate\Support\Facades\DB;

/**
 * PR #301 state-machine correction — the ONE authoritative mapping between
 * two things every prior round patched independently, at multiple seams,
 * which is exactly why each round kept discovering another ordering bug:
 *
 *   the customer-visible bubble  chat_box_messages.send_status
 *   the durable managed send     business_messaging_operations.status
 *
 * LOGICAL BUBBLE STATES this class knows about: sending, failed, ambiguous,
 * sent, delivered, delivery_failed. DURABLE OPERATION STATES it reads:
 * attempted, accepted, rejected, delivered, failed (MessagingOperationStatus).
 *
 * THE INVARIANTS EVERY CALLER RELIES ON THIS CLASS TO ENFORCE:
 *
 *   1. sent/delivered are never downgraded by a stale local refusal.
 *   2. ambiguous is never turned retryable merely because a later local
 *      replay/refusal occurs — protected exactly like a terminal success
 *      state (canApplyLocalRefusal()) until durable operation evidence
 *      conclusively resolves it (projectOperation()).
 *   3. an operation whose durable status is Failed never projects as Sent.
 *   4. an Accepted/Delivered operation never leads to another provider
 *      send — every caller here is DB-only (invariant 8); nothing in this
 *      class ever calls a provider or mints a new operation key.
 *   5. an Attempted (unresolved) operation is never guessed at: projectOperation()
 *      returns null for it, and reconcileSendingRow() never resolves it to
 *      a state it cannot yet prove. A FRESH claim (correction round 6,
 *      item 1) is left exactly as 'sending' — the provider call may
 *      genuinely still be executing, and Attempted is that request's
 *      NORMAL state throughout its own duration, not evidence anything
 *      went wrong. Only once the claim is STALE does an unresolved
 *      operation get represented on the bubble at all, moved to the
 *      honest 'ambiguous' state — see reconcileSendingRow()'s own
 *      docblock; that is not a guess about the outcome, it is labelling
 *      the outcome as unconfirmed.
 *   6. failed/delivery_failed are the only bubble states a deliberate
 *      Retry may ever start from (isRetryEligible()).
 *   7. sending is transient; reconcileSendingRow() is its one recovery
 *      path, usable both from retry()'s own claim transaction and from
 *      the conversation read path (correction round 5, item 3).
 *   8. every method on this class is DB-only. None of them ever performs
 *      a provider call, and none of them mints a new
 *      business_messaging_operations row — reconciliation only ever reads
 *      what already exists.
 */
final class ManagedSendStateMachine
{
    public const SENDING = 'sending';

    public const FAILED = 'failed';

    public const AMBIGUOUS = 'ambiguous';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'delivery_failed';

    /** Invariant 6 — the only two bubble states a deliberate Retry may ever start from. */
    private const RETRY_ELIGIBLE = [self::FAILED, self::DELIVERY_FAILED];

    /**
     * Invariants 1 & 2 — a LOCAL, pre-provider refusal (recordManualSendFailure())
     * may never move a bubble out of any of these; only durable operation
     * evidence, projected by projectOperation(), may resolve them further.
     */
    private const PROTECTED_FROM_LOCAL_REFUSAL = [self::SENT, self::DELIVERED, self::AMBIGUOUS];

    public static function isRetryEligible(?string $sendStatus): bool
    {
        return in_array($sendStatus, self::RETRY_ELIGIBLE, true);
    }

    public static function isTerminalSuccess(?string $sendStatus): bool
    {
        return in_array($sendStatus, [self::SENT, self::DELIVERED], true);
    }

    /**
     * Whether a LOCAL, pre-provider refusal (never itself confirmed against
     * the provider) is allowed to write onto a bubble currently in
     * $currentStatus. False for sent/delivered/ambiguous (invariants 1 & 2)
     * — a stale replay of an old local refusal must never downgrade any of
     * them, no matter how the request that produced it got re-triggered.
     */
    public static function canApplyLocalRefusal(?string $currentStatus): bool
    {
        return ! in_array($currentStatus, self::PROTECTED_FROM_LOCAL_REFUSAL, true);
    }

    /**
     * The ONE mapping from a durable business_messaging_operations row to
     * the bubble state it authorizes (invariants 3 & 5). Every caller that
     * writes a bubble state FROM operation evidence — recordManagedOutbound()'s
     * attach step, and reconcileSendingRow() below — goes through this, so
     * there is exactly one place that decision is made.
     *
     * Returns null when the operation is UNRESOLVED: no row at all, or a
     * row still 'attempted' (the provider call may or may not have gone
     * out — never guessed at, at any age). The caller decides the
     * context-appropriate fallback for that case; this method never does.
     */
    public static function projectOperation(?object $operation): ?ManagedSendProjection
    {
        if ($operation === null) {
            return null;
        }

        $status = MessagingOperationStatus::tryFrom((string) $operation->status);

        return match ($status) {
            MessagingOperationStatus::Accepted => new ManagedSendProjection(self::SENT, null),
            MessagingOperationStatus::Delivered => new ManagedSendProjection(self::DELIVERED, null),
            MessagingOperationStatus::Failed => new ManagedSendProjection(self::DELIVERY_FAILED, ConversationSendFailureReason::DeliveryFailed->value),
            MessagingOperationStatus::Rejected => self::projectRejected($operation),
            MessagingOperationStatus::Attempted, null => null,
        };
    }

    private static function projectRejected(object $operation): ManagedSendProjection
    {
        $reason = $operation->error_category !== null
            ? ConversationSendFailureReason::fromProviderErrorCategory(ProviderErrorCategory::tryFrom((string) $operation->error_category))
            : ConversationSendFailureReason::SendFailed;

        return new ManagedSendProjection($reason->bubbleStatus(), $reason->value);
    }

    /**
     * The deterministic operation key retry()'s own claim transaction mints
     * for the attempt a 'sending' row is currently claiming — conversation-
     * scoped (correction round 4, item 3). retry_count on the row IS the
     * count that specific claim already incremented to, so this is always
     * derivable from the row alone; nothing extra needs to be stored.
     */
    public static function inFlightOperationKeyFor(ChatBoxMessage $message): string
    {
        return 'conversation:' . $message->box_id . ':' . $message->send_uid . ':retry:' . $message->retry_count;
    }

    /**
     * Reconciles ONE row the caller has ALREADY locked (retry()'s own claim
     * transaction, or the read-path reconciliation below) that is currently
     * 'sending', from durable local state alone (invariant 8 — this never
     * performs a provider call and never mints a new operation key; a
     * genuinely fresh in-flight claim is simply left as 'sending').
     *
     * A row not currently 'sending' is returned untouched — reconciliation
     * only ever applies to the one transient state that needs it
     * (invariant 7).
     *
     *   operation Accepted/Delivered  -> sent/delivered, never resent.
     *   operation Failed              -> delivery_failed.
     *   operation Rejected            -> failed, with the same customer-safe
     *                                    reason attemptManagedSend() itself
     *                                    would have recorded (including
     *                                    Ambiguous, for a Retryable/Unknown
     *                                    category — correction round 4,
     *                                    item 1).
     *   operation Attempted           -> 'ambiguous'. This is NOT a guess at
     *                                    the outcome (invariant 5) — it is
     *                                    the honest label for "unconfirmed",
     *                                    the same bubble state a live
     *                                    ambiguous send already gets. The
     *                                    alternative — leaving it at
     *                                    'sending' forever with no visible
     *                                    indication anything is wrong — is
     *                                    exactly the stranded-bubble problem
     *                                    this correction closes.
     *   no operation row, STALE claim -> failed/SendFailed: provider dispatch
     *                                    demonstrably never even obtained an
     *                                    operation row for this attempt, so
     *                                    a fresh Retry is safe to offer.
     *   no operation row, FRESH claim -> untouched. A genuinely live request
     *                                    may still be moments from creating
     *                                    that row; never released out from
     *                                    under it.
     */
    public static function reconcileSendingRow(ChatBoxMessage $locked, int $businessId): ChatBoxMessage
    {
        if ($locked->send_status !== self::SENDING) {
            return $locked;
        }

        $operationKey = self::inFlightOperationKeyFor($locked);

        $operation = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('business_id', $businessId)
            ->where('operation_key', $operationKey)
            ->first();

        if ($operation !== null) {
            $projection = self::projectOperation($operation);

            if ($projection === null) {
                // Attempted, or an unrecognised status — genuinely
                // unresolved AT THE OPERATION LEVEL: the provider call may
                // or may not have gone out, and this is never guessed at
                // (invariant 5).
                //
                // Correction round 6, item 1 — a FRESH claim is left
                // exactly as 'sending': the provider HTTP call this claim
                // started may still genuinely be executing right now (an
                // Attempted row is written BEFORE the adapter call — see
                // ManagedMessageDispatcher::recordAttempt() — so "Attempted"
                // is the row's NORMAL state for the entire duration of a
                // live request, not evidence of anything having gone
                // wrong). Reconciling it to 'ambiguous' this early would be
                // a genuine regression: a conclusive result that arrives a
                // moment later (the provider call returning, however it
                // resolves) would then try to record onto an
                // already-protected 'ambiguous' bubble and be refused by
                // canApplyLocalRefusal() — stranding a message that is
                // actually KNOWN to have failed behind a state that claims
                // "we don't know".
                //
                // Only once the claim is STALE (comfortably longer than any
                // real synchronous request takes to either finish or
                // crash) does an unresolved operation get represented on
                // the bubble at all — moved to the honest 'ambiguous'
                // state, exactly what a live ambiguous send already shows:
                // never a guess about the eventual outcome, just an honest
                // label that it is currently unconfirmed. The alternative
                // (leaving a STALE claim at 'sending' forever) is exactly
                // the stranded-bubble problem this correction exists to
                // close.
                if (! self::isClaimStale($locked)) {
                    return $locked;
                }

                $locked->update([
                    'business_messaging_operation_id' => (int) $operation->id,
                    'send_status' => self::AMBIGUOUS,
                    'send_failure_reason' => ConversationSendFailureReason::Ambiguous->value,
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'business_messaging_operation_id' => (int) $operation->id,
                'send_status' => $projection->sendStatus,
                'send_failure_reason' => $projection->failureReason,
            ]);

            return $locked->fresh();
        }

        // No operation row at all — a bounded LIVENESS check, never a
        // resolution of an ambiguous provider outcome.
        if (! self::isClaimStale($locked)) {
            return $locked;
        }

        $locked->update([
            'send_status' => self::FAILED,
            'send_failure_reason' => ConversationSendFailureReason::SendFailed->value,
        ]);

        return $locked->fresh();
    }

    /**
     * Two minutes is comfortably longer than any real synchronous request/
     * provider-HTTP-call takes to either finish or crash — a bounded
     * LIVENESS check on the CLAIM itself, never a resolution of an
     * ambiguous provider outcome (invariant 5). Shared by both branches of
     * reconcileSendingRow() that need to distinguish "this could still be a
     * live request" from "this claim is definitely dead".
     */
    private static function isClaimStale(ChatBoxMessage $locked): bool
    {
        $claimedAt = $locked->send_claimed_at;

        return $claimedAt === null || $claimedAt->lt(now()->subMinutes(2));
    }
}
