<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentRefunded;
use App\Exceptions\Payments\RefundException;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessStripeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §7.4 / §8.7 / §5.9 — refund admission, capacity
 * reservation, the historical connected account, and the partial-vs-full rule.
 */
class DocumentRefundTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    // =================================================================
    // §8.7 — admission and capacity
    // =================================================================

    public function test_a_refund_inserts_one_pending_row_before_the_provider_is_called(): void
    {
        $captured = $this->capturedPayment();

        $refund = app(PaymentManager::class)->requestRefund($captured['payment'], 10000, 'Goodwill');

        $this->assertSame(1, BusinessDocumentRefund::query()->count());
        $this->assertSame(10000, (int) $refund->amount_minor);
        $this->assertSame('document-refund:' . $refund->uid, (string) $refund->local_idempotency_key);

        $call = $this->gateway->callsOf('createRefund')[0];
        $this->assertSame('document-refund:' . $refund->uid, $call['args']['idempotency_key']);
        $this->assertSame(0, $call['transaction_level'] - $this->gateway->baselineTransactionLevel,
            'The provider call happens after commit, outside every lock.');
    }

    public function test_a_pending_refund_reserves_capacity_exactly_like_a_succeeded_one(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);

        // Stays pending at the provider.
        $manager->requestRefund($captured['payment'], 30000);
        $this->assertSame(BusinessDocumentRefundStatus::Pending, BusinessDocumentRefund::query()->sole()->status);

        $this->assertSame(20000, $manager->refundableAmount($captured['payment']->refresh()));

        $this->expectException(RefundException::class);
        $manager->requestRefund($captured['payment'], 20001);
    }

    public function test_a_succeeded_refund_consumes_capacity(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;

        $manager->requestRefund($captured['payment'], 30000);

        $this->assertSame(20000, $manager->refundableAmount($captured['payment']->refresh()));

        $manager->requestRefund($captured['payment'], 20000);
        $this->assertSame(0, $manager->refundableAmount($captured['payment']->refresh()));
    }

    public function test_a_failed_refund_releases_its_reserved_capacity(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Failed;

        $manager->requestRefund($captured['payment'], 50000);

        $this->assertSame(BusinessDocumentRefundStatus::Failed, BusinessDocumentRefund::query()->sole()->status);
        $this->assertSame(50000, $manager->refundableAmount($captured['payment']->refresh()),
            'A terminal failed refund reserves nothing.');

        // ...and the whole amount can be refunded again.
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;
        $manager->requestRefund($captured['payment'], 50000);
        $this->assertSame(0, $manager->refundableAmount($captured['payment']->refresh()));
    }

    public function test_a_request_above_the_remaining_capacity_is_refused(): void
    {
        $captured = $this->capturedPayment();

        try {
            app(PaymentManager::class)->requestRefund($captured['payment'], 50001);
            $this->fail('An over-refund must be refused.');
        } catch (RefundException $e) {
            $this->assertSame(RefundException::EXCEEDS_REFUNDABLE, $e->reason);
        }

        $this->assertSame(0, BusinessDocumentRefund::query()->count(), 'A refused refund leaves no row.');
        $this->assertSame([], $this->gateway->callsOf('createRefund'), 'and never reaches the provider.');
    }

    public function test_a_non_positive_amount_is_refused(): void
    {
        $captured = $this->capturedPayment();

        try {
            app(PaymentManager::class)->requestRefund($captured['payment'], 0);
            $this->fail('A zero refund must be refused.');
        } catch (RefundException $e) {
            $this->assertSame(RefundException::INVALID_AMOUNT, $e->reason);
        }

        $this->assertSame(0, BusinessDocumentRefund::query()->count());
    }

    public function test_only_a_succeeded_payment_can_be_refunded(): void
    {
        $fixture = $this->payableDocument();
        app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
        $payment = \App\Models\BusinessDocumentPayment::query()->sole();
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $payment->status);

        try {
            app(PaymentManager::class)->requestRefund($payment, 100);
            $this->fail('An unsettled payment must not be refundable.');
        } catch (RefundException $e) {
            $this->assertSame(RefundException::PAYMENT_NOT_SUCCEEDED, $e->reason);
        }

        $this->assertSame(0, BusinessDocumentRefund::query()->count());
    }

    /**
     * §8.7's forced race, in the window where it actually exists.
     *
     * Refund B's admission runs from INSIDE refund A's provider call — so A is
     * committed, `pending`, and its outcome is still unknown. Subtracting only
     * already-succeeded refunds would let both through and return 80000 of a
     * 50000 capture. The reservation computed under the payment lock is what
     * refuses B.
     */
    public function test_two_forced_concurrent_refunds_cannot_exceed_the_captured_amount(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);

        $second = null;
        $this->gateway->duringCreateRefund = function () use ($manager, $captured, &$second) {
            try {
                $manager->requestRefund($captured['payment'], 40000);
                $second = 'admitted';
            } catch (RefundException $e) {
                $second = $e->reason;
            }
        };

        $manager->requestRefund($captured['payment'], 40000);

        $this->assertSame(RefundException::EXCEEDS_REFUNDABLE, $second,
            'The concurrent request sees the first reservation and is refused.');
        $this->assertSame(1, BusinessDocumentRefund::query()->count());

        $reserved = (int) BusinessDocumentRefund::query()
            ->where('status', '!=', BusinessDocumentRefundStatus::Failed->value)
            ->sum('amount_minor');
        $this->assertLessThanOrEqual(50000, $reserved);
    }

    // =================================================================
    // §7.4 — the uncertain response re-drives ONE row and ONE key
    // =================================================================

    public function test_an_uncertain_response_re_drives_the_same_row_and_key(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);
        $this->gateway->loseNextRefundResponse = true;

        try {
            $manager->requestRefund($captured['payment'], 25000);
            $this->fail('The lost response must surface.');
        } catch (\App\Exceptions\Payments\StripeConnectException) {
            // expected
        }

        // The durable row exists and still reserves its capacity, so nothing
        // else can spend it while the outcome is unknown.
        $refund = BusinessDocumentRefund::query()->sole();
        $this->assertSame(BusinessDocumentRefundStatus::Pending, $refund->status);
        $this->assertNull($refund->provider_refund_id);
        $this->assertSame(25000, $manager->refundableAmount($captured['payment']->refresh()));

        // Re-driving that SAME row reuses the key, so Stripe hands back the
        // refund it already created rather than returning money twice.
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;
        $manager->driveRefund($refund);

        $this->assertSame(1, BusinessDocumentRefund::query()->count(), 'No second refund row.');
        $this->assertSame(1, count($this->gateway->refunds), 'and no second provider refund.');

        $keys = array_map(fn ($call) => $call['args']['idempotency_key'], $this->gateway->callsOf('createRefund'));
        $this->assertSame([$keys[0], $keys[0]], $keys, 'Both attempts used one key.');
    }

    // =================================================================
    // §5.7 — the HISTORICAL connected account
    // =================================================================

    public function test_a_refund_targets_the_account_the_payment_was_taken_on(): void
    {
        $captured = $this->capturedPayment();

        // The Business moves to a different Stripe account after capture.
        BusinessStripeConnection::query()->whereKey($captured['fixture']['connection']->id)
            ->update(['status' => \App\Enums\Documents\StripeConnectionStatus::Disconnected->value]);
        $this->chargeReadyConnection($captured['fixture']['tenant']['business'], 'acct_brandnew9');

        app(PaymentManager::class)->requestRefund($captured['payment'], 10000);

        $call = $this->gateway->callsOf('createRefund')[0];
        $this->assertSame('acct_ready001', $call['args']['account'],
            'The refund must go to the account that captured the money, not the current one.');
        $this->assertNotContains('acct_brandnew9', $this->gateway->accountsTouched());
    }

    // =================================================================
    // §5.9 — partial vs full, and the document never moves backward
    // =================================================================

    public function test_a_partial_refund_leaves_the_schedule_item_paid(): void
    {
        Event::fake([DocumentRefunded::class]);
        $captured = $this->capturedPayment();
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;

        app(PaymentManager::class)->requestRefund($captured['payment'], 10000);

        $item = BusinessDocumentPaymentScheduleItem::query()->findOrFail($captured['payment']->schedule_item_id);
        $this->assertSame(PaymentScheduleItemStatus::Paid, $item->status);
        $this->assertSame(DocumentStatus::Paid, $captured['fixture']['document']->refresh()->status);
        Event::assertDispatched(DocumentRefunded::class, 1);
    }

    public function test_only_a_full_cumulative_refund_marks_the_item_refunded(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;

        $manager->requestRefund($captured['payment'], 20000);
        $item = BusinessDocumentPaymentScheduleItem::query()->findOrFail($captured['payment']->schedule_item_id);
        $this->assertSame(PaymentScheduleItemStatus::Paid, $item->status, 'One partial is not a full return.');

        $manager->requestRefund($captured['payment'], 30000);

        $this->assertSame(PaymentScheduleItemStatus::Refunded, $item->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $captured['fixture']['document']->refresh()->status,
            '§5.9 — the document stays paid after any refund and never moves backward.');
    }

    public function test_cumulative_succeeded_refunds_can_never_exceed_the_captured_amount(): void
    {
        $captured = $this->capturedPayment();
        $manager = app(PaymentManager::class);
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;

        $manager->requestRefund($captured['payment'], 50000);

        try {
            $manager->requestRefund($captured['payment'], 1);
            $this->fail('Nothing remains to refund.');
        } catch (RefundException $e) {
            $this->assertSame(RefundException::EXCEEDS_REFUNDABLE, $e->reason);
        }

        $total = (int) BusinessDocumentRefund::query()
            ->where('status', BusinessDocumentRefundStatus::Succeeded->value)->sum('amount_minor');
        $this->assertSame(50000, $total);
    }

    public function test_a_deposit_refund_leaves_the_balance_schedule_untouched(): void
    {
        $captured = $this->capturedPayment($this->depositAndBalance());
        $this->assertSame(20000, (int) $captured['payment']->amount_minor);
        $this->gateway->refundStatus = BusinessDocumentRefundStatus::Succeeded;

        app(PaymentManager::class)->requestRefund($captured['payment'], 20000);

        $schedule = BusinessDocumentPaymentScheduleItem::query()->orderBy('sequence')->get();
        $this->assertSame(PaymentScheduleItemStatus::Refunded, $schedule[0]->status);
        $this->assertSame(PaymentScheduleItemStatus::Pending, $schedule[1]->status,
            'A refund of the deposit does not touch the balance term.');
    }
}
