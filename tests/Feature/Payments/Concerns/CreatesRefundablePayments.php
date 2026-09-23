<?php

namespace Tests\Feature\Payments\Concerns;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Library\Payments\PaymentFinalizer;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use Illuminate\Support\Str;

/**
 * Sub-slice F fixtures — a CAPTURED payment, produced by the production
 * PAY START path and settled through the production finalizer, so a refund
 * test never invents a `succeeded` row by hand.
 */
trait CreatesRefundablePayments
{
    use CreatesPayableDocuments;

    /**
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    protected function capturedPayment(?array $schedule = null, bool $sign = true): array
    {
        $fixture = $this->payableDocument($schedule, $sign);

        return ['fixture' => $fixture, 'payment' => $this->captureNextItem($fixture)];
    }

    /**
     * Starts and settles whatever schedule item is currently payable.
     */
    protected function captureNextItem(array $fixture): BusinessDocumentPayment
    {
        app(PaymentManager::class)->start($this->accessFor($fixture['document']->refresh(), $fixture['token']));
        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->first();

        $intentId = (string) $payment->provider_payment_intent_id;
        $this->gateway->setIntentStatus($intentId, BusinessDocumentPaymentStatus::Succeeded);
        app(PaymentFinalizer::class)->apply($payment, $this->gateway->intentSnapshot($intentId));

        return $payment->refresh();
    }

    /**
     * A signed Connect webhook body for one REFUND object.
     *
     * Note the shape: `data.object` is a refund, and it deliberately carries
     * NO `app_operation_id`. §4.5/§8.3 — refund metadata is independent and is
     * never inherited from the originating PaymentIntent, so resolution has to
     * work from provider references alone.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function refundWebhookPayload(
        string $eventType,
        string $refundId,
        string $status,
        string $accountId,
        int $amountMinor,
        string $currency,
        ?string $paymentIntentId = null,
        ?string $eventId = null,
    ): array {
        $body = json_encode([
            'id' => $eventId ?? ('evt_' . Str::random(16)),
            'type' => $eventType,
            'account' => $accountId,
            'data' => ['object' => [
                'id' => $refundId,
                'object' => 'refund',
                'status' => $status,
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'payment_intent' => $paymentIntentId,
                'metadata' => [],
            ]],
        ]);

        return [$body, ['Stripe-Signature' => $this->gateway->validSignature]];
    }
}
