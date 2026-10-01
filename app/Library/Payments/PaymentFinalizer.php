<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessStripeConnection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §8.2 / §8.3 — THE shared idempotent finalizer.
 *
 * Every path that learns a payment outcome comes through here: the
 * payment-start response, the verified webhook, and (Sub-slice F) the
 * reconciliation sweep. One finalizer is what makes replay safety a property
 * of the system rather than of each caller.
 *
 * CROSS-CHECKS BEFORE ANY MUTATION (§8.3), all fail-closed with a reason code
 * and never a best-effort guess:
 *   - the connected account must match THE CONNECTION RECORDED ON THE ROW —
 *     deliberately not the Business's current connection, so a webhook for a
 *     now-disconnected historical account still finalizes its own older
 *     payment (§5.7);
 *   - `metadata.app_operation_id` must equal the persisted
 *     `local_idempotency_key`;
 *   - the provider intent id, amount and currency must match.
 *
 * LOCK ORDER (§7.0), always: document -> schedule item -> payment. The payment
 * is resolved UNLOCKED first, then everything is re-read under locks in that
 * order before a single field changes.
 *
 * TWO TRUTHS, KEPT APART (Payments & Invoices V1 completion):
 *
 *   - THE PAYMENT ROW is the ledger of what the provider did with THIS
 *     attempt. It records what the provider confirmed, whatever state the
 *     document is in — a void or expired document must never strand money the
 *     provider really captured, because a payment that is not recorded cannot
 *     be refunded and cannot be seen by the Business.
 *   - THE DOCUMENT AND ITS SCHEDULE move only while the document is still
 *     live. A late capture against a terminal document is RECORDED (so it is
 *     visible and refundable) but never reopens, pays or otherwise moves that
 *     document, and never emits `DocumentFullyPaid`.
 *
 * WHICH OBSERVATIONS MAY MOVE A SETTLED ROW. `succeeded` and `canceled` are
 * final at the provider and never move. `failed` is NOT final at the provider:
 * `payment_intent.payment_failed` leaves the PaymentIntent alive in
 * `requires_payment_method`, and the Payment Element lets the same customer
 * retry the same intent and succeed. A provider-confirmed `succeeded` on a
 * `failed` row is therefore applied — the only transition out of `failed`.
 * Every other observation of a `failed` row is a no-op.
 *
 * NO NETWORK HERE. This class only applies an outcome someone else already
 * obtained, so it can safely hold locks.
 */
final class PaymentFinalizer
{
    public const APPLIED = 'applied';
    /**
     * The provider confirmed a capture for a document that was already void,
     * expired or paid. The money is recorded on the payment row (so it can be
     * refunded) and the document is left exactly as it was.
     */
    public const APPLIED_DOCUMENT_TERMINAL = 'recorded_against_terminal_document';
    public const IGNORED_ALREADY_TERMINAL = 'ignored_already_terminal';
    public const IGNORED_NO_CHANGE = 'ignored_no_change';
    public const IGNORED_DOCUMENT_TERMINAL = 'ignored_document_terminal';
    public const ACCOUNT_MISMATCH = 'account_mismatch';
    public const OPERATION_ID_MISMATCH = 'operation_id_mismatch';
    public const AMOUNT_MISMATCH = 'amount_mismatch';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const INTENT_MISMATCH = 'intent_mismatch';

    /**
     * Applies one provider observation to one local payment row.
     *
     * @return string one of the disposition constants above
     */
    public function apply(BusinessDocumentPayment $payment, PaymentIntentSnapshot $snapshot): string
    {
        // The secret must not survive into anything this method touches.
        $snapshot = $snapshot->withoutClientSecret();

        $mismatch = $this->crossCheck($payment, $snapshot);

        if ($mismatch !== null) {
            return $mismatch;
        }

        return DB::transaction(function () use ($payment, $snapshot) {
            // ---- §7.0 canonical order: document (1) -----------------------
            $document = BusinessDocument::query()
                ->whereKey($payment->business_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            // ---- schedule item (2) ---------------------------------------
            $item = BusinessDocumentPaymentScheduleItem::query()
                ->whereKey($payment->schedule_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            // ---- payment (3) ---------------------------------------------
            $locked = BusinessDocumentPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $succeeded = $snapshot->status === BusinessDocumentPaymentStatus::Succeeded;

            if ($this->isSettled($locked->status, $succeeded)) {
                // §8.3 — a replay of an already-settled payment changes
                // nothing, and is not an error.
                return $locked->status === $snapshot->status
                    ? self::IGNORED_NO_CHANGE
                    : self::IGNORED_ALREADY_TERMINAL;
            }

            $documentTerminal = in_array($document->status, [DocumentStatus::Paid, DocumentStatus::Void, DocumentStatus::Expired], true);

            // The ledger row records what the provider did, regardless of the
            // document's state (see the class comment).
            $locked->forceFill([
                'status' => $snapshot->status->value,
                'provider_payment_intent_id' => $snapshot->providerPaymentIntentId,
                'provider_charge_id' => $snapshot->providerChargeId ?? $locked->provider_charge_id,
                'failure_code' => $succeeded
                    ? null
                    : ($snapshot->failureCode === null ? null : mb_substr($snapshot->failureCode, 0, 64)),
                'succeeded_at' => $succeeded ? now() : null,
            ])->save();

            if (! $succeeded) {
                if ($snapshot->status === BusinessDocumentPaymentStatus::Failed && ! $documentTerminal) {
                    DB::afterCommit(function () use ($locked, $document) {
                        DocumentPaymentFailed::dispatch(
                            (int) $document->id,
                            (int) $locked->id,
                            (int) $document->business_id,
                            (int) $document->business_location_id,
                            (int) $document->contact_id,
                        );
                    });
                }

                return self::APPLIED;
            }

            // ---- success advances the schedule, then maybe the document ---
            // A TERMINAL document is never advanced. Its schedule is inert
            // (void pends were voided; a paid document has nothing pending)
            // and the capture is recorded above purely as money to refund.
            $allSettled = false;

            if (! $documentTerminal) {
                if ($item->status === PaymentScheduleItemStatus::Pending) {
                    $item->forceFill([
                        'status' => PaymentScheduleItemStatus::Paid->value,
                        'paid_at' => now(),
                    ])->save();
                }

                // The document becomes `paid` off the CURRENT version's
                // schedule only. A charge that settles against a
                // since-superseded version is still recorded — the money is
                // real and must never be lost — but it can never declare the
                // document paid, because §5.9 makes the current version's
                // schedule the payable one.
                $isCurrentVersion = (int) $item->business_document_version_id === (int) $document->current_version_id;

                $allSettled = $isCurrentVersion && ! BusinessDocumentPaymentScheduleItem::query()
                    ->where('business_document_version_id', $document->current_version_id)
                    ->where('status', PaymentScheduleItemStatus::Pending->value)
                    ->exists();

                // A DEPOSIT succeeding must not mark the whole document paid
                // while the balance is still outstanding.
                if ($allSettled) {
                    $document->forceFill([
                        'status' => DocumentStatus::Paid->value,
                        'paid_at' => now(),
                    ])->save();
                }
            }

            // Blueprint §9 — a payment NEVER advances an Opportunity stage.
            // Stated here because this is the one place that would be
            // tempted to; a test asserts the stage is unchanged.

            DB::afterCommit(function () use ($locked, $document, $allSettled) {
                DocumentPaymentSucceeded::dispatch(
                    (int) $document->id,
                    (int) $locked->id,
                    (int) $document->business_id,
                    (int) $document->business_location_id,
                    (int) $document->contact_id,
                );

                if ($allSettled) {
                    DocumentFullyPaid::dispatch(
                        (int) $document->id,
                        (int) $document->business_id,
                        (int) $document->business_location_id,
                        (int) $document->contact_id,
                    );
                }
            });

            return $documentTerminal ? self::APPLIED_DOCUMENT_TERMINAL : self::APPLIED;
        });
    }

    /**
     * §8.3's cross-check list. Every one is fail-closed: a mismatch is
     * reported, never reconciled by guessing which side is right.
     */
    private function crossCheck(BusinessDocumentPayment $payment, PaymentIntentSnapshot $snapshot): ?string
    {
        // The account is the one recorded ON THE ROW (§5.7) — a Business that
        // has since disconnected and reconnected elsewhere must still be able
        // to finalize its older payment.
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

        if ($connection === null || (string) $connection->stripe_account_id !== $snapshot->connectedAccountId) {
            return self::ACCOUNT_MISMATCH;
        }

        if ($snapshot->operationId !== null && $snapshot->operationId !== (string) $payment->local_idempotency_key) {
            return self::OPERATION_ID_MISMATCH;
        }

        if ($payment->provider_payment_intent_id !== null
            && (string) $payment->provider_payment_intent_id !== $snapshot->providerPaymentIntentId) {
            return self::INTENT_MISMATCH;
        }

        if ((int) $payment->amount_minor !== $snapshot->amountMinor) {
            return self::AMOUNT_MISMATCH;
        }

        if (mb_strtoupper((string) $payment->currency_code) !== mb_strtoupper($snapshot->currencyCode)) {
            return self::CURRENCY_MISMATCH;
        }

        return null;
    }

    /**
     * Whether this observation may NOT move the row. `succeeded` and
     * `canceled` are final at the provider; `failed` yields only to a
     * provider-confirmed `succeeded` (see the class comment).
     */
    private function isSettled(BusinessDocumentPaymentStatus $status, bool $observationSucceeded): bool
    {
        return match ($status) {
            BusinessDocumentPaymentStatus::Succeeded,
            BusinessDocumentPaymentStatus::Canceled => true,
            BusinessDocumentPaymentStatus::Failed => ! $observationSucceeded,
            default => false,
        };
    }
}
