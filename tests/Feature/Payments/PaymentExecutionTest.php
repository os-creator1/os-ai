<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Exceptions\Payments\PaymentStartException;
use App\Exceptions\Payments\StripeConnectException;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentFinalizer;
use App\Library\Payments\PaymentManager;
use App\Library\Payments\ProviderStatusMap;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 Sub-slice E — payment execution, PaymentIntents,
 * verified lane-B webhooks and finalization.
 */
class PaymentExecutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    private function manager(): PaymentManager
    {
        return app(PaymentManager::class);
    }

    // =================================================================
    // (a) the public GET has no payment side effect at all
    // =================================================================

    public function test_the_public_get_creates_no_payment_row_and_makes_no_provider_call(): void
    {
        $fixture = $this->payableDocument();
        $this->gateway->calls = [];

        $this->get($this->publicUrl($fixture['document'], $fixture['token']))->assertOk();
        $this->get($this->publicUrl($fixture['document'], $fixture['token']))->assertOk();

        $this->assertSame(0, BusinessDocumentPayment::query()->count());
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_the_public_page_shows_the_amount_due_and_the_pay_action(): void
    {
        $fixture = $this->payableDocument();

        $html = $this->get($this->publicUrl($fixture['document'], $fixture['token']))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="amount-due"', $html);
        $this->assertStringContainsString('500.00 USD', $html);
        $this->assertStringContainsString('data-role="payment-element"', $html);
    }

    public function test_an_unsigned_requires_signature_document_offers_no_pay_action(): void
    {
        $fixture = $this->payableDocument(sign: false);

        $html = $this->get($this->publicUrl($fixture['document'], $fixture['token']))->assertOk()->getContent();

        $this->assertStringContainsString('can be paid once it has been signed', $html);
        $this->assertStringNotContainsString('data-role="pay-button"', $html);
    }

    // =================================================================
    // (b)(c)(d) payment-start response, input schema, secret containment
    // =================================================================

    public function test_payment_start_returns_the_client_secret_of_the_exact_durable_attempt(): void
    {
        $fixture = $this->payableDocument();

        $body = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk()->json();

        $payment = BusinessDocumentPayment::query()->sole();
        $this->assertSame((string) $payment->uid, $body['payment_uid']);
        $this->assertSame($payment->provider_payment_intent_id . '_secret_fake', $body['client_secret']);
        $this->assertSame('acct_ready001', $body['stripe_account']);
        $this->assertSame(50000, $body['amount_minor']);
        $this->assertArrayNotHasKey('secret_key', $body);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cardShapedInput(): array
    {
        return [
            'card number' => [['card_number' => '4242424242424242']],
            'bare pan' => [['anything' => '4242 4242 4242 4242']],
            'cvc' => [['cvc' => '123']],
            'expiry' => [['exp_month' => '12', 'exp_year' => '2030']],
            'payment method' => [['payment_method' => 'pm_card_visa']],
            'stripe account' => [['stripe_account' => 'acct_attacker']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cardShapedInput')]
    public function test_card_shaped_input_is_rejected_and_starts_nothing(array $payload): void
    {
        $fixture = $this->payableDocument();

        $this->postJson($this->payUrl($fixture['document'], $fixture['token']), $payload)->assertStatus(422);

        $this->assertSame(0, BusinessDocumentPayment::query()->count());
        $this->assertSame([], $this->gateway->callsOf('createPaymentIntent'));
    }

    public function test_the_client_secret_never_reaches_the_database_the_log_or_an_exception(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message . ' ' . json_encode($message->context);
        });

        $fixture = $this->payableDocument();
        $secret = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->json('client_secret');
        $this->assertNotEmpty($secret);

        // Finalize it too, so the webhook path is covered by the same sweep.
        $payment = BusinessDocumentPayment::query()->sole();
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            'acct_ready001', 50000, 'USD', (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        foreach (['business_document_payments', 'business_payment_events', 'business_documents'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    $this->assertStringNotContainsString($secret, (string) $value, "[{$table}.{$column}] holds the client secret.");
                }
            }
        }

        foreach ($lines as $line) {
            $this->assertStringNotContainsString($secret, $line, 'The client secret must never be logged.');
        }

        // ...and no lane-B class persists it. PaymentStartResult legitimately
        // names the response key — it is the one place the value is allowed
        // to exist, for the length of a single HTTP response.
        foreach (glob(app_path('Library/Payments/*.php')) ?: [] as $file) {
            if (basename($file) === 'PaymentStartResult.php') {
                continue;
            }

            $code = $this->codeWithoutComments($file);

            $this->assertStringNotContainsString("'client_secret'", $code, basename($file) . ' must not handle a client_secret key.');
            $this->assertSame(0, preg_match('/(save|update|insert|forceFill)[^;]*clientSecret/s', $code),
                basename($file) . ' must never write the client secret to a row.');
        }
    }

    // =================================================================
    // (e)(f)(h) one attempt: refresh, uncertain response, SCA
    // =================================================================

    public function test_refreshing_re_drives_the_same_attempt_and_the_same_intent(): void
    {
        $fixture = $this->payableDocument();

        $first = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->json();
        $second = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->json();

        $this->assertSame($first['payment_uid'], $second['payment_uid']);
        $this->assertSame($first['client_secret'], $second['client_secret']);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'), 'Case A retrieves; it never creates again.');
        $this->assertCount(1, $this->gateway->callsOf('retrievePaymentIntent'));
    }

    public function test_an_uncertain_creation_response_re_drives_the_same_idempotency_key(): void
    {
        $fixture = $this->payableDocument();

        // Stripe created the intent; the response was lost.
        $this->gateway->loseNextCreateResponse = true;
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertNotFound();

        $payment = BusinessDocumentPayment::query()->sole();
        $this->assertNull($payment->provider_payment_intent_id, 'The row never learned the intent id.');

        // The retry must reuse the SAME key, so Stripe returns the original.
        $body = $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk()->json();

        $keys = array_map(fn ($c) => $c['args']['idempotency_key'], $this->gateway->callsOf('createPaymentIntent'));
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'A retry must never invent a new provider key.');
        $this->assertSame('document-payment:' . $payment->uid, $keys[0]);

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(1, count($this->gateway->intentsByKey), 'Exactly one provider intent exists.');
        $this->assertSame((string) $payment->uid, $body['payment_uid']);
    }

    public function test_an_sca_cycle_creates_no_second_attempt(): void
    {
        $fixture = $this->payableDocument();
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $payment = BusinessDocumentPayment::query()->sole();

        // Stripe moves the intent to requires_action; the customer returns.
        $this->gateway->setIntentStatus((string) $payment->provider_payment_intent_id, BusinessDocumentPaymentStatus::RequiresAction);
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame(BusinessDocumentPaymentStatus::RequiresAction, $payment->refresh()->status);
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    public function test_two_simultaneous_first_clicks_produce_one_row_and_one_provider_operation(): void
    {
        $fixture = $this->payableDocument();
        $access = $this->accessFor($fixture['document'], $fixture['token']);

        // Sequential calls model the post-lock interleaving: the document lock
        // serializes them, and the second must find the first's active row at
        // §7.2 step 9 rather than inserting its own.
        $a = $this->manager()->start($access);
        $b = $this->manager()->start($access);

        $this->assertSame($a->paymentUid, $b->paymentUid);
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('createPaymentIntent'));
    }

    public function test_the_database_itself_refuses_a_second_live_attempt_for_one_item(): void
    {
        $fixture = $this->payableDocument();
        $this->manager()->start($this->accessFor($fixture['document'], $fixture['token']));
        $first = BusinessDocumentPayment::query()->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('business_document_payments')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $first->business_id,
            'business_document_id' => $first->business_document_id,
            'schedule_item_id' => $first->schedule_item_id,
            'business_stripe_connection_id' => $first->business_stripe_connection_id,
            'local_idempotency_key' => 'document-payment:other',
            'amount_minor' => $first->amount_minor,
            'currency_code' => $first->currency_code,
            'status' => BusinessDocumentPaymentStatus::Created->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =================================================================
    // (g) the connected account is server-derived
    // =================================================================

    public function test_a_browser_supplied_account_cannot_change_the_account_used(): void
    {
        $fixture = $this->payableDocument();

        // Rejected outright by the input schema...
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']), ['stripe_account' => 'acct_attacker'])
            ->assertStatus(422);

        // ...and the legitimate call uses only the persisted connection.
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();

        foreach ($this->gateway->callsOf('createPaymentIntent') as $call) {
            $this->assertSame('acct_ready001', $call['args']['account']);
        }

        $this->assertStringNotContainsString('acct_attacker', json_encode($this->gateway->calls));
    }

    public function test_no_application_fee_is_ever_sent(): void
    {
        $fixture = $this->payableDocument();
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();

        // Comments stripped: the docblock explains WHY application_fee_amount
        // is omitted, and must not itself trip a scan for the code.
        $gateway = $this->codeWithoutComments(app_path('Library/Payments/StripeApiConnectGateway.php'));
        $this->assertStringNotContainsString('application_fee_amount', $gateway);
        $this->assertStringNotContainsString('on_behalf_of', $gateway);
        $this->assertStringNotContainsString('transfer_data', $gateway);
        $this->assertStringContainsString("'stripe_account' => \$connectedAccountId", $gateway);
    }

    // =================================================================
    // §7.3 payability gates
    // =================================================================

    public function test_a_requires_signature_document_cannot_pay_while_only_sent(): void
    {
        $fixture = $this->payableDocument(sign: false);

        try {
            $this->manager()->start($this->accessFor($fixture['document'], $fixture['token']));
            $this->fail('An unsigned proposal must not be payable.');
        } catch (PaymentStartException $e) {
            $this->assertSame(PaymentStartException::NOT_SIGNED, $e->reason);
        }

        $this->assertSame(0, BusinessDocumentPayment::query()->count());
    }

    public function test_the_balance_cannot_be_paid_before_the_deposit_succeeds(): void
    {
        $fixture = $this->payableDocument($this->depositAndBalance());
        $access = $this->accessFor($fixture['document'], $fixture['token']);

        // The only payable item is the deposit.
        $result = $this->manager()->start($access);
        $payment = BusinessDocumentPayment::query()->sole();
        $this->assertSame(20000, (int) $payment->amount_minor);

        $balance = BusinessDocumentPaymentScheduleItem::query()->where('sequence', 2)->sole();
        $this->assertSame(PaymentScheduleItemStatus::Pending, $balance->status);
        $this->assertSame(0, BusinessDocumentPayment::query()->where('schedule_item_id', $balance->id)->count());
    }

    public function test_deposit_success_does_not_mark_the_document_paid_but_the_balance_then_becomes_payable(): void
    {
        $fixture = $this->payableDocument($this->depositAndBalance());
        $access = $this->accessFor($fixture['document'], $fixture['token']);
        $this->manager()->start($access);
        $deposit = BusinessDocumentPayment::query()->sole();

        $this->succeed($deposit, 20000);

        $this->assertSame(DocumentStatus::Signed, $fixture['document']->refresh()->status, 'A paid deposit is not a paid document.');
        $this->assertSame(PaymentScheduleItemStatus::Paid, BusinessDocumentPaymentScheduleItem::query()->where('sequence', 1)->sole()->status);

        // Now the balance is the payable item.
        $this->manager()->start($this->accessFor($fixture['document']->refresh(), $fixture['token']));
        $balancePayment = BusinessDocumentPayment::query()->orderByDesc('id')->first();
        $this->assertSame(30000, (int) $balancePayment->amount_minor);

        $this->succeed($balancePayment, 30000);

        $this->assertSame(DocumentStatus::Paid, $fixture['document']->refresh()->status);
        $this->assertNotNull($fixture['document']->refresh()->paid_at);
    }

    public function test_a_superseded_versions_schedule_item_is_never_payable(): void
    {
        $fixture = $this->payableDocument(sign: false);
        $document = $fixture['document'];
        $oldItem = BusinessDocumentPaymentScheduleItem::query()->sole();

        // Revise and re-send: version 2 becomes current.
        app(DocumentManager::class)->revise($document, $fixture['tenant']['customer']->user);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());
        app(DocumentManager::class)->sign($document, [
            'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
            'ip_address' => '127.0.0.1', 'user_agent' => null,
        ]);

        $this->manager()->start($this->accessFor($document->refresh(), $token));
        $payment = BusinessDocumentPayment::query()->sole();

        $this->assertNotSame((int) $oldItem->id, (int) $payment->schedule_item_id,
            'Payment must target the CURRENT version\'s schedule, never the superseded one.');
        $this->assertSame(PaymentScheduleItemStatus::Pending, $oldItem->refresh()->status);
    }

    public function test_a_voided_document_cannot_start_a_payment(): void
    {
        $fixture = $this->payableDocument();
        app(DocumentManager::class)->void($fixture['document'], 'Cancelled.');

        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertNotFound();
        $this->assertSame(0, BusinessDocumentPayment::query()->count());
    }

    public function test_a_business_that_is_not_charge_ready_cannot_be_paid(): void
    {
        $fixture = $this->payableDocument();
        DB::table('business_stripe_connections')->where('id', $fixture['connection']->id)
            ->update(['charges_enabled' => false]);

        try {
            $this->manager()->start($this->accessFor($fixture['document'], $fixture['token']));
            $this->fail('§11.4 readiness must be rechecked immediately before the intent.');
        } catch (PaymentStartException $e) {
            $this->assertSame(PaymentStartException::NOT_PAYMENT_READY, $e->reason);
        }

        $this->assertSame([], $this->gateway->callsOf('createPaymentIntent'));
    }

    // =================================================================
    // (i)(j) browser return has no authority; the webhook finalizes
    // =================================================================

    public function test_returning_from_stripe_never_marks_a_payment_succeeded(): void
    {
        $fixture = $this->payableDocument();
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $payment = BusinessDocumentPayment::query()->sole();

        // Stripe really did succeed provider-side...
        $this->gateway->setIntentStatus((string) $payment->provider_payment_intent_id, BusinessDocumentPaymentStatus::Succeeded);

        // ...but merely coming back to the page transitions nothing.
        $this->get($this->publicUrl($fixture['document'], $fixture['token']) . '?payment_intent=' . $payment->provider_payment_intent_id . '&payment_intent_client_secret=leak')
            ->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Created, $payment->refresh()->status);
        $this->assertSame(DocumentStatus::Signed, $fixture['document']->refresh()->status);
    }

    public function test_a_verified_webhook_completes_that_same_attempt(): void
    {
        Notification::fake();
        $fixture = $this->payableDocument();
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $payment = BusinessDocumentPayment::query()->sole();

        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            'acct_ready001', 50000, 'USD', (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertNotNull($payment->succeeded_at);
        $this->assertSame(DocumentStatus::Paid, $fixture['document']->refresh()->status);
        $this->assertSame(BusinessPaymentEventState::Processed, BusinessPaymentEvent::query()->sole()->state);
    }

    // =================================================================
    // (k)(l) terminal failure, and the status map
    // =================================================================

    public function test_after_a_terminal_failure_exactly_one_new_attempt_is_permitted(): void
    {
        $fixture = $this->payableDocument();
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $first = BusinessDocumentPayment::query()->sole();

        [$body, $headers] = $this->webhookPayload('payment_intent.payment_failed', (string) $first->provider_payment_intent_id,
            'acct_ready001', 50000, 'USD', (string) $first->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $this->assertSame(BusinessDocumentPaymentStatus::Failed, $first->refresh()->status);
        $this->assertNull(DB::table('business_document_payments')->where('id', $first->id)->value('active_schedule_item_id'),
            'A terminal attempt must free the schedule slot.');

        // Exactly one new deliberate attempt.
        $this->postJson($this->payUrl($fixture['document'], $fixture['token']))->assertOk();
        $this->assertSame(2, BusinessDocumentPayment::query()->count());

        $second = BusinessDocumentPayment::query()->orderByDesc('id')->first();
        $this->assertNotSame((string) $first->uid, (string) $second->uid);
        $this->assertNotSame((string) $first->local_idempotency_key, (string) $second->local_idempotency_key);
    }

    // =================================================================
    // §7.2/§8.1 — the durable identity is complete in ONE insert
    // =================================================================

    /**
     * `local_idempotency_key` is NOT NULL with no default, and
     * unique(business_id, local_idempotency_key) is tenant-scoped. If a
     * payment were inserted first and keyed afterwards, the row would exist
     * for a moment holding '' (this connection runs non-strict), and two
     * unrelated payments being created for two documents of the SAME Business
     * would collide on ('', business_id) — a raw driver error that has nothing
     * to do with either payment.
     *
     * So this asserts the property directly, from inside the model events: the
     * key is already in the INSERT, and at no point does a persisted row carry
     * an empty one.
     */
    public function test_each_payment_insert_already_carries_its_own_durable_key(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);

        $fixtures = [];

        foreach ([1, 2] as $ignored) {
            [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));
            app(DocumentManager::class)->sign($document, [
                'signer_name' => 'Pat Rivera',
                'signer_email' => 'pat@example.test',
                'typed_name' => 'Pat Rivera',
                'ip_address' => '127.0.0.1',
                'user_agent' => null,
            ]);
            $fixtures[] = [$document->refresh(), $token];
        }

        $atInsert = [];
        $afterInsert = [];

        BusinessDocumentPayment::creating(function (BusinessDocumentPayment $payment) use (&$atInsert) {
            // Whatever is set here is exactly what the INSERT statement sends.
            $atInsert[] = [
                'uid' => (string) $payment->uid,
                'key' => (string) $payment->local_idempotency_key,
                'status' => $payment->status,
            ];
        });

        BusinessDocumentPayment::created(function (BusinessDocumentPayment $payment) use (&$afterInsert) {
            // The instant after the INSERT, before any later UPDATE could
            // repair anything.
            $afterInsert[] = [
                'key' => (string) $payment->fresh()->local_idempotency_key,
                'blank_rows' => (int) DB::table('business_document_payments')
                    ->where('local_idempotency_key', '')->count(),
            ];
        });

        foreach ($fixtures as [$document, $token]) {
            $this->manager()->start($this->accessFor($document, $token));
        }

        $this->assertCount(2, $atInsert);
        $this->assertCount(2, $afterInsert);

        foreach ($atInsert as $index => $observed) {
            $this->assertNotSame('', $observed['uid'], 'The insert carries the UID the key is derived from.');
            $this->assertSame('document-payment:' . $observed['uid'], $observed['key'],
                'The insert already carries the full durable key.');
            $this->assertSame(BusinessDocumentPaymentStatus::Created, $observed['status'],
                'and the status the active_schedule_item_id guard depends on.');
            $this->assertSame($observed['key'], $afterInsert[$index]['key'],
                'The persisted key is the one the insert wrote; nothing repairs it afterwards.');
            $this->assertSame(0, $afterInsert[$index]['blank_rows'],
                'No payment row ever exists with an empty idempotency key.');
        }

        // Two independent payments for ONE Business, each with its own key.
        $payments = BusinessDocumentPayment::query()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(1, $payments->pluck('business_id')->unique()->count());
        $this->assertSame(2, $payments->pluck('local_idempotency_key')->unique()->count());

        foreach ($payments as $payment) {
            $this->assertSame('document-payment:' . $payment->uid, (string) $payment->local_idempotency_key);
            $this->assertSame((string) $payment->local_idempotency_key, PaymentManager::idempotencyKeyFor($payment));
        }
    }

    /**
     * @return array<string, array{0: string, 1: BusinessDocumentPaymentStatus}>
     */
    public static function providerStatuses(): array
    {
        return [
            'requires_payment_method' => ['requires_payment_method', BusinessDocumentPaymentStatus::Created],
            'requires_confirmation' => ['requires_confirmation', BusinessDocumentPaymentStatus::Created],
            'requires_action' => ['requires_action', BusinessDocumentPaymentStatus::RequiresAction],
            'processing' => ['processing', BusinessDocumentPaymentStatus::Processing],
            'requires_capture' => ['requires_capture', BusinessDocumentPaymentStatus::Processing],
            'succeeded' => ['succeeded', BusinessDocumentPaymentStatus::Succeeded],
            'canceled' => ['canceled', BusinessDocumentPaymentStatus::Canceled],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerStatuses')]
    public function test_every_provider_status_maps_to_the_contracted_local_status(string $provider, BusinessDocumentPaymentStatus $expected): void
    {
        $this->assertSame($expected, ProviderStatusMap::forIntentStatus($provider));
    }

    public function test_an_unmapped_provider_status_fails_closed(): void
    {
        foreach (['requires_source', 'totally_new_status', ''] as $unknown) {
            try {
                ProviderStatusMap::forIntentStatus($unknown);
                $this->fail("[{$unknown}] must fail closed rather than be guessed.");
            } catch (StripeConnectException $e) {
                $this->assertSame(StripeConnectException::UNMAPPED_PROVIDER_STATUS, $e->reason);
                $this->assertStringNotContainsString($unknown === '' ? 'zzz' : $unknown, $e->getMessage());
            }
        }
    }

    public function test_no_provider_status_string_leaks_outside_the_gateway_seam(): void
    {
        foreach ([
            app_path('Library/Payments/PaymentManager.php'),
            app_path('Library/Payments/PaymentFinalizer.php'),
            app_path('Http/Controllers/Public/PublicDocumentController.php'),
            app_path('Jobs/BusinessPayments/ProcessBusinessPaymentEvent.php'),
            resource_path('views/public/documents/show.blade.php'),
        ] as $file) {
            // Comments stripped: a docblock may legitimately EXPLAIN Stripe's
            // vocabulary; what must not exist is code that depends on it.
            $code = str_ends_with($file, '.blade.php')
                ? (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file))
                : $this->codeWithoutComments($file);

            foreach (['requires_payment_method', 'requires_confirmation', 'requires_capture'] as $providerString) {
                $this->assertStringNotContainsString($providerString, $code, basename($file) . " must not know [{$providerString}].");
            }
        }
    }

    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private function succeed(BusinessDocumentPayment $payment, int $amount): void
    {
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            'acct_ready001', $amount, 'USD', (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();
    }
}
