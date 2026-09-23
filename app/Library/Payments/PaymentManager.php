<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Exceptions\Payments\PaymentStartException;
use App\Exceptions\Payments\RefundException;
use App\Library\Documents\PublicDocumentAccess;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessStripeConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementation Contract 17 §7.2 — PAY START, implemented as the contract's
 * numbered algorithm rather than a simpler flow that happens to work most of
 * the time.
 *
 * THE INVARIANT THAT MATTERS: one active attempt per schedule item. An
 * ordinal-suffixed key cannot stop two concurrent first clicks from each
 * choosing an ordinal and each making a real charge, so the guarantee is
 * `unique(active_schedule_item_id)` in the database — a stored generated
 * column that holds the schedule item id only while a payment is in a
 * non-terminal status. Two racing inserts mean one of them violates that key
 * and re-reads the winner instead.
 *
 * NO NETWORK UNDER A LOCK (§7). The transaction below creates or finds the
 * durable row and commits; only then does the provider call happen; the
 * outcome is applied through the shared finalizer, which re-acquires locks in
 * §7.0's canonical order.
 *
 * TWO RE-DRIVE SHAPES (§7.2.1), never a second intent:
 *   Case A — the row already knows its intent id: retrieve THAT intent on the
 *            connection recorded on the row and return its client_secret.
 *   Case B — an earlier creation returned uncertainly, so the row has no
 *            intent id: repeat creation with the SAME
 *            `document-payment:{payment_uid}` key, and Stripe's own
 *            idempotency returns the original intent.
 *
 * THE CONNECTED ACCOUNT IS SERVER-DERIVED (§7.2.2). It comes from the payment
 * row's own `business_stripe_connection_id`; no request parameter names an
 * account and no client-supplied identifier is ever consulted.
 */
final class PaymentManager
{
    public function __construct(
        private readonly StripeConnectGateway $gateway,
        private readonly StripeConnectManager $connections,
        private readonly PaymentFinalizer $finalizer,
        private readonly RefundFinalizer $refundFinalizer,
    ) {
    }

    /**
     * §7.2 steps 1-15. Returns the transient browser material for the ONE
     * durable attempt that now exists for the currently payable item.
     *
     * @throws PaymentStartException
     */
    public function start(PublicDocumentAccess $access): PaymentStartResult
    {
        // ---- steps 1-13: local intent, committed, no network ------------
        [$payment, $connectionAccountId] = DB::transaction(function () use ($access) {
            // (2) document first — §7.0 tier 1.
            $document = BusinessDocument::query()
                ->whereKey($access->document->id)
                ->lockForUpdate()
                ->firstOrFail();

            // (3) the exact current issued version.
            $version = $document->current_version_id === null ? null : BusinessDocumentVersion::query()
                ->where('business_document_id', $document->id)
                ->whereKey($document->current_version_id)
                ->first();

            if ($version === null) {
                throw PaymentStartException::because(PaymentStartException::NOT_CURRENT_VERSION);
            }

            // (6) document payability, re-checked under the lock.
            $this->assertDocumentPayable($document);

            // (4) lock the exact schedule item — §7.0 tier 2, ascending id.
            $schedule = BusinessDocumentPaymentScheduleItem::query()
                ->where('business_document_version_id', $version->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $item = $schedule->first(fn ($row) => $row->status === PaymentScheduleItemStatus::Pending);

            if ($item === null) {
                throw PaymentStartException::because(PaymentStartException::NOTHING_PAYABLE);
            }

            // (5) an item of a superseded version is never payable. The query
            // above already scoped to current_version_id, so this re-asserts
            // the property rather than trusting the scope.
            if ((int) $item->business_document_version_id !== (int) $version->id) {
                throw PaymentStartException::because(PaymentStartException::NOT_CURRENT_VERSION);
            }

            // (7) deposit-first: every earlier sequence must have succeeded.
            foreach ($schedule as $earlier) {
                if ((int) $earlier->sequence < (int) $item->sequence
                    && $earlier->status !== PaymentScheduleItemStatus::Paid) {
                    throw PaymentStartException::because(PaymentStartException::DEPOSIT_OUTSTANDING);
                }
            }

            // (8) §11.4 — readiness re-checked immediately before the intent.
            $connection = $this->connections->liveConnection($access->business);

            if ($connection === null || ! $this->connections->isChargeReady($access->business)) {
                throw PaymentStartException::because(PaymentStartException::NOT_PAYMENT_READY);
            }

            // (9/10) an existing ACTIVE attempt is re-driven, never replaced.
            $active = BusinessDocumentPayment::query()
                ->where('schedule_item_id', $item->id)
                ->whereIn('status', self::activeStatuses())
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                return [$active, $this->accountFor($active)];
            }

            // (11/12) exactly one row; its UID is the provider key.
            $payment = new BusinessDocumentPayment([
                'business_id' => $access->business->id,
                'business_document_id' => $document->id,
                'schedule_item_id' => $item->id,
                'business_stripe_connection_id' => $connection->id,
                'amount_minor' => (int) $item->amount_minor,
                'currency_code' => (string) $item->currency_code,
            ]);
            $payment->save();

            // local_idempotency_key is derived from that durable row's UID and
            // is what the provider's metadata is cross-checked against (§8.3).
            $payment->forceFill([
                'local_idempotency_key' => self::idempotencyKeyFor($payment),
                'status' => BusinessDocumentPaymentStatus::Created->value,
            ])->save();

            return [$payment, (string) $connection->stripe_account_id];
        });

        // ---- (14) provider call, OUTSIDE every transaction and lock ------
        $snapshot = $payment->provider_payment_intent_id !== null
            // Case A — that same intent, on that same account.
            ? $this->gateway->retrievePaymentIntent($connectionAccountId, (string) $payment->provider_payment_intent_id)
            // Case B — same key, so Stripe returns the original if a previous
            // creation actually reached it.
            : $this->gateway->createPaymentIntent(
                $connectionAccountId,
                (int) $payment->amount_minor,
                (string) $payment->currency_code,
                self::idempotencyKeyFor($payment),
                (string) $payment->local_idempotency_key,
                'Document ' . $access->document->uid,
            );

        // ---- (15) shared idempotent finalizer ---------------------------
        $this->finalizer->apply($payment, $snapshot);

        return new PaymentStartResult(
            paymentUid: (string) $payment->uid,
            clientSecret: (string) $snapshot->clientSecret,
            connectedAccountId: $connectionAccountId,
            publishableKey: (string) config('services.stripe.key'),
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
        );
    }

    /**
     * §7.2 — the provider idempotency key is derived from the durable row's
     * UID, never from a guessed ordinal, so a repeat cannot originate a
     * second charge.
     */
    public static function idempotencyKeyFor(BusinessDocumentPayment $payment): string
    {
        return 'document-payment:' . $payment->uid;
    }

    // =================================================================
    // Sub-slice F — refunds (§7.4 / §8.7)
    // =================================================================

    /**
     * §7.4 — ADMIT one refund, then drive it.
     *
     * The admission transaction locks the PAYMENT (§7.0 tier 3) and then its
     * REFUNDS (tier 4) and takes NO OTHER LOCK. In particular it never touches
     * the document: §7.0 permits a refund path to enter at tier 3 only on the
     * condition that it does not afterwards reach backwards to tier 1. The
     * work that does need the document — completing a full return, which
     * changes the schedule item — happens later in RefundFinalizer, which
     * restarts cleanly from tier 1.
     *
     * §8.7's capacity rule, computed UNDER the payment lock so two concurrent
     * requests cannot both see the same headroom:
     *
     *     available = captured - SUM(pending) - SUM(succeeded)
     *
     * A PENDING refund reserves capacity exactly like a succeeded one. That is
     * the whole point: an in-flight refund has not failed, and treating it as
     * free headroom is how a payment gets refunded twice. A FAILED refund
     * reserves nothing, so its capacity returns automatically.
     *
     * NO NETWORK UNDER THE LOCK. The row is committed first; only then is
     * Stripe called, outside every transaction.
     *
     * @throws RefundException
     */
    public function requestRefund(
        BusinessDocumentPayment $payment,
        int $amountMinor,
        ?string $reason = null,
        ?int $initiatedByUserId = null,
    ): BusinessDocumentRefund {
        if ($amountMinor <= 0) {
            throw RefundException::because(RefundException::INVALID_AMOUNT);
        }

        $refund = DB::transaction(function () use ($payment, $amountMinor, $reason, $initiatedByUserId) {
            // (tier 3) the payment itself.
            $locked = BusinessDocumentPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== BusinessDocumentPaymentStatus::Succeeded) {
                throw RefundException::because(RefundException::PAYMENT_NOT_SUCCEEDED);
            }

            if ($locked->provider_payment_intent_id === null) {
                throw RefundException::because(RefundException::NO_PROVIDER_PAYMENT);
            }

            // (tier 4) its refunds, ascending id.
            $existing = BusinessDocumentRefund::query()
                ->where('business_document_payment_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $reserved = $existing
                ->filter(fn ($row) => $row->status !== BusinessDocumentRefundStatus::Failed)
                ->sum('amount_minor');

            if ($amountMinor > (int) $locked->amount_minor - (int) $reserved) {
                throw RefundException::because(RefundException::EXCEEDS_REFUNDABLE);
            }

            $refund = new BusinessDocumentRefund([
                'business_id' => $locked->business_id,
                'business_document_payment_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'initiated_by_user_id' => $initiatedByUserId,
            ]);

            // The UID is minted HERE rather than by the creating hook, so the
            // durable key exists in the SAME insert as the row. Saving first
            // and filling the key afterwards would leave the row momentarily
            // holding an empty `local_idempotency_key`, and two refunds
            // admitted concurrently for one Business would then collide on
            // unique(business_id, local_idempotency_key) with a raw driver
            // error instead of this method's own clean refusal.
            $refund->generateUid();
            $refund->local_idempotency_key = self::refundKeyFor($refund);
            $refund->status = BusinessDocumentRefundStatus::Pending->value;
            $refund->save();

            return $refund;
        });

        return $this->driveRefund($refund);
    }

    /**
     * §7.4 — take one already-admitted refund row to the provider and apply
     * whatever comes back. Separate from admission so an UNCERTAIN response
     * re-drives THE SAME ROW AND KEY instead of admitting a second refund.
     *
     * Two shapes, mirroring §7.2.1:
     *   Case A — the row already knows its provider refund id: retrieve THAT
     *            refund on the payment's own historical account.
     *   Case B — creation returned uncertainly, so there is no id: repeat
     *            creation with the SAME `document-refund:{uid}` key, and
     *            Stripe's idempotency returns the original refund rather than
     *            returning money twice.
     *
     * @throws RefundException|\App\Exceptions\Payments\StripeConnectException
     */
    public function driveRefund(BusinessDocumentRefund $refund): BusinessDocumentRefund
    {
        $payment = BusinessDocumentPayment::query()->findOrFail($refund->business_document_payment_id);

        // §5.7 — the HISTORICAL account this money was taken on. Never the
        // Business's currently connected account: refunding on a different
        // account would either fail or return someone else's money.
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

        if ($connection === null) {
            throw RefundException::because(RefundException::CONNECTION_UNAVAILABLE);
        }

        $account = (string) $connection->stripe_account_id;

        $snapshot = $refund->provider_refund_id !== null
            ? $this->gateway->retrieveRefund($account, (string) $refund->provider_refund_id)
            : $this->gateway->createRefund(
                $account,
                (string) $payment->provider_payment_intent_id,
                (int) $refund->amount_minor,
                self::refundKeyFor($refund),
                (string) $refund->local_idempotency_key,
            );

        $this->refundFinalizer->apply($refund, $snapshot);

        return $refund->refresh();
    }

    /**
     * §7.4/§8.1 — `document-refund:{refund_uid}`. Derived from the durable
     * row, so re-driving is structurally the same call.
     */
    public static function refundKeyFor(BusinessDocumentRefund $refund): string
    {
        return 'document-refund:' . $refund->uid;
    }

    /**
     * §7.5 — bounded reconciliation of ABANDONED attempts, introducing NO
     * second authority.
     *
     * A customer who closes the tab mid-Payment-Element leaves a local attempt
     * in `created` or `requires_action`, still holding
     * `active_schedule_item_id` and so still blocking its schedule item. This
     * sweep does exactly two things per row: it ASKS the provider, on the
     * row's own recorded connection, and it hands the answer to the SAME
     * shared PaymentFinalizer every other path uses — with the same
     * amount/currency/account/app_operation_id cross-checks.
     *
     * IT DECIDES NOTHING. It never marks an attempt `failed` or `canceled`
     * because time passed. That prohibition is the entire point of §7.5: a
     * customer can complete an SCA step late, and a locally invented terminal
     * state would free `active_schedule_item_id` while a real charge was still
     * live — inviting a second charge for the same item. Only a
     * provider-confirmed terminal outcome releases the slot, and it does so
     * through the finalizer, not here.
     *
     * ROWS WITH NO PROVIDER INTENT ID ARE SKIPPED, not resolved. There is no
     * authoritative object to retrieve for them, and the only way to find out
     * would be to re-issue the creation — originating a PaymentIntent from a
     * background sweep, which is not what §7.5 describes. Such a row is
     * resolved the way §7.2.1 Case B already resolves it: the next deliberate
     * Pay re-drives the same row under the same key.
     *
     * NO NETWORK UNDER A LOCK: the retrieval happens outside any transaction,
     * and the finalizer opens its own.
     *
     * THE RETURNED COUNT IS ATTEMPTS THAT REACHED A TERMINAL STATE. An attempt
     * the provider still reports as in flight is re-observed and left exactly
     * where it is, and is deliberately not counted as "reconciled" — nothing
     * about it was resolved.
     */
    public function reconcileStalePayments(int $limit): int
    {
        $threshold = now()->subMinutes(max(1, (int) config('documents.stale_payment_minutes', 30)));

        $candidates = BusinessDocumentPayment::query()
            ->whereIn('status', self::activeStatuses())
            ->whereNotNull('provider_payment_intent_id')
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reconciled = 0;

        foreach ($candidates as $payment) {
            try {
                $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

                if ($connection === null) {
                    continue;
                }

                $snapshot = $this->gateway->retrievePaymentIntent(
                    (string) $connection->stripe_account_id,
                    (string) $payment->provider_payment_intent_id,
                );

                $disposition = $this->finalizer->apply($payment, $snapshot);

                if ($disposition === PaymentFinalizer::APPLIED
                    && ! in_array($payment->refresh()->status->value, self::activeStatuses(), true)) {
                    $reconciled++;
                }
            } catch (Throwable $e) {
                // One unreachable account or one provider hiccup must not
                // abort the batch. The reason CODE is kept; no provider
                // message, account id or amount is logged.
                Log::error('PaymentManager::reconcileStalePayments failed for a payment', [
                    'business_document_payment_id' => $payment->id,
                    'exception' => class_basename($e),
                ]);
            }
        }

        return $reconciled;
    }

    /**
     * §8.7 — what is still refundable on a payment, for the confirmation step
     * to render. Read-only and advisory: the authoritative check is the one
     * requestRefund() performs under the payment lock.
     */
    public function refundableAmount(BusinessDocumentPayment $payment): int
    {
        if ($payment->status !== BusinessDocumentPaymentStatus::Succeeded) {
            return 0;
        }

        $reserved = (int) BusinessDocumentRefund::query()
            ->where('business_document_payment_id', $payment->id)
            ->where('status', '!=', BusinessDocumentRefundStatus::Failed->value)
            ->sum('amount_minor');

        return max(0, (int) $payment->amount_minor - $reserved);
    }

    /**
     * The payment state the public page renders: what is due, which item is
     * payable, and whether paying is possible at all. Read-only — §6.3's GET
     * creates nothing.
     *
     * @return array{payable_item: ?BusinessDocumentPaymentScheduleItem, amount_minor: ?int, currency_code: ?string, can_pay: bool, reason: ?string}
     */
    public function paymentState(PublicDocumentAccess $access): array
    {
        $none = ['payable_item' => null, 'amount_minor' => null, 'currency_code' => null, 'can_pay' => false, 'reason' => null];

        try {
            $this->assertDocumentPayable($access->document);
        } catch (PaymentStartException $e) {
            // Spread, not `+`: PHP's union keeps the LEFT operand's key, so
            // `$none + [...]` would silently discard the reason.
            return [...$none, 'reason' => $e->reason];
        }

        $schedule = $access->version->paymentScheduleItems()->orderBy('sequence')->get();
        $item = $schedule->first(fn ($row) => $row->status === PaymentScheduleItemStatus::Pending);

        if ($item === null) {
            return [...$none, 'reason' => PaymentStartException::NOTHING_PAYABLE];
        }

        foreach ($schedule as $earlier) {
            if ((int) $earlier->sequence < (int) $item->sequence && $earlier->status !== PaymentScheduleItemStatus::Paid) {
                return [...$none, 'reason' => PaymentStartException::DEPOSIT_OUTSTANDING];
            }
        }

        $ready = $this->connections->isChargeReady($access->business);

        return [
            'payable_item' => $item,
            'amount_minor' => (int) $item->amount_minor,
            'currency_code' => (string) $item->currency_code,
            'can_pay' => $ready,
            'reason' => $ready ? null : PaymentStartException::NOT_PAYMENT_READY,
        ];
    }

    /**
     * §7.3 — signature is a payability gate, and a terminal document is never
     * payable.
     *
     * @throws PaymentStartException
     */
    private function assertDocumentPayable(BusinessDocument $document): void
    {
        if (in_array($document->status, [DocumentStatus::Paid, DocumentStatus::Void, DocumentStatus::Expired, DocumentStatus::Draft], true)) {
            throw PaymentStartException::because(PaymentStartException::DOCUMENT_NOT_PAYABLE);
        }

        if ($document->requires_signature && $document->status !== DocumentStatus::Signed) {
            throw PaymentStartException::because(PaymentStartException::NOT_SIGNED);
        }

        if (! $document->requires_signature
            && ! in_array($document->status, [DocumentStatus::Sent, DocumentStatus::Signed], true)) {
            throw PaymentStartException::because(PaymentStartException::DOCUMENT_NOT_PAYABLE);
        }
    }

    /**
     * §7.2.2 — the account comes from the row's OWN historical connection, so
     * re-driving an attempt always targets the account that created the
     * intent, even if the Business has since connected a different one.
     */
    private function accountFor(BusinessDocumentPayment $payment): string
    {
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

        if ($connection === null) {
            throw PaymentStartException::because(PaymentStartException::NOT_PAYMENT_READY);
        }

        return (string) $connection->stripe_account_id;
    }

    /**
     * Exactly the statuses the DB's `active_schedule_item_id` generated
     * column treats as live, so the application and the unique key can never
     * disagree about what "one active attempt" means.
     *
     * @return array<int, string>
     */
    private static function activeStatuses(): array
    {
        return [
            BusinessDocumentPaymentStatus::Created->value,
            BusinessDocumentPaymentStatus::RequiresAction->value,
            BusinessDocumentPaymentStatus::Processing->value,
        ];
    }
}
