<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentRefunded;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessStripeConnection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §5.9 / §7.4 / §8.3 — the shared idempotent
 * finalizer for refunds, mirroring PaymentFinalizer exactly.
 *
 * Every path that learns a refund outcome comes through here: the refund
 * request's own provider response and the verified refund webhook. One
 * finalizer is what makes replay safety structural rather than per-caller.
 *
 * LOCK ORDER (§7.0). Applying a refund may have to change the SCHEDULE ITEM
 * (tier 2) when the refund completes a full return, so this path restarts
 * from tier 1 and acquires document -> schedule item -> payment -> refund in
 * the canonical order. That is exactly what §7.0 prescribes: refund-only
 * finalization may lock 3 -> 4 alone, but "if it needs the document, it must
 * restart from tier 1". The ADMISSION step (§7.4, in PaymentManager) is the
 * one that locks payment -> refunds and never takes the document lock.
 *
 * §5.9'S PARTIAL-REFUND RULE, precisely:
 *   - the schedule item stays `paid` while cumulative SUCCEEDED refunds are
 *     LESS than the captured amount;
 *   - it becomes `refunded` ONLY when they equal it — never on a first
 *     partial refund;
 *   - the document remains `paid` throughout. A refund never moves a terminal
 *     document backward.
 *
 * NO NETWORK HERE, so holding locks is safe.
 */
final class RefundFinalizer
{
    public const APPLIED = 'applied';
    public const IGNORED_ALREADY_TERMINAL = 'ignored_already_terminal';
    public const ACCOUNT_MISMATCH = 'account_mismatch';
    public const AMOUNT_MISMATCH = 'amount_mismatch';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const REFUND_MISMATCH = 'refund_mismatch';

    /**
     * @return string one of the disposition constants above
     */
    public function apply(BusinessDocumentRefund $refund, RefundSnapshot $snapshot): string
    {
        $payment = BusinessDocumentPayment::query()->find($refund->business_document_payment_id);

        if ($payment === null) {
            return self::REFUND_MISMATCH;
        }

        $mismatch = $this->crossCheck($refund, $payment, $snapshot);

        if ($mismatch !== null) {
            return $mismatch;
        }

        return DB::transaction(function () use ($refund, $payment, $snapshot) {
            // ---- §7.0 canonical order, restarted from tier 1 --------------
            $document = BusinessDocument::query()
                ->whereKey($payment->business_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = BusinessDocumentPaymentScheduleItem::query()
                ->whereKey($payment->schedule_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = BusinessDocumentPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRefund = BusinessDocumentRefund::query()
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A settled refund never moves again; a replay is a no-op.
            if ($lockedRefund->status !== BusinessDocumentRefundStatus::Pending) {
                return self::IGNORED_ALREADY_TERMINAL;
            }

            $lockedRefund->forceFill([
                'status' => $snapshot->status->value,
                'provider_refund_id' => $snapshot->providerRefundId,
                'succeeded_at' => $snapshot->status === BusinessDocumentRefundStatus::Succeeded ? now() : null,
            ])->save();

            if ($snapshot->status !== BusinessDocumentRefundStatus::Succeeded) {
                // A failed refund RELEASES its reserved capacity simply by
                // ceasing to be pending (§8.7) — nothing else to undo.
                return self::APPLIED;
            }

            // §5.9 — only a FULL cumulative return marks the item refunded.
            $cumulative = (int) BusinessDocumentRefund::query()
                ->where('business_document_payment_id', $lockedPayment->id)
                ->where('status', BusinessDocumentRefundStatus::Succeeded->value)
                ->sum('amount_minor');

            if ($cumulative >= (int) $lockedPayment->amount_minor
                && $item->status === PaymentScheduleItemStatus::Paid) {
                $item->forceFill(['status' => PaymentScheduleItemStatus::Refunded->value])->save();
            }

            // The DOCUMENT is deliberately not touched. It was locked only to
            // honour the canonical order; §5.9 keeps it `paid` after any
            // refund, and a terminal document never moves backward (§8.3).

            DB::afterCommit(fn () => DocumentRefunded::dispatch(
                (int) $document->id,
                (int) $lockedPayment->id,
                (int) $lockedRefund->id,
            ));

            return self::APPLIED;
        });
    }

    /**
     * §8.3's cross-checks, in refund form. Fail-closed throughout.
     */
    private function crossCheck(BusinessDocumentRefund $refund, BusinessDocumentPayment $payment, RefundSnapshot $snapshot): ?string
    {
        // The HISTORICAL connection recorded on the PAYMENT (§5.7) — never
        // the Business's current one.
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

        if ($connection === null || (string) $connection->stripe_account_id !== $snapshot->connectedAccountId) {
            return self::ACCOUNT_MISMATCH;
        }

        if ($refund->provider_refund_id !== null
            && (string) $refund->provider_refund_id !== $snapshot->providerRefundId) {
            return self::REFUND_MISMATCH;
        }

        if ((int) $refund->amount_minor !== $snapshot->amountMinor) {
            return self::AMOUNT_MISMATCH;
        }

        if (mb_strtoupper((string) $payment->currency_code) !== mb_strtoupper($snapshot->currencyCode)) {
            return self::CURRENCY_MISMATCH;
        }

        return null;
    }
}
