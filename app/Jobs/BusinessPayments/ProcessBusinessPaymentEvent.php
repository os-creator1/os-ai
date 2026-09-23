<?php

namespace App\Jobs\BusinessPayments;

use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Jobs\Base;
use App\Library\Payments\PaymentFinalizer;
use App\Library\Payments\PaymentIntentSnapshot;
use App\Library\Payments\ProviderStatusMap;
use App\Library\Payments\RefundFinalizer;
use App\Library\Payments\RefundSnapshot;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessPaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Implementation Contract 17 §8.2 — the claim/lease consumer for lane-B
 * Connect events.
 *
 * THE CLAIM IS ONE ATOMIC CONDITIONAL UPDATE whose WHERE admits only a
 * `received` row, a retryable `failed` one, or a `processing` one whose lease
 * has expired. Zero rows updated means another worker owns it, and this job
 * returns immediately — that is what makes concurrent replay of the same
 * event harmless.
 *
 * TERMINAL WRITES ARE GUARDED `WHERE id = ? AND state = 'processing'`, so a
 * worker that lost its lease mid-flight cannot overwrite the winner's result.
 *
 * `last_error` stores an exception CLASS or a reason code — never a message
 * (§8.2 step 5), because a provider message can carry account identifiers and
 * payload detail.
 *
 * Mutation happens only through the shared finalizer, which re-acquires locks
 * in §7.0's canonical order and performs §8.3's fail-closed cross-checks. This
 * job decides nothing about money on its own.
 */
class ProcessBusinessPaymentEvent extends Base implements ShouldQueue
{
    /** How long a claim is held before another worker may take it over. */
    private const LEASE_SECONDS = 120;

    public function __construct(private readonly int $eventId)
    {
    }

    public function handle(PaymentFinalizer $finalizer, RefundFinalizer $refundFinalizer): void
    {
        $claimed = $this->claim();

        if ($claimed === 0) {
            return;
        }

        $event = BusinessPaymentEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        try {
            [$state, $reason] = $this->process($event, $finalizer, $refundFinalizer);
        } catch (Throwable $e) {
            $this->finish(BusinessPaymentEventState::Failed, class_basename($e));

            throw $e;
        }

        $this->finish($state, $reason);
    }

    /**
     * @return array{0: BusinessPaymentEventState, 1: ?string}
     */
    private function process(BusinessPaymentEvent $event, PaymentFinalizer $finalizer, RefundFinalizer $refundFinalizer): array
    {
        $eventType = (string) $event->event_type;

        // §8.3/§4.5 — refunds are routed by EVENT TYPE, through this same
        // claim/lease, never by inheriting the PaymentIntent's metadata.
        if (ProviderStatusMap::isRefundEventType($eventType)) {
            return $this->processRefund($event, $refundFinalizer);
        }

        // Only the lane-B payment events are acted on. Everything else is
        // recorded and ignored rather than guessed at (§8.3).
        if (! ProviderStatusMap::handlesEventType($eventType)) {
            return [BusinessPaymentEventState::Ignored, 'unhandled_event_type'];
        }

        $payload = json_decode((string) $event->payload_encrypted, true);
        $intent = $payload['data']['object'] ?? null;

        if (! is_array($intent) || ! is_string($intent['id'] ?? null)) {
            return [BusinessPaymentEventState::Ignored, 'unusable_payload'];
        }

        // Resolved by PROVIDER REFERENCE, never by trusting the event to name
        // one of our rows.
        $payment = BusinessDocumentPayment::query()
            ->where('provider_payment_intent_id', $intent['id'])
            ->first();

        if ($payment === null) {
            return [BusinessPaymentEventState::Failed, 'no_matching_local_record'];
        }

        // The event type is authoritative for the outcome: Stripe reuses
        // `requires_payment_method` for both a fresh intent and a failed one,
        // so the intent status alone cannot express failure (§11.8).
        $status = ProviderStatusMap::forEventType($eventType);

        $snapshot = new PaymentIntentSnapshot(
            providerPaymentIntentId: (string) $intent['id'],
            status: $status,
            amountMinor: (int) ($intent['amount'] ?? 0),
            currencyCode: mb_strtoupper((string) ($intent['currency'] ?? '')),
            // The event's own `account` field — the connected account the
            // finalizer cross-checks against the row's recorded connection.
            connectedAccountId: (string) $event->stripe_account_id,
            operationId: $intent['metadata']['app_operation_id'] ?? null,
            providerChargeId: is_string($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : null,
            failureCode: $intent['last_payment_error']['code'] ?? null,
            clientSecret: null,
        );

        $disposition = $finalizer->apply($payment, $snapshot);

        return match ($disposition) {
            PaymentFinalizer::APPLIED => [BusinessPaymentEventState::Processed, null],
            PaymentFinalizer::IGNORED_ALREADY_TERMINAL,
            PaymentFinalizer::IGNORED_NO_CHANGE,
            PaymentFinalizer::IGNORED_DOCUMENT_TERMINAL => [BusinessPaymentEventState::Ignored, $disposition],
            // Every cross-check mismatch is fail-closed and retained for
            // inspection as a reason CODE.
            default => [BusinessPaymentEventState::Failed, $disposition],
        };
    }

    /**
     * §8.3 — apply a refund event.
     *
     * RESOLUTION IS BY PROVIDER REFERENCE, in that order and no other:
     *   1. the refund's own id against `provider_refund_id`;
     *   2. failing that — the webhook can beat our own create response back —
     *      the refund's `payment_intent` / `charge` reference, narrowed to the
     *      still-pending refunds of that payment for the exact amount.
     *
     * If step 2 leaves MORE THAN ONE candidate, that is
     * `cross_reference_ambiguity` and the event fails closed. Guessing which
     * of two identical pending refunds an event belongs to would settle the
     * wrong row and release the wrong reservation.
     *
     * The refund OBJECT's status is authoritative here, unlike the payment
     * path: Stripe's refund statuses are unambiguous, so there is no
     * `requires_payment_method`-style collision to disambiguate by event type.
     *
     * @return array{0: BusinessPaymentEventState, 1: ?string}
     */
    private function processRefund(BusinessPaymentEvent $event, RefundFinalizer $refundFinalizer): array
    {
        $payload = json_decode((string) $event->payload_encrypted, true);
        $object = $payload['data']['object'] ?? null;

        if (! is_array($object) || ! is_string($object['id'] ?? null) || ! is_string($object['status'] ?? null)) {
            return [BusinessPaymentEventState::Ignored, 'unusable_payload'];
        }

        $refund = BusinessDocumentRefund::query()
            ->where('provider_refund_id', $object['id'])
            ->first();

        if ($refund === null) {
            $resolved = $this->resolveUnlinkedRefund($object);

            if (is_string($resolved)) {
                return [BusinessPaymentEventState::Failed, $resolved];
            }

            $refund = $resolved;
        }

        $snapshot = new RefundSnapshot(
            providerRefundId: (string) $object['id'],
            status: ProviderStatusMap::forRefundStatus((string) $object['status']),
            amountMinor: (int) ($object['amount'] ?? 0),
            currencyCode: mb_strtoupper((string) ($object['currency'] ?? '')),
            // The event's own `account` field, cross-checked by the finalizer
            // against the connection recorded on the ORIGINAL payment (§5.7).
            connectedAccountId: (string) $event->stripe_account_id,
            operationId: null,
            providerChargeId: is_string($object['charge'] ?? null) ? $object['charge'] : null,
        );

        $disposition = $refundFinalizer->apply($refund, $snapshot);

        return match ($disposition) {
            RefundFinalizer::APPLIED => [BusinessPaymentEventState::Processed, null],
            RefundFinalizer::IGNORED_ALREADY_TERMINAL => [BusinessPaymentEventState::Ignored, $disposition],
            default => [BusinessPaymentEventState::Failed, $disposition],
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return BusinessDocumentRefund|string the row, or a fail-closed reason code
     */
    private function resolveUnlinkedRefund(array $object): BusinessDocumentRefund|string
    {
        $payment = null;

        if (is_string($object['payment_intent'] ?? null)) {
            $payment = BusinessDocumentPayment::query()
                ->where('provider_payment_intent_id', $object['payment_intent'])
                ->first();
        }

        if ($payment === null && is_string($object['charge'] ?? null)) {
            $payment = BusinessDocumentPayment::query()
                ->where('provider_charge_id', $object['charge'])
                ->first();
        }

        if ($payment === null) {
            return 'no_matching_local_record';
        }

        $candidates = BusinessDocumentRefund::query()
            ->where('business_document_payment_id', $payment->id)
            ->whereNull('provider_refund_id')
            ->where('status', BusinessDocumentRefundStatus::Pending->value)
            ->where('amount_minor', (int) ($object['amount'] ?? 0))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return 'no_matching_local_record';
        }

        if ($candidates->count() > 1) {
            return 'cross_reference_ambiguity';
        }

        return $candidates->first();
    }

    /**
     * §8.2 step 3 — the atomic conditional claim.
     */
    private function claim(): int
    {
        $now = now();

        return DB::table('business_payment_events')
            ->where('id', $this->eventId)
            ->where(function ($query) use ($now) {
                $query->where('state', BusinessPaymentEventState::Received->value)
                    ->orWhere('state', BusinessPaymentEventState::Failed->value)
                    ->orWhere(function ($stale) use ($now) {
                        $stale->where('state', BusinessPaymentEventState::Processing->value)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<', $now);
                    });
            })
            ->update([
                'state' => BusinessPaymentEventState::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => $now,
                'last_attempt_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
            ]);
    }

    /**
     * §8.2 step 4 — terminal writes are guarded on still owning the claim.
     */
    private function finish(BusinessPaymentEventState $state, ?string $reason): void
    {
        DB::table('business_payment_events')
            ->where('id', $this->eventId)
            ->where('state', BusinessPaymentEventState::Processing->value)
            ->update([
                'state' => $state->value,
                'completed_at' => now(),
                'lease_expires_at' => null,
                'last_error' => $reason === null ? null : mb_substr($reason, 0, 120),
            ]);
    }
}
