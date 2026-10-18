<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus as Status;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentSucceeded;
use App\Exceptions\Payments\StripeConnectException;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessStripeConnection;
use App\Notifications\Documents\PaymentReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * The customer's return from Stripe's Payment Element.
 *
 * The browser coming back proves nothing. The status endpoint resolves the
 * attempt from the verified document link and the persisted payment rows,
 * reconciles THAT attempt through the shared finalizer, and never creates a
 * payment row, a PaymentIntent or a client secret.
 */
class PaymentReturnStatusTest extends TestCase
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

    private function statusUrl(BusinessDocument $document, string $token): string
    {
        return route('public.documents.pay.status', ['uid' => $document->uid, 'token' => $token]);
    }

    /** @return array{fixture: array, payment: BusinessDocumentPayment} */
    private function started(?array $schedule = null, ?array $fixture = null): array
    {
        $fixture ??= $this->payableDocument($schedule);
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();

        return [
            'fixture' => $fixture,
            'payment' => BusinessDocumentPayment::query()->where('business_document_id', $fixture['document']->id)->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function fetchStatus(array $started)
    {
        return $this->getJson($this->statusUrl($started['fixture']['document'], $started['fixture']['token']));
    }

    private function webhook(BusinessDocumentPayment $payment, string $type = 'payment_intent.succeeded', ?string $eventId = null)
    {
        [$body, $headers] = $this->webhookPayload(
            $type,
            (string) $payment->provider_payment_intent_id,
            (string) BusinessStripeConnection::query()->find($payment->business_stripe_connection_id)->stripe_account_id,
            (int) $payment->amount_minor,
            (string) $payment->currency_code,
            (string) $payment->local_idempotency_key,
            $eventId,
        );

        return $this->postWebhook($body, $headers);
    }

    private function providerSays(BusinessDocumentPayment $payment, Status $status): void
    {
        $this->gateway->setIntentStatus((string) $payment->provider_payment_intent_id, $status);
    }

    // 1 -----------------------------------------------------------------

    public function test_success_with_the_webhook_already_processed_reports_paid_in_full(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);
        $this->webhook($started['payment'])->assertOk();

        $this->fetchStatus($started)->assertOk()->assertJson([
            'state' => 'succeeded',
            'uncertain' => false,
            'amount_minor' => 50000,
            'currency_code' => 'USD',
            'document_paid' => true,
            'remaining_minor' => 0,
            'can_retry' => false,
        ]);

        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
    }

    // 2 -----------------------------------------------------------------

    public function test_the_browser_returning_before_the_webhook_is_reconciled_from_the_provider(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);

        // No webhook has arrived: local state is still `created`.
        $this->assertSame(Status::Created, $started['payment']->refresh()->status);

        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'succeeded', 'document_paid' => true]);

        $this->assertSame(Status::Succeeded, $started['payment']->refresh()->status);
        $this->assertSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
        $this->assertSame(1, BusinessDocumentPayment::query()->count(), 'Reconciliation never creates an attempt.');
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    // 3 -----------------------------------------------------------------

    public function test_a_delayed_webhook_eventually_succeeds_with_one_receipt_and_one_transition(): void
    {
        $started = $this->started();

        // Bank still working on it.
        $this->providerSays($started['payment'], Status::Processing);
        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'processing', 'document_paid' => false, 'can_retry' => false]);
        $this->assertSame(DocumentStatus::Signed, $started['fixture']['document']->refresh()->status);

        // Then it clears; the browser polls first, the webhook lands afterwards.
        $this->providerSays($started['payment'], Status::Succeeded);
        $this->fetchStatus($started)->assertJson(['state' => 'succeeded']);
        $this->webhook($started['payment'])->assertOk();
        $this->webhook($started['payment'], 'payment_intent.succeeded', 'evt_late_replay')->assertOk();
        $this->fetchStatus($started)->assertJson(['state' => 'succeeded']);

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(1, BusinessDocumentPaymentScheduleItem::query()->where('status', PaymentScheduleItemStatus::Paid->value)->count());
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function test_status_polling_and_the_webhook_emit_exactly_one_success_transition(): void
    {
        Event::fake([DocumentPaymentSucceeded::class, DocumentFullyPaid::class]);
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);

        $this->fetchStatus($started)->assertOk();
        $this->fetchStatus($started)->assertOk();
        $this->webhook($started['payment'])->assertOk();
        $this->fetchStatus($started)->assertOk();

        Event::assertDispatchedTimes(DocumentPaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(DocumentFullyPaid::class, 1);
    }

    // 4 -----------------------------------------------------------------

    public function test_reopening_the_link_after_payment_shows_the_settled_state_and_no_form(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);
        $this->webhook($started['payment'])->assertOk();

        $page = $this->get($this->publicUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk();
        $page->assertSee('Payment received', false)
            ->assertDontSee('data-role="pay-button"', false)
            ->assertDontSee('data-role="payment-element"', false);

        // Starting again is a clear "already settled", not the generic refusal.
        $this->postJson($this->payUrl($started['fixture']['document'], $started['fixture']['token']))
            ->assertStatus(409)
            ->assertJson(['error' => 'already_settled']);

        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    // 5 -----------------------------------------------------------------

    public function test_a_declined_card_reports_failed_and_retries_the_same_intent(): void
    {
        $started = $this->started();
        $payment = $started['payment'];
        $this->providerSays($payment, Status::Failed);

        $this->fetchStatus($started)->assertOk()->assertJson([
            'state' => 'failed',
            'document_paid' => false,
            'can_retry' => true,
        ]);

        // Retry goes through the normal Pay action and re-drives the SAME intent.
        $retry = $this->postJson($this->payUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk()->json();
        $this->assertSame((string) $payment->uid, $retry['payment_uid']);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));

        // The retry works.
        $this->providerSays($payment, Status::Succeeded);
        $this->fetchStatus($started)->assertJson(['state' => 'succeeded', 'document_paid' => true]);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    // 6 -----------------------------------------------------------------

    public function test_a_canceled_attempt_is_reported_and_status_never_starts_a_new_one(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Canceled);

        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'canceled', 'can_retry' => true]);
        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'canceled']);

        $this->assertSame(1, BusinessDocumentPayment::query()->count(), 'Reading status creates nothing.');
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    public function test_an_incomplete_authentication_is_not_reported_as_success(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::RequiresAction);

        $this->fetchStatus($started)->assertOk()->assertJson([
            'state' => 'requires_action',
            'document_paid' => false,
            'can_retry' => true,
        ]);

        $this->assertSame(DocumentStatus::Signed, $started['fixture']['document']->refresh()->status);
    }

    public function test_a_document_with_no_attempt_reports_none_and_creates_nothing(): void
    {
        $fixture = $this->payableDocument();

        $this->getJson($this->statusUrl($fixture['document'], $fixture['token']))
            ->assertOk()->assertJson(['state' => 'none', 'can_retry' => false]);

        $this->assertSame(0, BusinessDocumentPayment::query()->count());
        $this->assertSame([], $this->gateway->callsOf('createPaymentIntent'));
    }

    // 7 -----------------------------------------------------------------

    public function test_a_succeeded_deposit_leaves_the_balance_unpaid(): void
    {
        $started = $this->started($this->depositAndBalance());
        $this->providerSays($started['payment'], Status::Succeeded);

        $this->fetchStatus($started)->assertOk()->assertJson([
            'state' => 'succeeded',
            'amount_minor' => 20000,
            'document_paid' => false,
            'remaining_minor' => 30000,
        ]);

        $document = $started['fixture']['document']->refresh();
        $this->assertNotSame(DocumentStatus::Paid, $document->status);

        // The reopened page shows the received/remaining split and offers the balance.
        $this->get($this->publicUrl($document, $started['fixture']['token']))
            ->assertOk()
            ->assertSee('data-role="payment-received-summary"', false)
            ->assertSee('300.00', false);
    }

    // 8 -----------------------------------------------------------------

    public function test_repeated_returns_and_double_clicks_never_create_a_second_charge(): void
    {
        $started = $this->started();

        for ($i = 0; $i < 4; $i++) {
            $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'created']);
        }

        // Double click on Pay now.
        $a = $this->postJson($this->payUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk()->json();
        $b = $this->postJson($this->payUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk()->json();

        $this->assertSame($a['payment_uid'], $b['payment_uid']);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    public function test_the_status_endpoint_never_exposes_secrets_or_provider_identifiers(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);

        $body = $this->fetchStatus($started)->assertOk()->getContent();

        $this->assertStringNotContainsString('secret', strtolower($body));
        $this->assertStringNotContainsString((string) $started['payment']->provider_payment_intent_id, $body);
        $this->assertStringNotContainsString('acct_', $body);
        $this->assertStringNotContainsString((string) $started['payment']->uid, $body);
    }

    public function test_an_unreachable_provider_is_reported_uncertain_and_changes_nothing(): void
    {
        $started = $this->started();
        $this->gateway->duringRetrieveIntent = function () {
            throw StripeConnectException::providerFailed();
        };

        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'created', 'uncertain' => true, 'document_paid' => false]);

        $this->assertSame(Status::Created, $started['payment']->refresh()->status);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_a_mismatched_provider_answer_is_refused_and_not_marked_paid(): void
    {
        $started = $this->started();
        $intent = (string) $started['payment']->provider_payment_intent_id;
        $this->gateway->intents[$intent]['amount'] = 100; // not what we asked for
        $this->providerSays($started['payment'], Status::Succeeded);

        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'created', 'uncertain' => true, 'document_paid' => false]);

        $this->assertSame(Status::Created, $started['payment']->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $started['fixture']['document']->refresh()->status);
    }

    // 9 -----------------------------------------------------------------

    public function test_invalid_rotated_and_expired_tokens_get_the_uniform_refusal(): void
    {
        $started = $this->started();
        $document = $started['fixture']['document'];

        $this->getJson($this->statusUrl($document, 'not-the-token'))->assertNotFound();

        $this->getJson($this->statusUrl($document, $started['fixture']['token']))->assertOk();

        \DB::table('business_documents')->where('id', $document->id)->update(['access_token_expires_at' => now()->subMinute()]);
        $this->getJson($this->statusUrl($document, $started['fixture']['token']))->assertNotFound();
    }

    public function test_a_token_from_another_document_cannot_read_this_documents_payment(): void
    {
        $mine = $this->started();
        $other = $this->payableDocument();

        $this->getJson($this->statusUrl($mine['fixture']['document'], $other['token']))->assertNotFound();
        $this->getJson($this->statusUrl($other['document'], $mine['fixture']['token']))->assertNotFound();
    }

    // 10 ----------------------------------------------------------------

    public function test_forged_payment_references_are_ignored_and_other_businesses_are_never_reached(): void
    {
        $mine = $this->started();
        $other = $this->started();

        $this->providerSays($other['payment'], Status::Succeeded);

        // Forged references in the query string, as a returning browser could carry.
        $forged = $this->statusUrl($mine['fixture']['document'], $mine['fixture']['token'])
            . '?payment_intent=' . $other['payment']->provider_payment_intent_id
            . '&payment_intent_client_secret=' . $other['payment']->provider_payment_intent_id . '_secret_fake'
            . '&payment_uid=' . $other['payment']->uid
            . '&redirect_status=succeeded';

        $this->getJson($forged)->assertOk()->assertJson(['state' => 'created', 'document_paid' => false]);

        // Only this document's own intent was ever asked about.
        foreach ($this->gateway->callsOf('retrievePaymentIntent') as $call) {
            $this->assertSame((string) $mine['payment']->provider_payment_intent_id, $call['args']['intent']);
        }

        $this->assertSame(Status::Created, $other['payment']->refresh()->status, 'The other Business\'s payment is untouched.');
        $this->assertNotSame(DocumentStatus::Paid, $other['fixture']['document']->refresh()->status);
        $this->assertNotSame(DocumentStatus::Paid, $mine['fixture']['document']->refresh()->status);
    }

    public function test_a_redirect_status_of_succeeded_alone_never_marks_anything_paid(): void
    {
        $started = $this->started();

        $this->getJson($this->statusUrl($started['fixture']['document'], $started['fixture']['token']) . '?redirect_status=succeeded')
            ->assertOk()->assertJson(['state' => 'created', 'document_paid' => false]);

        $this->get($this->publicUrl($started['fixture']['document'], $started['fixture']['token']) . '?payment_return=1&redirect_status=succeeded')
            ->assertOk();

        $this->assertSame(Status::Created, $started['payment']->refresh()->status);
        $this->assertSame(DocumentStatus::Signed, $started['fixture']['document']->refresh()->status);
    }

    // 11 ----------------------------------------------------------------

    public function test_the_page_uses_a_deterministic_return_url_and_hides_the_form_while_processing(): void
    {
        $started = $this->started();
        $url = $this->publicUrl($started['fixture']['document'], $started['fixture']['token']);

        $this->get($url)->assertOk()
            ->assertSee("'?payment_return=1'", false)
            ->assertSee('Confirming your payment', false)
            ->assertSee('data-role="pay-button"', false);

        // An in-flight (processing) attempt: calm notice, no second Pay button.
        $this->providerSays($started['payment'], Status::Processing);
        $this->fetchStatus($started)->assertJson(['state' => 'processing']);

        $this->get($url)->assertOk()
            ->assertSee('data-role="payment-processing"', false)
            ->assertDontSee('data-role="pay-button"', false);

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    public function test_receipts_are_sent_once_across_status_checks_and_webhook_replays(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);

        $this->webhook($started['payment'], 'payment_intent.succeeded', 'evt_a')->assertOk();
        $this->fetchStatus($started)->assertOk();
        $this->webhook($started['payment'], 'payment_intent.succeeded', 'evt_b')->assertOk();
        $this->fetchStatus($started)->assertOk();

        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }

    // -----------------------------------------------------------------
    // Sidebar / summary agrees with the main payment section
    // -----------------------------------------------------------------

    private function page(array $started): \Illuminate\Testing\TestResponse
    {
        return $this->get($this->publicUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk();
    }

    public function test_a_signed_unpaid_page_offers_pay_now_in_the_sidebar_and_wires_the_sync(): void
    {
        $started = $this->started();

        $this->page($started)
            ->assertSee('Signed — payment due', false)
            ->assertSee('<a class="pd-cta" data-role="summary-action"', false)
            // One mechanism: the existing status endpoint drives the sidebar too.
            ->assertSee('syncSummary', false)
            ->assertSee("'pageshow'", false);
    }

    public function test_full_payment_makes_the_sidebar_and_main_section_agree_on_reopening(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);
        $this->fetchStatus($started)->assertOk()->assertJson(['document_paid' => true, 'payable_now' => false]);

        $this->page($started)
            ->assertSee('data-role="summary-status">Paid<', false)
            ->assertDontSee('Signed — payment due', false)
            ->assertDontSee('<a class="pd-cta" data-role="summary-action"', false)
            ->assertSee('Payment received', false);
    }

    public function test_a_deposit_shows_received_and_balance_due_and_never_paid(): void
    {
        $started = $this->started($this->depositAndBalance());
        $this->providerSays($started['payment'], Status::Succeeded);

        $this->fetchStatus($started)->assertOk()->assertJson([
            'document_paid' => false, 'remaining_minor' => 30000, 'payable_now' => true,
        ]);

        $this->page($started)
            ->assertSee('Deposit received — balance due', false)
            ->assertDontSee('data-role="summary-status">Paid<', false)
            ->assertSee('<a class="pd-cta" data-role="summary-action"', false)
            ->assertSee('data-role="payment-received-summary"', false);
    }

    public function test_processing_is_pending_everywhere_with_no_pay_action(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Processing);
        $this->fetchStatus($started)->assertOk()->assertJson(['state' => 'processing', 'payable_now' => false]);

        $this->page($started)
            ->assertSee('data-role="payment-processing"', false)
            ->assertSee('data-role="summary-status">Payment processing<', false)
            ->assertDontSee('Signed — payment due', false)
            ->assertDontSee('<a class="pd-cta" data-role="summary-action"', false)
            ->assertDontSee('data-role="pay-button"', false);
    }

    public function test_an_uncertain_answer_never_offers_a_payable_action(): void
    {
        $started = $this->started();
        $this->gateway->duringRetrieveIntent = function () {
            throw StripeConnectException::providerFailed();
        };

        $this->fetchStatus($started)->assertOk()->assertJson(['uncertain' => true, 'payable_now' => false, 'document_paid' => false]);
    }

    public function test_repeated_status_polling_creates_no_charge_and_no_duplicate_receipt(): void
    {
        $started = $this->started();
        $this->providerSays($started['payment'], Status::Succeeded);

        for ($i = 0; $i < 5; $i++) {
            $this->fetchStatus($started)->assertOk()->assertJson(['document_paid' => true]);
            $this->page($started);
        }

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }
}
