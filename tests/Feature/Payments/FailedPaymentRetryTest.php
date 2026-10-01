<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentFailed;
use App\Events\DocumentPaymentSucceeded;
use App\Exceptions\Payments\PaymentStartException;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Payments & Invoices V1 correction round 1 — ONE canonical retryable-attempt
 * rule.
 *
 * `payment_intent.payment_failed` does not end a PaymentIntent: Stripe leaves
 * it in `requires_payment_method` and the customer can retry the SAME intent.
 * So a `failed` row is still the LIVE attempt for its schedule item — in the
 * application (PaymentManager::liveStatuses) and in the database (the
 * `active_schedule_item_id` generated column) — and:
 *
 *   start -> payment_failed -> start again -> later succeeded
 *
 * is ONE payment row, ONE PaymentIntent, ONE connected account, ONE
 * idempotency identity, ONE succeeded transition and ONE schedule settlement.
 * Only a provider-confirmed cancellation (a dead intent) releases the item for
 * a new attempt.
 */
class FailedPaymentRetryTest extends TestCase
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

    /** @return array{fixture: array, payment: BusinessDocumentPayment} */
    private function startedPayment(?array $schedule = null): array
    {
        $fixture = $this->payableDocument($schedule);
        app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));

        return ['fixture' => $fixture, 'payment' => BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail()];
    }

    private function event(BusinessDocumentPayment $payment, string $type, array $overrides = [])
    {
        [$body, $headers] = $this->webhookPayload(
            $type,
            $overrides['intent'] ?? (string) $payment->provider_payment_intent_id,
            (string) BusinessStripeConnection::query()->find($payment->business_stripe_connection_id)->stripe_account_id,
            $overrides['amount'] ?? (int) $payment->amount_minor,
            (string) $payment->currency_code,
            (string) $payment->local_idempotency_key,
            $overrides['event_id'] ?? null,
        );

        return $this->postWebhook($body, $headers);
    }

    private function restart(array $started)
    {
        return app(PaymentManager::class)->start($this->accessFor($started['fixture']['document']->refresh(), $started['fixture']['token']));
    }

    // =================================================================
    // The whole retry sequence is one attempt
    // =================================================================

    public function test_start_failed_start_again_then_succeeded_is_one_row_one_intent_and_one_settlement(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class, DocumentPaymentFailed::class]);
        $started = $this->startedPayment();
        $first = $started['payment'];
        $document = $started['fixture']['document'];

        $identity = [
            'id' => $first->id,
            'uid' => $first->uid,
            'intent' => $first->provider_payment_intent_id,
            'connection' => $first->business_stripe_connection_id,
            'key' => $first->local_idempotency_key,
        ];

        // The card is declined.
        $this->event($first, 'payment_intent.payment_failed')->assertOk();
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $first->refresh()->status);
        $this->assertSame((int) $first->schedule_item_id, (int) $first->active_schedule_item_id, 'A failed attempt still holds the item.');

        // The customer presses Pay again — twice.
        $a = $this->restart($started);
        $b = $this->restart($started);

        $this->assertSame($identity['uid'], $a->paymentUid);
        $this->assertSame($identity['uid'], $b->paymentUid);
        $this->assertSame(1, BusinessDocumentPayment::query()->count(), 'No new payment row merely because the local status is failed.');
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'), 'No second PaymentIntent was ever created.');

        $retrieved = $this->gateway->callsOf('retrievePaymentIntent');
        $this->assertNotEmpty($retrieved);
        foreach ($retrieved as $call) {
            $this->assertSame($identity['intent'], $call['args']['intent'], 'The SAME PaymentIntent was re-driven.');
            $this->assertSame(
                (string) BusinessStripeConnection::query()->find($identity['connection'])->stripe_account_id,
                $call['args']['account'],
                'On the SAME connected account.'
            );
        }
        $this->assertSame($identity['intent'] . '_secret_fake', $a->clientSecret, 'The browser gets that intent back to retry.');

        // Still failed, still the same identity.
        $first->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $first->status);
        $this->assertSame($identity['key'], $first->local_idempotency_key);

        // The retry on the same intent succeeds.
        $this->event($first, 'payment_intent.succeeded')->assertOk();
        $this->event($first, 'payment_intent.succeeded', ['event_id' => 'evt_replay'])->assertOk();

        $first->refresh();
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame($identity['id'], (int) $first->id);
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $first->status, 'The SAME row moved failed -> succeeded.');
        $this->assertNull($first->failure_code);
        $this->assertSame(1, BusinessDocumentPaymentScheduleItem::query()->where('status', PaymentScheduleItemStatus::Paid->value)->count(), 'One schedule settlement.');
        $this->assertSame(DocumentStatus::Paid, $document->refresh()->status);

        Event::assertDispatchedTimes(DocumentPaymentFailed::class, 1);
        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(DocumentFullyPaid::class, 1);
        $this->assertSame(1, BusinessPaymentEvent::query()->where('state', BusinessPaymentEventState::Processed->value)->where('event_type', 'payment_intent.succeeded')->count());

        // Nothing is left to pay.
        try {
            $this->restart($started);
            $this->fail('A paid invoice must refuse a further start.');
        } catch (PaymentStartException) {
        }
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_the_public_pay_endpoint_returns_the_same_attempt_after_a_decline(): void
    {
        $started = $this->startedPayment();
        $fixture = $started['fixture'];
        $first = $started['payment'];

        $this->event($first, 'payment_intent.payment_failed')->assertOk();

        $response = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk()->json();

        $this->assertSame((string) $first->uid, $response['payment_uid']);
        $this->assertSame((string) $first->provider_payment_intent_id . '_secret_fake', $response['client_secret']);
        $this->assertSame(50000, $response['amount_minor']);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_a_second_decline_of_the_same_intent_emits_no_second_failure_event(): void
    {
        Event::fake([DocumentPaymentFailed::class]);
        $started = $this->startedPayment();

        $this->event($started['payment'], 'payment_intent.payment_failed', ['event_id' => 'evt_d1'])->assertOk();
        $this->restart($started);
        $this->event($started['payment'], 'payment_intent.payment_failed', ['event_id' => 'evt_d2'])->assertOk();

        Event::assertDispatchedTimes(DocumentPaymentFailed::class, 1);
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $started['payment']->refresh()->status);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    // =================================================================
    // The database and the application agree on "live"
    // =================================================================

    public function test_the_database_refuses_a_second_row_beside_a_failed_one(): void
    {
        $started = $this->startedPayment();
        $first = $started['payment'];
        $this->event($first, 'payment_intent.payment_failed')->assertOk();

        $this->expectException(QueryException::class);

        DB::table('business_document_payments')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $first->business_id,
            'business_document_id' => $first->business_document_id,
            'schedule_item_id' => $first->schedule_item_id,
            'business_stripe_connection_id' => $first->business_stripe_connection_id,
            'local_idempotency_key' => 'document-payment:another',
            'amount_minor' => $first->amount_minor,
            'currency_code' => $first->currency_code,
            'status' => BusinessDocumentPaymentStatus::Created->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_application_live_list_equals_the_generated_column_for_every_status(): void
    {
        $started = $this->startedPayment();
        $payment = $started['payment'];

        foreach (BusinessDocumentPaymentStatus::cases() as $status) {
            DB::table('business_document_payments')->where('id', $payment->id)->update(['status' => $status->value]);
            $held = DB::table('business_document_payments')->where('id', $payment->id)->value('active_schedule_item_id') !== null;

            $this->assertSame(
                in_array($status->value, PaymentManager::liveStatuses(), true),
                $held,
                "The application and the database disagree about whether [{$status->value}] is a live attempt."
            );
        }

        $this->assertSame(['created', 'requires_action', 'processing', 'failed'], PaymentManager::liveStatuses());
    }

    // =================================================================
    // Only a dead intent releases the item
    // =================================================================

    public function test_a_provider_cancelled_intent_releases_the_item_for_exactly_one_new_attempt(): void
    {
        $started = $this->startedPayment();
        $first = $started['payment'];
        $this->event($first, 'payment_intent.payment_failed')->assertOk();

        // The provider says the intent is dead.
        $this->gateway->setIntentStatus((string) $first->provider_payment_intent_id, BusinessDocumentPaymentStatus::Canceled);

        $result = $this->restart($started);

        $first->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $first->status);
        $this->assertNull($first->active_schedule_item_id, 'A dead intent frees the slot.');
        $this->assertSame(2, BusinessDocumentPayment::query()->count());
        $second = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->uid, $second->uid);
        $this->assertSame($second->uid, $result->paymentUid);
        $this->assertNotSame($first->provider_payment_intent_id, $second->provider_payment_intent_id);
        $this->assertNotSame($first->local_idempotency_key, $second->local_idempotency_key);
        $this->assertCount(2, $this->gateway->callsOf('createPaymentIntent'));

        // The dead intent can never pay, even if an event claims it did.
        $this->event($first, 'payment_intent.succeeded')->assertOk();
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $first->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);

        // Further starts keep re-driving the new attempt: still exactly one.
        $this->restart($started);
        $this->assertSame(2, BusinessDocumentPayment::query()->count());

        $this->event($second, 'payment_intent.succeeded')->assertOk();
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
    }

    public function test_a_re_driven_failed_row_whose_intent_actually_succeeded_is_settled_not_replaced(): void
    {
        $started = $this->startedPayment();
        $first = $started['payment'];
        $this->event($first, 'payment_intent.payment_failed')->assertOk();

        // The customer's retry worked, but the webhook has not arrived yet.
        $this->gateway->setIntentStatus((string) $first->provider_payment_intent_id, BusinessDocumentPaymentStatus::Succeeded);

        try {
            $this->restart($started);
            $this->fail('Nothing is left to pay once the provider confirms success.');
        } catch (PaymentStartException $e) {
            $this->assertSame(PaymentStartException::NOTHING_PAYABLE, $e->reason);
        }

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $first->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    // =================================================================
    // Reconciliation covers failed attempts too
    // =================================================================

    public function test_the_sweep_settles_or_releases_a_stale_failed_attempt_from_provider_truth(): void
    {
        config(['documents.stale_payment_minutes' => 30]);

        $settled = $this->startedPayment();
        $released = $this->startedPayment();
        $waiting = $this->startedPayment();
        foreach ([$settled, $released, $waiting] as $s) {
            $this->event($s['payment'], 'payment_intent.payment_failed')->assertOk();
            DB::table('business_document_payments')->where('id', $s['payment']->id)->update(['updated_at' => now()->subHours(3)]);
        }

        $this->gateway->setIntentStatus((string) $settled['payment']->provider_payment_intent_id, BusinessDocumentPaymentStatus::Succeeded);
        $this->gateway->setIntentStatus((string) $released['payment']->provider_payment_intent_id, BusinessDocumentPaymentStatus::Canceled);

        $this->assertSame(2, app(PaymentManager::class)->reconcileStalePayments(100), 'Succeeded and canceled are terminal; a still-retryable intent is not.');

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $settled['payment']->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $settled['fixture']['document']->refresh()->status, 'A retry that succeeded is recovered even if its webhook never arrived.');
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $released['payment']->refresh()->status);
        $this->assertNull($released['payment']->active_schedule_item_id);
        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $waiting['payment']->refresh()->status, 'Time passing is never a decision.');
        $this->assertNotNull($waiting['payment']->active_schedule_item_id);
    }

    // =================================================================
    // What must not regress
    // =================================================================

    public function test_a_late_capture_on_a_failed_attempt_of_a_void_document_is_recorded_and_refundable(): void
    {
        $started = $this->startedPayment();
        $this->event($started['payment'], 'payment_intent.payment_failed')->assertOk();
        app(DocumentManager::class)->void($started['fixture']['document'], 'Cancelled.');

        $this->event($started['payment'], 'payment_intent.succeeded')->assertOk();

        $payment = $started['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->status);
        $this->assertSame(DocumentStatus::Void, $started['fixture']['document']->refresh()->status, 'A terminal document never reopens.');
        $this->assertSame(50000, app(PaymentManager::class)->refundableAmount($payment));
        $this->assertSame('recorded_against_terminal_document', BusinessPaymentEvent::query()->where('event_type', 'payment_intent.succeeded')->sole()->last_error);
    }

    public function test_the_cross_checks_still_guard_a_success_on_a_failed_attempt(): void
    {
        $started = $this->startedPayment();
        $this->event($started['payment'], 'payment_intent.payment_failed')->assertOk();

        $this->event($started['payment'], 'payment_intent.succeeded', ['amount' => 1])->assertOk();
        $this->assertSame('amount_mismatch', BusinessPaymentEvent::query()->where('event_type', 'payment_intent.succeeded')->sole()->last_error);

        $this->event($started['payment'], 'payment_intent.succeeded', ['intent' => 'pi_someone_elses'])->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $started['payment']->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
    }

    public function test_a_deposit_failure_retries_the_deposit_and_the_balance_stays_locked(): void
    {
        $started = $this->startedPayment($this->depositAndBalance());
        $this->event($started['payment'], 'payment_intent.payment_failed')->assertOk();

        $result = $this->restart($started);

        $this->assertSame((string) $started['payment']->uid, $result->paymentUid);
        $this->assertSame(20000, $result->amountMinor, 'The deposit is what is retried; the balance is not payable first.');
        $this->assertSame(1, BusinessDocumentPayment::query()->count());

        $this->event($started['payment'], 'payment_intent.succeeded')->assertOk();
        $this->assertNotSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);

        // Now the balance — a NEW row for a DIFFERENT item.
        $this->restart($started);
        $this->assertSame(2, BusinessDocumentPayment::query()->count());
    }
}
