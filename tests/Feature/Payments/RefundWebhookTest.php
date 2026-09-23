<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §8.2 / §8.3 / §4.5 — refund events travel the
 * SAME verified webhook boundary and the SAME claim/lease as payment events,
 * routed by event type and resolved by provider reference.
 */
class RefundWebhookTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    /** A pending refund whose provider id we already recorded. */
    private function pendingRefund(int $amount = 20000): array
    {
        $captured = $this->capturedPayment();
        $refund = app(PaymentManager::class)->requestRefund($captured['payment'], $amount);

        return ['captured' => $captured, 'refund' => $refund->refresh()];
    }

    private function postRefundEvent(array $state, array $overrides = [])
    {
        [$body, $headers] = $this->refundWebhookPayload(
            $overrides['type'] ?? 'refund.updated',
            $overrides['refund'] ?? (string) ($state['refund']->provider_refund_id ?? 're_unknown'),
            $overrides['status'] ?? 'succeeded',
            $overrides['account'] ?? 'acct_ready001',
            $overrides['amount'] ?? (int) $state['refund']->amount_minor,
            $overrides['currency'] ?? 'USD',
            $overrides['intent'] ?? (string) $state['captured']['payment']->provider_payment_intent_id,
            $overrides['event_id'] ?? null,
        );

        return $this->postWebhook($body, $headers);
    }

    // =================================================================
    // §4.5 — routed by EVENT TYPE, never by metadata
    // =================================================================

    public function test_a_refund_event_settles_the_refund_without_any_app_metadata(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state)->assertOk();

        $this->assertSame(BusinessDocumentRefundStatus::Succeeded, $state['refund']->refresh()->status);
        $this->assertNotNull($state['refund']->refresh()->succeeded_at);
        $this->assertSame(BusinessPaymentEventState::Processed, BusinessPaymentEvent::query()->sole()->state);
    }

    public function test_a_charge_level_event_is_recorded_and_ignored(): void
    {
        $state = $this->pendingRefund();

        // `charge.refunded` carries a CHARGE, not a refund; this slice does not
        // parse two object shapes in one handler.
        $this->postRefundEvent($state, ['type' => 'charge.refunded'])->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Ignored, $event->state);
        $this->assertSame('unhandled_event_type', $event->last_error);
        $this->assertSame(BusinessDocumentRefundStatus::Pending, $state['refund']->refresh()->status);
    }

    public function test_the_legacy_refund_event_name_is_handled_too(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state, ['type' => 'charge.refund.updated'])->assertOk();

        $this->assertSame(BusinessDocumentRefundStatus::Succeeded, $state['refund']->refresh()->status);
    }

    // =================================================================
    // §8.2 — replay safety
    // =================================================================

    public function test_a_duplicate_refund_delivery_returns_200_and_reprocesses_nothing(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state, ['event_id' => 'evt_refund_dup'])->assertOk();
        $this->postRefundEvent($state, ['event_id' => 'evt_refund_dup'])->assertOk();
        $this->postRefundEvent($state, ['event_id' => 'evt_refund_dup'])->assertOk();

        $this->assertSame(1, BusinessPaymentEvent::query()->count());
        $this->assertSame(1, (int) BusinessPaymentEvent::query()->sole()->attempts);
        $this->assertSame(1, BusinessDocumentRefund::query()->count());
        $this->assertSame(BusinessDocumentRefundStatus::Succeeded, $state['refund']->refresh()->status);
    }

    public function test_two_distinct_events_for_one_refund_settle_it_once(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state, ['event_id' => 'evt_r_one'])->assertOk();
        $settledAt = $state['refund']->refresh()->succeeded_at;

        $this->postRefundEvent($state, ['event_id' => 'evt_r_two'])->assertOk();

        $this->assertSame(2, BusinessPaymentEvent::query()->count());
        $this->assertSame('ignored_already_terminal',
            BusinessPaymentEvent::query()->orderByDesc('id')->first()->last_error);
        $this->assertEquals($settledAt, $state['refund']->refresh()->succeeded_at,
            'A terminal refund never moves again.');
    }

    public function test_a_failed_refund_event_releases_the_reservation(): void
    {
        $state = $this->pendingRefund(50000);

        $this->postRefundEvent($state, ['type' => 'refund.failed', 'status' => 'failed'])->assertOk();

        $this->assertSame(BusinessDocumentRefundStatus::Failed, $state['refund']->refresh()->status);
        $this->assertSame(50000, app(PaymentManager::class)->refundableAmount($state['captured']['payment']->refresh()));
    }

    // =================================================================
    // §8.3 — resolution by provider reference, fail-closed
    // =================================================================

    public function test_an_event_resolves_a_refund_whose_provider_id_we_never_learned(): void
    {
        $captured = $this->capturedPayment();
        $this->gateway->loseNextRefundResponse = true;

        try {
            app(PaymentManager::class)->requestRefund($captured['payment'], 15000);
        } catch (\App\Exceptions\Payments\StripeConnectException) {
            // The row exists; the id never came back.
        }

        $refund = BusinessDocumentRefund::query()->sole();
        $this->assertNull($refund->provider_refund_id);

        // The webhook beats our own response back, and resolves through the
        // PaymentIntent reference instead.
        $this->postRefundEvent(['captured' => $captured, 'refund' => $refund], [
            'refund' => 're_fake000001',
            'amount' => 15000,
        ])->assertOk();

        $refund->refresh();
        $this->assertSame(BusinessDocumentRefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_fake000001', (string) $refund->provider_refund_id);
    }

    public function test_an_ambiguous_resolution_fails_closed(): void
    {
        $captured = $this->capturedPayment();

        // Two identical unlinked pending refunds: nothing in the event can say
        // which one it belongs to.
        foreach ([1, 2] as $ignored) {
            $this->gateway->loseNextRefundResponse = true;
            try {
                app(PaymentManager::class)->requestRefund($captured['payment'], 10000);
            } catch (\App\Exceptions\Payments\StripeConnectException) {
                // expected
            }
        }

        $this->assertSame(2, BusinessDocumentRefund::query()->whereNull('provider_refund_id')->count());

        $this->postRefundEvent(['captured' => $captured, 'refund' => BusinessDocumentRefund::query()->first()], [
            'refund' => 're_ambiguous01',
            'amount' => 10000,
        ])->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Failed, $event->state);
        $this->assertSame('cross_reference_ambiguity', $event->last_error);
        $this->assertSame(2, BusinessDocumentRefund::query()
            ->where('status', BusinessDocumentRefundStatus::Pending->value)->count(),
            'Neither row was guessed at.');
    }

    public function test_an_event_on_the_wrong_account_is_refused(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state, ['account' => 'acct_someoneelse'])->assertOk();

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Failed, $event->state);
        $this->assertSame(BusinessDocumentRefundStatus::Pending, $state['refund']->refresh()->status);
    }

    public function test_an_event_with_the_wrong_amount_is_refused(): void
    {
        $state = $this->pendingRefund();

        $this->postRefundEvent($state, ['amount' => 20001])->assertOk();

        $this->assertSame('amount_mismatch', BusinessPaymentEvent::query()->sole()->last_error);
        $this->assertSame(BusinessDocumentRefundStatus::Pending, $state['refund']->refresh()->status);
    }

    // =================================================================
    // §5.9 — what a settled refund does to the schedule
    // =================================================================

    public function test_a_full_refund_arriving_by_webhook_marks_the_item_refunded_and_leaves_the_document_paid(): void
    {
        $state = $this->pendingRefund(50000);

        $this->postRefundEvent($state)->assertOk();

        $item = BusinessDocumentPaymentScheduleItem::query()
            ->findOrFail($state['captured']['payment']->schedule_item_id);
        $this->assertSame(PaymentScheduleItemStatus::Refunded, $item->status);
        $this->assertSame(DocumentStatus::Paid, $state['captured']['fixture']['document']->refresh()->status);
    }

    public function test_a_partial_refund_arriving_by_webhook_leaves_the_item_paid(): void
    {
        $state = $this->pendingRefund(10000);

        $this->postRefundEvent($state)->assertOk();

        $item = BusinessDocumentPaymentScheduleItem::query()
            ->findOrFail($state['captured']['payment']->schedule_item_id);
        $this->assertSame(PaymentScheduleItemStatus::Paid, $item->status);
        $this->assertSame(DocumentStatus::Paid, $state['captured']['fixture']['document']->refresh()->status);
    }
}
