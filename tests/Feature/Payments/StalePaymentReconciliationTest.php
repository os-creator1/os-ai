<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Exceptions\Payments\StripeConnectException;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §7.5 — the sweep asks the provider and hands the
 * answer to the shared finalizer. It introduces NO second authority, and it
 * never invents a terminal state because time passed.
 */
class StalePaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
        config(['documents.enabled' => true, 'documents.stale_payment_minutes' => 30]);
    }

    /**
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    private function abandonedAttempt(BusinessDocumentPaymentStatus $status = BusinessDocumentPaymentStatus::Created): array
    {
        $this->gateway->intentStatus = $status;
        $fixture = $this->payableDocument();
        app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame($status, $payment->status);

        return ['fixture' => $fixture, 'payment' => $payment];
    }

    private function age(BusinessDocumentPayment $payment, int $minutes = 60): void
    {
        DB::table('business_document_payments')->where('id', $payment->id)
            ->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    // =================================================================
    // §7.5 — provider truth, through the shared finalizer
    // =================================================================

    public function test_a_stale_created_attempt_is_resolved_from_provider_truth(): void
    {
        $abandoned = $this->abandonedAttempt();
        $this->age($abandoned['payment']);

        // The customer really did pay; we just never heard.
        $this->gateway->setIntentStatus((string) $abandoned['payment']->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Succeeded);

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $abandoned['payment']->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $abandoned['fixture']['document']->refresh()->status);
        $this->assertSame(['retrievePaymentIntent'], array_values(array_unique(array_map(
            fn ($call) => $call['method'],
            array_filter($this->gateway->calls, fn ($c) => str_contains($c['method'], 'PaymentIntent') && $c['method'] !== 'createPaymentIntent')
        ))));
    }

    public function test_a_late_successful_authentication_still_settles(): void
    {
        $abandoned = $this->abandonedAttempt(BusinessDocumentPaymentStatus::RequiresAction);
        $this->age($abandoned['payment'], 240);

        $this->gateway->setIntentStatus((string) $abandoned['payment']->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Succeeded);

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $abandoned['payment']->refresh()->status);
        $item = BusinessDocumentPaymentScheduleItem::query()->sole();
        $this->assertSame(PaymentScheduleItemStatus::Paid, $item->status);
    }

    public function test_the_sweep_never_invents_a_terminal_state_because_time_passed(): void
    {
        $abandoned = $this->abandonedAttempt(BusinessDocumentPaymentStatus::RequiresAction);
        $this->age($abandoned['payment'], 60 * 24 * 7);

        // The provider still says the authentication is outstanding.
        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(100));

        $payment = $abandoned['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::RequiresAction, $payment->status,
            'A week of silence is not a failure.');
        $this->assertNotNull($payment->active_schedule_item_id,
            'and the slot stays held, so no second charge can be started against the same item.');
    }

    public function test_a_provider_confirmed_terminal_outcome_frees_the_schedule_item(): void
    {
        $abandoned = $this->abandonedAttempt();
        $this->age($abandoned['payment']);
        $this->gateway->setIntentStatus((string) $abandoned['payment']->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Canceled);

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $payment = $abandoned['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $payment->status);
        $this->assertNull($payment->active_schedule_item_id);

        // ...and exactly one new deliberate attempt is now permitted (§7.2).
        $this->gateway->intentStatus = BusinessDocumentPaymentStatus::Created;
        app(PaymentManager::class)->start($this->accessFor(
            $abandoned['fixture']['document']->refresh(), $abandoned['fixture']['token']));
        $this->assertSame(2, BusinessDocumentPayment::query()->count());
    }

    public function test_the_shared_finalizers_cross_checks_still_apply(): void
    {
        $abandoned = $this->abandonedAttempt();
        $this->age($abandoned['payment']);
        $intentId = (string) $abandoned['payment']->provider_payment_intent_id;

        // The provider answers about a DIFFERENT connected account.
        $this->gateway->intents[$intentId]['account'] = 'acct_somebodyelse';
        $this->gateway->setIntentStatus($intentId, BusinessDocumentPaymentStatus::Succeeded);

        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(100));
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $abandoned['payment']->refresh()->status,
            'The sweep decides nothing the finalizer would refuse.');
    }

    // =================================================================
    // Bounds and safety
    // =================================================================

    public function test_an_attempt_inside_the_threshold_is_not_touched(): void
    {
        $abandoned = $this->abandonedAttempt();
        $before = count($this->gateway->calls);

        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(100));

        $this->assertSame($before, count($this->gateway->calls), 'A fresh attempt is not worth a provider call.');
    }

    public function test_a_settled_attempt_is_never_revisited(): void
    {
        $captured = $this->capturedPayment();
        $this->age($captured['payment']);
        $before = count($this->gateway->calls);

        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(100));
        $this->assertSame($before, count($this->gateway->calls));
    }

    // =================================================================
    // §7.2.1 Case B — the attempt that never learned its intent id
    // =================================================================

    /**
     * An attempt whose creation returned uncertainly. The intent DOES exist at
     * the provider; the local row never learned its id. Without reconciliation
     * this row holds `active_schedule_item_id` until someone presses Pay again.
     *
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    private function uncertainAttempt(): array
    {
        $this->gateway->intentStatus = BusinessDocumentPaymentStatus::Created;
        $fixture = $this->payableDocument();
        $this->gateway->loseNextCreateResponse = true;

        try {
            app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
            $this->fail('The lost response must surface.');
        } catch (StripeConnectException) {
            // expected
        }

        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $this->assertNull($payment->provider_payment_intent_id);
        $this->assertNotNull($payment->active_schedule_item_id, 'The slot is held by an attempt nobody can resolve.');

        return ['fixture' => $fixture, 'payment' => $payment];
    }

    /**
     * The other half of the uncertain case: the request never reached Stripe
     * at all, so no intent exists there either.
     *
     * @return array{fixture: array, payment: BusinessDocumentPayment}
     */
    private function neverDeliveredAttempt(): array
    {
        $this->gateway->intentStatus = BusinessDocumentPaymentStatus::Created;
        $fixture = $this->payableDocument();
        $this->gateway->failCreateWith = StripeConnectException::providerFailed();

        try {
            app(PaymentManager::class)->start($this->accessFor($fixture['document'], $fixture['token']));
            $this->fail('The failed creation must surface.');
        } catch (StripeConnectException) {
            // expected
        }

        $this->gateway->failCreateWith = null;
        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $this->assertNull($payment->provider_payment_intent_id);
        $this->assertSame([], $this->gateway->intents, 'The request never reached the provider.');

        return ['fixture' => $fixture, 'payment' => $payment];
    }

    public function test_a_stale_attempt_with_no_intent_id_re_drives_the_identical_request(): void
    {
        $abandoned = $this->neverDeliveredAttempt();
        $this->age($abandoned['payment']);

        app(PaymentManager::class)->reconcileStalePayments(100);

        $creates = $this->gateway->callsOf('createPaymentIntent');
        $this->assertCount(2, $creates, 'One original attempt, one re-drive.');
        $this->assertSame($creates[0]['args'], $creates[1]['args'],
            'The re-drive sends byte-identical creation arguments: same account, amount, '
            . 'currency, idempotency key, operation id and description.');
        $this->assertSame('document-payment:' . $abandoned['payment']->uid, $creates[1]['args']['idempotency_key']);
        $this->assertSame((string) $abandoned['payment']->local_idempotency_key, $creates[1]['args']['operation_id']);
        $this->assertSame('acct_ready001', $creates[1]['args']['account'], 'On the row\'s own historical account.');
    }

    public function test_the_re_drive_never_creates_a_second_local_payment(): void
    {
        $abandoned = $this->neverDeliveredAttempt();
        $this->age($abandoned['payment']);

        app(PaymentManager::class)->reconcileStalePayments(100);

        $this->assertSame(1, BusinessDocumentPayment::query()->count(),
            'Reconciliation resolves the existing attempt; it never starts another.');
        $this->assertSame((string) $abandoned['payment']->uid,
            (string) BusinessDocumentPayment::query()->sole()->uid);
    }

    public function test_an_uncertain_creation_then_reconciliation_yields_one_provider_identity(): void
    {
        $abandoned = $this->uncertainAttempt();
        $this->assertCount(1, $this->gateway->intents, 'The provider really does hold one intent.');
        $originalIntentId = array_key_first($this->gateway->intents);

        $this->age($abandoned['payment']);
        app(PaymentManager::class)->reconcileStalePayments(100);

        $this->assertCount(1, $this->gateway->intents,
            'Stripe idempotency returns the ORIGINAL intent; no second one is ever created.');
        $this->assertSame($originalIntentId, (string) $abandoned['payment']->refresh()->provider_payment_intent_id,
            'and the local row is now bound to exactly that intent.');
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_a_still_nonterminal_re_drive_leaves_the_attempt_active(): void
    {
        $abandoned = $this->uncertainAttempt();
        $this->age($abandoned['payment']);

        // The provider still reports the intent as awaiting the customer.
        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(100));

        $payment = $abandoned['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $payment->status);
        $this->assertNotNull($payment->active_schedule_item_id,
            'A non-terminal provider answer is not a reason to free the slot.');
    }

    public function test_a_provider_confirmed_terminal_re_drive_frees_the_slot(): void
    {
        $abandoned = $this->uncertainAttempt();
        $this->age($abandoned['payment']);
        $this->gateway->setIntentStatus(array_key_first($this->gateway->intents),
            BusinessDocumentPaymentStatus::Canceled);

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $payment = $abandoned['payment']->refresh();
        $this->assertSame(BusinessDocumentPaymentStatus::Canceled, $payment->status);
        $this->assertNull($payment->active_schedule_item_id);

        // ...and exactly one new deliberate attempt is now permitted (§7.2).
        $this->gateway->intentStatus = BusinessDocumentPaymentStatus::Created;
        app(PaymentManager::class)->start($this->accessFor(
            $abandoned['fixture']['document']->refresh(), $abandoned['fixture']['token']));
        $this->assertSame(2, BusinessDocumentPayment::query()->count());
    }

    public function test_a_late_success_on_an_unknown_intent_still_finalizes(): void
    {
        $abandoned = $this->uncertainAttempt();
        $this->age($abandoned['payment'], 240);

        // The customer completed the payment long after we lost the response.
        $this->gateway->setIntentStatus(array_key_first($this->gateway->intents),
            BusinessDocumentPaymentStatus::Succeeded);

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $abandoned['payment']->refresh()->status);
        $this->assertSame(PaymentScheduleItemStatus::Paid,
            BusinessDocumentPaymentScheduleItem::query()->sole()->status);
        $this->assertSame(DocumentStatus::Paid, $abandoned['fixture']['document']->refresh()->status);
    }

    public function test_the_re_drive_happens_outside_every_transaction_and_lock(): void
    {
        $abandoned = $this->neverDeliveredAttempt();
        $this->age($abandoned['payment']);

        // The fake throws if any provider call is made above the test's own
        // transaction depth, so reaching the assertion at all is the proof.
        app(PaymentManager::class)->reconcileStalePayments(100);

        foreach ($this->gateway->callsOf('createPaymentIntent') as $call) {
            $this->assertSame($this->gateway->baselineTransactionLevel, $call['transaction_level']);
        }
    }

    public function test_a_provider_failure_on_one_row_does_not_abort_the_batch(): void
    {
        $first = $this->abandonedAttempt();
        $second = $this->abandonedAttempt();
        $this->age($first['payment']);
        $this->age($second['payment']);

        $this->gateway->setIntentStatus((string) $second['payment']->provider_payment_intent_id,
            BusinessDocumentPaymentStatus::Succeeded);

        // The first retrieval blows up; the second must still run.
        $failOn = (string) $first['payment']->provider_payment_intent_id;
        $this->gateway->duringRetrieveIntent = function (string $intentId) use ($failOn) {
            if ($intentId === $failOn) {
                throw StripeConnectException::providerFailed();
            }
        };

        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(100));

        $this->assertSame(BusinessDocumentPaymentStatus::Created, $first['payment']->refresh()->status);
        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $second['payment']->refresh()->status);
    }

    public function test_the_batch_is_bounded_by_the_limit(): void
    {
        foreach (range(1, 3) as $ignored) {
            $abandoned = $this->abandonedAttempt();
            $this->age($abandoned['payment']);
            $this->gateway->setIntentStatus((string) $abandoned['payment']->provider_payment_intent_id,
                BusinessDocumentPaymentStatus::Succeeded);
        }

        $this->assertSame(2, app(PaymentManager::class)->reconcileStalePayments(2));
        $this->assertSame(1, app(PaymentManager::class)->reconcileStalePayments(2));
        $this->assertSame(0, app(PaymentManager::class)->reconcileStalePayments(2));
    }
}
