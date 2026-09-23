<?php

namespace App\Library\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Exceptions\Payments\PaymentStartException;
use App\Library\Documents\PublicDocumentAccess;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessStripeConnection;
use Illuminate\Support\Facades\DB;

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
