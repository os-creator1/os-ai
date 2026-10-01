<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Payments & Invoices V1 completion — the money-correctness gaps found in the
 * Contract 17 finalizer, each proven through the REAL webhook route and the
 * REAL managers (only Stripe itself is the fake).
 *
 *   - a capture that lands after the document went void/expired/paid is
 *     RECORDED (so it is visible and refundable), never stranded;
 *   - `failed` yields to a provider-confirmed success on the same intent
 *     (Payment Element retry), and to nothing else;
 *   - the stable after-commit events carry Business + Location + Contact +
 *     document + payment identity and fire at most once per transition;
 *   - the provider object is traceable to its tenant, and the browser can
 *     never move the amount, the currency or the account.
 */
class InvoicePaymentCompletionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
        Notification::fake();
    }

    /**
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    private function startedPayment(?array $schedule = null): array
    {
        $fixture = $this->payableDocument($schedule);
        app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));

        return ['fixture' => $fixture, 'payment' => BusinessDocumentPayment::query()->orderByDesc('id')->first()];
    }

    private function event(BusinessDocumentPayment $payment, string $type, array $overrides = [])
    {
        [$body, $headers] = $this->webhookPayload(
            $type,
            $overrides['intent'] ?? (string) $payment->provider_payment_intent_id,
            $overrides['account'] ?? (string) BusinessStripeConnection::query()->find($payment->business_stripe_connection_id)->stripe_account_id,
            $overrides['amount'] ?? (int) $payment->amount_minor,
            $overrides['currency'] ?? (string) $payment->currency_code,
            array_key_exists('operation', $overrides) ? $overrides['operation'] : (string) $payment->local_idempotency_key,
            $overrides['event_id'] ?? null,
        );

        return $this->postWebhook($body, $headers);
    }

    private function succeed(BusinessDocumentPayment $payment, array $overrides = [])
    {
        return $this->event($payment, 'payment_intent.succeeded', $overrides);
    }

    // =================================================================
    // A capture after the document is terminal is recorded, not stranded
    // =================================================================

    public function test_a_capture_after_void_is_recorded_so_it_can_be_refunded_and_the_document_stays_void(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment();
        $document = $started['fixture']['document'];

        // The owner voids while the customer is mid-payment…
        app(DocumentManager::class)->void($document, 'Cancelled by owner.');

        // …and the customer's payment completes at Stripe anyway.
        $this->succeed($started['payment'])->assertOk();

        $payment = $started['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->status,
            'Real money was captured; the ledger row must say so.');
        $this->assertNotNull($payment->succeeded_at);

        $document->refresh();
        $this->assertSame(DocumentStatus::Void, $document->status, 'A void document never moves.');
        $this->assertNull($document->paid_at);
        $this->assertSame(PaymentScheduleItemStatus::Void, BusinessDocumentPaymentScheduleItem::query()->find($payment->schedule_item_id)->status);

        $event = BusinessPaymentEvent::query()->sole();
        $this->assertSame(BusinessPaymentEventState::Processed, $event->state);
        $this->assertSame('recorded_against_terminal_document', $event->last_error,
            'Support can see that this capture landed on a closed document.');

        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertNotDispatched(DocumentFullyPaid::class);

        // It is visible and it is refundable.
        $this->assertSame(50000, app(PaymentManager::class)->refundableAmount($payment));
        $refund = app(PaymentManager::class)->requestRefund($payment, 50000, 'Voided invoice', null);
        $this->assertSame(50000, (int) $refund->amount_minor);

        // The void now being unreimbursed money is the document's own rule:
        // the document is already void, and stays so.
        $this->assertSame(DocumentStatus::Void, $document->refresh()->status);
    }

    public function test_replaying_a_capture_against_a_void_document_records_it_once(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment();
        app(DocumentManager::class)->void($started['fixture']['document'], 'Cancelled.');

        $this->succeed($started['payment'], ['event_id' => 'evt_first'])->assertOk();
        $this->succeed($started['payment'], ['event_id' => 'evt_second'])->assertOk();

        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        $this->assertSame(1, BusinessDocumentPayment::query()->where('status', 'succeeded')->count());
        $second = BusinessPaymentEvent::query()->where('provider_event_id', 'evt_second')->sole();
        $this->assertSame(BusinessPaymentEventState::Ignored, $second->state);
        $this->assertSame('ignored_no_change', $second->last_error);
    }

    public function test_a_non_success_observation_on_a_void_document_still_settles_the_attempt(): void
    {
        $started = $this->startedPayment();
        app(DocumentManager::class)->void($started['fixture']['document'], 'Cancelled.');

        $this->event($started['payment'], 'payment_intent.canceled')->assertOk();

        // The attempt stops holding its slot; the document is untouched.
        $payment = $started['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $payment->status);
        $this->assertNull($payment->active_schedule_item_id);
        $this->assertSame(DocumentStatus::Void, $started['fixture']['document']->refresh()->status);
    }

    // =================================================================
    // `failed` is not final at the provider
    // =================================================================

    public function test_a_failed_attempt_emits_one_failure_event_and_a_replay_emits_none(): void
    {
        Event::fake([DocumentPaymentFailed::class]);
        $started = $this->startedPayment();

        $this->event($started['payment'], 'payment_intent.payment_failed', ['event_id' => 'evt_f1'])->assertOk();
        $this->event($started['payment'], 'payment_intent.payment_failed', ['event_id' => 'evt_f2'])->assertOk();

        $payment = $started['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $payment->status);
        $this->assertNotNull($payment->active_schedule_item_id, 'A decline leaves the intent retryable, so the row keeps the item (FailedPaymentRetryTest).');

        Event::assertDispatchedTimes(DocumentPaymentFailed::class, 1);
        Event::assertDispatched(DocumentPaymentFailed::class, function (DocumentPaymentFailed $event) use ($started, $payment) {
            $document = $started['fixture']['document'];

            return $event->documentId === (int) $document->id
                && $event->paymentId === (int) $payment->id
                && $event->businessId === (int) $document->business_id
                && $event->businessLocationId === (int) $document->business_location_id
                && $event->contactId === (int) $document->contact_id;
        });
    }

    public function test_a_provider_confirmed_success_on_a_failed_attempt_is_applied(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class, DocumentPaymentFailed::class]);
        $started = $this->startedPayment();

        // The card is declined…
        $this->event($started['payment'], 'payment_intent.payment_failed')->assertOk();
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $started['payment']->refresh()->status);

        // …but the SAME intent stays alive at Stripe, and the customer retries
        // in the same Payment Element with another card and it succeeds.
        $this->succeed($started['payment'])->assertOk();

        $payment = $started['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->failure_code, 'A success carries no failure code.');
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status,
            'Money was taken; the invoice must be paid.');

        Event::assertDispatchedTimes(DocumentPaymentFailed::class, 1);
        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(DocumentFullyPaid::class, 1);
    }

    public function test_a_failure_or_cancellation_can_never_overwrite_a_success(): void
    {
        $started = $this->startedPayment();
        $this->succeed($started['payment'])->assertOk();

        foreach (['payment_intent.payment_failed', 'payment_intent.canceled', 'payment_intent.processing', 'payment_intent.requires_action'] as $type) {
            $this->event($started['payment'], $type)->assertOk();
        }

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $started['payment']->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
        $this->assertSame(0, BusinessPaymentEvent::query()->where('state', BusinessPaymentEventState::Failed->value)->count());
    }

    public function test_a_second_capture_for_an_already_paid_item_is_recorded_and_pays_the_invoice_once(): void
    {
        // The product flow can no longer start a second intent beside a live or
        // failed one (FailedPaymentRetryTest). This pins what the ledger does
        // if a second intent for the item was ever captured anyway — e.g. an
        // intent created before this rule existed: both captures are real
        // money, so both are recorded and refundable, and the invoice is paid
        // ONCE.
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment();
        $first = $started['payment'];
        $document = $started['fixture']['document'];

        // Simulate the legacy shape: the first attempt was released
        // (cancelled), a second was started and paid, then the first's intent
        // turned out to have been captured too.
        DB::table('business_document_payments')->where('id', $first->id)->update(['status' => 'canceled']);
        app(PaymentManager::class)->start($this->accessFor($document->refresh(), $started['fixture']['token']));
        $second = BusinessDocumentPayment::query()->orderByDesc('id')->first();
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->succeed($second)->assertOk();

        $document->refresh();
        $this->assertSame(DocumentStatus::Paid, $document->status);
        $paidAt = $document->paid_at;

        DB::table('business_document_payments')->where('id', $first->id)->update(['status' => 'failed']);
        $this->succeed($first)->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $first->refresh()->status);
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $second->refresh()->status);
        $this->assertEquals($paidAt, $document->refresh()->paid_at);
        $this->assertSame(1, BusinessDocumentPaymentScheduleItem::query()->where('status', 'paid')->count());
        Event::assertDispatchedTimes(DocumentFullyPaid::class, 1);
        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 2);
        $this->assertSame(50000, app(PaymentManager::class)->refundableAmount($first));
    }

    // =================================================================
    // Events: stable, after-commit, identity-bearing, idempotent
    // =================================================================

    public function test_the_paid_events_carry_business_location_contact_document_and_payment_identity_once(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment();
        $document = $started['fixture']['document'];

        $this->succeed($started['payment'], ['event_id' => 'evt_a'])->assertOk();
        $this->succeed($started['payment'], ['event_id' => 'evt_b'])->assertOk();

        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertDispatched(DocumentPaymentSucceeded::class, fn (DocumentPaymentSucceeded $e) => $e->documentId === (int) $document->id
            && $e->paymentId === (int) $started['payment']->id
            && $e->businessId === (int) $document->business_id
            && $e->businessLocationId === (int) $document->business_location_id
            && $e->contactId === (int) $document->contact_id);

        Event::assertDispatchedTimes(DocumentFullyPaid::class, 1);
        Event::assertDispatched(DocumentFullyPaid::class, fn (DocumentFullyPaid $e) => $e->documentId === (int) $document->id
            && $e->businessId === (int) $document->business_id
            && $e->businessLocationId === (int) $document->business_location_id
            && $e->contactId === (int) $document->contact_id);
    }

    public function test_a_deposit_emits_a_payment_event_but_not_the_fully_paid_event(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment($this->depositAndBalance());

        $this->succeed($started['payment'])->assertOk();

        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertNotDispatched(DocumentFullyPaid::class);
        $this->assertNotSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
    }

    public function test_a_rolled_back_outcome_emits_no_event(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->startedPayment();

        // The finalizer's own transaction rolls back when anything inside it
        // throws; the events are after-commit, so nothing may be emitted.
        try {
            DB::transaction(function () use ($started) {
                $payment = $started['payment'];
                app(\App\Library\Payments\PaymentFinalizer::class)->apply($payment, new \App\Library\Payments\PaymentIntentSnapshot(
                    providerPaymentIntentId: (string) $payment->provider_payment_intent_id,
                    status: BusinessDocumentPaymentStatus::Succeeded,
                    amountMinor: (int) $payment->amount_minor,
                    currencyCode: (string) $payment->currency_code,
                    connectedAccountId: (string) BusinessStripeConnection::query()->find($payment->business_stripe_connection_id)->stripe_account_id,
                    operationId: (string) $payment->local_idempotency_key,
                ));

                throw new \RuntimeException('outer failure');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(DocumentPaymentSucceeded::class);
        Event::assertNotDispatched(DocumentFullyPaid::class);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
    }

    // =================================================================
    // The provider object, the browser, and foreign provider ids
    // =================================================================

    public function test_the_intent_carries_tenant_traceability_metadata_and_never_loses_the_operation_id(): void
    {
        $started = $this->startedPayment();
        $document = $started['fixture']['document'];
        $tenant = $started['fixture']['tenant'];

        $create = $this->gateway->callsOf('createPaymentIntent')[0]['args'];

        $this->assertSame([
            'app_business_uid' => (string) $tenant['business']->uid,
            'app_location_uid' => (string) $tenant['location']->uid,
            'app_document_uid' => (string) $document->uid,
        ], $create['metadata']);
        $this->assertSame((string) $started['payment']->local_idempotency_key, $create['operation_id']);

        // No numeric id, no PII reaches the provider.
        foreach ($create['metadata'] as $value) {
            $this->assertDoesNotMatchRegularExpression('/^\d+$/', $value);
            $this->assertStringNotContainsString('@', $value);
        }
    }

    public function test_a_reconciliation_re_drive_sends_identical_metadata_under_the_same_key(): void
    {
        config(['documents.stale_payment_minutes' => 30]);
        $this->gateway->loseNextCreateResponse = true;

        $fixture = $this->payableDocument();
        try {
            app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
        } catch (\Throwable) {
        }
        $payment = BusinessDocumentPayment::query()->sole();
        $this->assertNull($payment->provider_payment_intent_id, 'The create response was lost.');
        DB::table('business_document_payments')->where('id', $payment->id)->update(['updated_at' => now()->subHours(2)]);

        app(PaymentManager::class)->reconcileStalePayments(10);

        $creates = $this->gateway->callsOf('createPaymentIntent');
        $this->assertCount(2, $creates);
        $this->assertSame($creates[0]['args']['idempotency_key'], $creates[1]['args']['idempotency_key']);
        $this->assertSame($creates[0]['args']['metadata'], $creates[1]['args']['metadata'],
            'A re-drive must send byte-identical parameters or Stripe rejects the reused key.');
    }

    public function test_a_browser_supplied_amount_currency_or_account_cannot_change_the_charge(): void
    {
        $fixture = $this->payableDocument();

        $this->postJson($this->payUrl($fixture['document'], $fixture['token']), [
            'amount' => 1, 'amount_minor' => 1, 'currency' => 'EUR', 'currency_code' => 'EUR', 'price' => 1,
        ])->assertOk();

        $create = $this->gateway->callsOf('createPaymentIntent')[0]['args'];
        $this->assertSame(50000, $create['amount'], 'The amount comes from the persisted schedule item only.');
        $this->assertSame('USD', $create['currency']);

        // A request that does try to name the account is refused outright and
        // starts nothing further.
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']), ['account' => 'acct_attacker'])->assertStatus(422);
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_an_event_pairing_this_intent_with_another_connected_account_fails_closed(): void
    {
        $victim = $this->startedPayment();
        $other = $this->payableDocument(null, true, 'acct_other_biz');

        // The attacker's account asserts the VICTIM's intent id.
        $this->event($victim['payment'], 'payment_intent.succeeded', ['account' => 'acct_other_biz'])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Created, $victim['payment']->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $victim['fixture']['document']->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $other['document']->refresh()->status);
        $this->assertSame('account_mismatch', BusinessPaymentEvent::query()->sole()->last_error);
    }

    public function test_an_unknown_provider_intent_changes_nothing(): void
    {
        $started = $this->startedPayment();

        $this->event($started['payment'], 'payment_intent.succeeded', ['intent' => 'pi_not_ours'])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Created, $started['payment']->refresh()->status);
        $this->assertSame('no_matching_local_record', BusinessPaymentEvent::query()->sole()->last_error);
    }

    // =================================================================
    // Reconciliation is fair
    // =================================================================

    public function test_reconciliation_rotates_through_every_stale_attempt_instead_of_re_reading_the_first(): void
    {
        config(['documents.stale_payment_minutes' => 30]);
        $a = $this->startedPayment();
        $b = $this->startedPayment();

        // Both are abandoned; A is the older one. The provider still says
        // "waiting", so neither reaches a terminal state.
        DB::table('business_document_payments')->where('id', $a['payment']->id)->update(['updated_at' => now()->subHours(3)]);
        DB::table('business_document_payments')->where('id', $b['payment']->id)->update(['updated_at' => now()->subHours(2)]);

        $manager = app(PaymentManager::class);
        $manager->reconcileStalePayments(1);
        $manager->reconcileStalePayments(1);

        $retrieved = array_map(fn ($call) => $call['args']['intent'], $this->gateway->callsOf('retrievePaymentIntent'));
        $this->assertSame([
            (string) $a['payment']->provider_payment_intent_id,
            (string) $b['payment']->provider_payment_intent_id,
        ], $retrieved, 'A one-row batch must reach the second attempt, not poll the first twice.');
    }
}
