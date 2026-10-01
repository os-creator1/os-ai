<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessStripeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Payments & Invoices V1 — the two surfaces a customer-facing invoice has:
 *
 *   - the BUSINESS's page: what was sent (the frozen issued version, never the
 *     live Catalog), where the money is, receipts, refunds, re-send and void;
 *   - the CUSTOMER's secure page: the frozen invoice, the schedule, a paid
 *     confirmation that is rendered from persisted (webhook-written) state,
 *     and nothing about the tenant beyond the Business's own name.
 */
class BusinessInvoiceSurfaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    /** @return array{fixture: array, payment: BusinessDocumentPayment} */
    private function startedInvoice(?array $schedule = null): array
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $connection = $this->chargeReadyConnection($tenant['business'], 'acct_surface001');
        $document = $this->draftDocument($tenant, ['kind' => 'invoice', 'title' => 'Kitchen invoice']);

        if ($schedule !== null) {
            app(DocumentManager::class)->setSchedule($document, $schedule);
        }

        [$document, $token] = $this->sendAndCaptureToken($document->refresh());
        app(PaymentManager::class)->start($this->accessFor($document, $token));

        return [
            'fixture' => ['tenant' => $tenant, 'document' => $document->refresh(), 'token' => $token, 'connection' => $connection],
            'payment' => BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail(),
        ];
    }

    private function deliver(BusinessDocumentPayment $payment, string $type = 'payment_intent.succeeded'): void
    {
        $connection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);
        [$body, $headers] = $this->webhookPayload($type, (string) $payment->provider_payment_intent_id,
            (string) $connection->stripe_account_id, (int) $payment->amount_minor, (string) $payment->currency_code,
            (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();
    }

    private function businessUrl(array $fixture, string $tail = ''): string
    {
        $tenant = $fixture['tenant'];

        return route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $fixture['document']->uid]) . $tail;
    }

    // =================================================================
    // The Business's page
    // =================================================================

    public function test_a_sent_invoice_shows_the_frozen_version_and_the_resend_action(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $this->authenticateAs($started['fixture']['tenant']['customer']);

        $this->get($this->businessUrl($started['fixture']))
            ->assertOk()
            ->assertSee('Sent version 1', false)
            ->assertSee('Design work')
            ->assertSee('500.00 USD')
            ->assertSee('Re-send payment link', false)
            ->assertSee('Revise (new version)', false)
            ->assertSee('awaiting payment', false)
            ->assertDontSee('Paid in full');
    }

    public function test_the_business_page_shows_the_issued_snapshot_not_the_live_catalog_or_a_later_draft(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $manager = app(DocumentManager::class);
        $document = $started['fixture']['document'];
        $user = $started['fixture']['tenant']['customer']->user;

        // The owner opens a revision and changes the price in the DRAFT.
        $manager->revise($document, $user);
        $draft = $document->versions()->where('state', 'draft')->firstOrFail();
        $draft->lineItems()->update(['unit_price_minor' => 99900, 'line_total_minor' => 199800]);

        $this->authenticateAs($started['fixture']['tenant']['customer']);
        $page = $this->get($this->businessUrl($started['fixture']))->assertOk();

        // What the customer holds is still version 1 at 500.00.
        $page->assertSee('Sent version 1', false)->assertSee('500.00 USD');
    }

    public function test_a_paid_invoice_shows_the_confirmation_receipt_state_and_a_refund_form(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $this->deliver($started['payment']);
        $this->authenticateAs($started['fixture']['tenant']['customer']);

        $page = $this->get($this->businessUrl($started['fixture']))->assertOk();

        $page->assertSee('Paid in full', false)
            ->assertSee('data-role="paid-confirmation"', false)
            ->assertSee('Payments and receipts', false)
            ->assertSee('succeeded')
            ->assertSee('500.00 USD')
            ->assertSee('Refund (minor units, up to 50000)', false)
            // Nothing left to do to a paid invoice but refund it.
            ->assertDontSee('Void document')
            ->assertDontSee('Re-send payment link');
    }

    public function test_the_refund_form_requests_a_refund_through_the_normal_gate(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $this->deliver($started['payment']);
        $this->authenticateAs($started['fixture']['tenant']['customer']);
        $payment = $started['payment']->refresh();
        $tenant = $started['fixture']['tenant'];

        $this->post(route('customer.workspaces.businesses.documents.payments.refund', [
            $tenant['workspace']->uid, $tenant['business']->uid, $started['fixture']['document']->uid, $payment->uid,
        ]), ['confirm' => '1', 'amount_minor' => 12000, 'reason' => 'Goodwill'])->assertRedirect();

        $refund = \App\Models\BusinessDocumentRefund::query()->sole();
        $this->assertSame(12000, (int) $refund->amount_minor);
        $this->assertSame($payment->id, (int) $refund->business_document_payment_id);

        // The page now shows the refund and the REMAINING refundable amount.
        $this->get($this->businessUrl($started['fixture']))
            ->assertOk()
            ->assertSee('Refund 120.00 USD', false)
            ->assertSee('up to 38000');
    }

    public function test_a_void_invoice_shows_the_void_notice_and_no_actions(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['kind' => 'invoice']);
        [$document] = $this->sendAndCaptureToken($document);
        app(DocumentManager::class)->void($document, 'Wrong customer');
        $this->authenticateAs($tenant['customer']);

        $this->get(route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]))
            ->assertOk()
            ->assertSee('Voided on', false)
            ->assertSee('Wrong customer')
            ->assertDontSee('Re-send payment link')
            ->assertDontSee('Void document');
    }

    public function test_the_list_shows_status_location_total_and_paid_date(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $this->deliver($started['payment']);
        $tenant = $started['fixture']['tenant'];
        $this->authenticateAs($tenant['customer']);

        $this->get(route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]))
            ->assertOk()
            ->assertSee('Kitchen invoice')
            ->assertSee('invoice')
            ->assertSee('paid')
            ->assertSee('Main')
            ->assertSee('500.00 USD');
    }

    // =================================================================
    // The customer's page
    // =================================================================

    public function test_the_customer_page_shows_the_paid_confirmation_only_after_the_webhook(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $fixture = $started['fixture'];

        // Coming "back from Stripe" proves nothing: the page still offers to pay.
        $this->get($this->publicUrl($fixture['document'], $fixture['token']) . '?redirect_status=succeeded&payment_intent=' . $started['payment']->provider_payment_intent_id)
            ->assertOk()
            ->assertDontSee('Payment received')
            ->assertSee('data-role="pay-button"', false);

        $this->deliver($started['payment']);

        $this->get($this->publicUrl($fixture['document'], $fixture['token']))
            ->assertOk()
            ->assertSee('Payment received', false)
            ->assertSee('data-role="paid-confirmation"', false)
            ->assertDontSee('data-role="pay-button"', false)
            ->assertSee('Paid');
    }

    public function test_the_customer_page_says_the_payment_is_being_confirmed_while_it_is_processing_and_never_before(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $fixture = $started['fixture'];

        // Merely having pressed Pay (status `created`) is not "processing".
        $this->get($this->publicUrl($fixture['document'], $fixture['token']))
            ->assertOk()->assertDontSee('being confirmed');

        $this->deliver($started['payment'], 'payment_intent.processing');
        $this->assertSame(BusinessDocumentPaymentStatus::Processing, $started['payment']->refresh()->status);

        $this->get($this->publicUrl($fixture['document'], $fixture['token']))
            ->assertOk()->assertSee('being confirmed', false)->assertDontSee('Payment received');
    }

    public function test_a_deposit_shows_which_instalment_is_paid_and_not_a_paid_confirmation(): void
    {
        Notification::fake();
        $started = $this->startedInvoice($this->depositAndBalance());
        $this->deliver($started['payment']);

        $page = $this->get($this->publicUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk();

        $page->assertSee('Deposit')->assertSee('Balance')->assertSee('Paid')
            ->assertDontSee('Payment received')
            ->assertSee('300.00 USD');
    }

    public function test_the_customer_page_and_pay_response_leak_no_tenant_data(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $fixture = $started['fixture'];
        $tenant = $fixture['tenant'];
        $this->deliver($started['payment']);

        $body = $this->get($this->publicUrl($fixture['document'], $fixture['token']))->assertOk()->getContent();

        // The Business's own name is the one tenant fact the customer is meant
        // to see; nothing else about the account, location or contact is.
        $this->assertStringContainsString('Harbor Lane Studios', $body);
        foreach ([
            'acct_surface001' => 'connected account id',
            (string) $tenant['business']->uid => 'business uid',
            (string) $tenant['location']->uid => 'location uid',
            (string) $tenant['contact']->phone => 'contact phone',
            (string) $tenant['workspace']->uid => 'workspace uid',
            'business_id' => 'internal column name',
            'local_idempotency_key' => 'internal column name',
            'document-payment:' => 'idempotency key',
        ] as $needle => $label) {
            $this->assertStringNotContainsString($needle, $body, "The public page must not expose the {$label}.");
        }
    }

    public function test_the_pay_response_exposes_only_the_minimum_transient_material(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business'], 'acct_surface002');
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);
        [$document, $token] = $this->sendAndCaptureToken($draft);

        $response = $this->postJson($this->payUrl($document, $token))->assertOk();

        $this->assertEqualsCanonicalizing(
            ['payment_uid', 'client_secret', 'stripe_account', 'publishable_key', 'amount_minor', 'currency_code'],
            array_keys($response->json()),
        );
        $this->assertStringNotContainsString('sk_', $response->getContent(), 'The platform secret key never reaches the browser.');
    }

    public function test_every_surface_is_idempotent_to_a_refresh_after_payment(): void
    {
        Notification::fake();
        $started = $this->startedInvoice();
        $this->deliver($started['payment']);

        for ($i = 0; $i < 3; $i++) {
            $this->get($this->publicUrl($started['fixture']['document'], $started['fixture']['token']))->assertOk()->assertSee('Payment received', false);
        }

        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $this->assertSame('paid', BusinessDocument::query()->findOrFail($started['fixture']['document']->id)->status->value);
    }
}
