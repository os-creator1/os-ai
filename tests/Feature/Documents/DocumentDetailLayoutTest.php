<?php

namespace Tests\Feature\Documents;

use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessStripeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * The internal document detail page: card layout, dynamic data, status-dependent
 * controls and the responsive structure. Behavior of the actions themselves is
 * covered by BusinessInvoiceSurfaceTest and the document lifecycle tests.
 */
class DocumentDetailLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    private function showUrl(array $tenant, $document): string
    {
        return route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]);
    }

    private function sentInvoice(): array
    {
        $tenant = $this->sendableTenant('Harbor Lane Studios');
        $this->chargeReadyConnection($tenant['business'], 'acct_layout001');
        $document = $this->draftDocument($tenant, ['kind' => 'invoice', 'title' => 'Kitchen invoice']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return [$tenant, $document->refresh(), $token];
    }

    public function test_a_sent_document_renders_the_card_layout_with_dynamic_data_and_the_live_controls(): void
    {
        Notification::fake();
        [$tenant, $document] = $this->sentInvoice();
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->showUrl($tenant, $document))->assertOk()->getContent();

        foreach (['document-detail', 'breadcrumb', 'document-title', 'document-status', 'payment-status', 'document-stats',
                  'stat-total', 'stat-payment', 'stat-signed', 'issued-version', 'link-actions', 'header-resend',
                  'history-panel', 'history', 'void-card', 'awaiting-notice'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, "Missing data-role {$role}");
        }
        // Real data, not the design reference's example values.
        $this->assertStringContainsString('Kitchen invoice', $html);
        $this->assertStringContainsString('Harbor Lane Studios', $html);
        $this->assertStringContainsString('USD 500.00', $html);
        $this->assertStringContainsString('Payments &amp; Contracts', $html);
        foreach (['12,000.00', 'Scary Photos', 'Daniel', 'ADASD'] as $example) {
            $this->assertStringNotContainsString($example, $html);
        }
        // One resend action posted from both places, no editing forms on a sent document.
        $this->assertSame(1, substr_count($html, 'id="dd-resend-form"'));
        $this->assertStringNotContainsString('Draft details', $html);
        // Newest history first: Sent precedes Created.
        $this->assertLessThan(strpos($html, 'dd-event__title">Created'), strpos($html, 'dd-event__title">Sent'));
    }

    public function test_the_layout_collapses_to_one_column_with_the_sidebar_after_the_document(): void
    {
        Notification::fake();
        [$tenant, $document] = $this->sentInvoice();
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->showUrl($tenant, $document))->assertOk()->getContent();

        $this->assertStringContainsString('class="dd-grid"', $html);
        $this->assertStringContainsString('@media (max-width: 991.98px)', $html);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $html);
        $this->assertLessThan(strpos($html, 'class="dd-side"'), strpos($html, 'class="dd-main"'), 'Document first, sidebar second, so mobile stacks in reading order.');
        $this->assertStringContainsString('<meta name="viewport"', $html);
    }

    public function test_a_draft_shows_editing_controls_and_no_resend_or_history_of_a_send(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['kind' => 'invoice']);
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->showUrl($tenant, $document))->assertOk()->getContent();

        $this->assertStringContainsString('Draft details', $html);
        $this->assertStringContainsString('data-role="draft-send"', $html);
        $this->assertStringContainsString('data-role="void-card"', $html);
        foreach (['link-actions', 'header-resend', 'issued-version', 'awaiting-notice'] as $role) {
            $this->assertStringNotContainsString('data-role="' . $role . '"', $html);
        }
    }

    public function test_a_void_document_shows_the_notice_and_no_mutating_controls(): void
    {
        Notification::fake();
        [$tenant, $document] = $this->sentInvoice();
        app(DocumentManager::class)->void($document, 'Wrong customer');
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->showUrl($tenant, $document))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="void-notice"', $html);
        $this->assertStringContainsString('Wrong customer', $html);
        foreach (['link-actions', 'header-resend', 'void-card'] as $role) {
            $this->assertStringNotContainsString('data-role="' . $role . '"', $html);
        }
        $this->assertStringContainsString('data-role="history"', $html);
        $this->assertStringContainsString('dd-event__title">Voided', $html);
    }

    public function test_a_paid_document_shows_the_confirmation_receipts_and_no_send_or_void_controls(): void
    {
        Notification::fake();
        $tenant = $this->sendableTenant();
        $connection = $this->chargeReadyConnection($tenant['business'], 'acct_layout002');
        $document = $this->draftDocument($tenant, ['kind' => 'invoice']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());
        app(PaymentManager::class)->start($this->accessFor($document, $token));
        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $stripeConnection = BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            (string) $stripeConnection->stripe_account_id, (int) $payment->amount_minor, (string) $payment->currency_code,
            (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();
        $this->authenticateAs($tenant['customer']);

        $html = $this->get($this->showUrl($tenant, $document->refresh()))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="paid-confirmation"', $html);
        $this->assertStringContainsString('data-role="payments"', $html);
        $this->assertStringContainsString('dd-event__title">Paid', $html);
        foreach (['link-actions', 'header-resend', 'void-card', 'awaiting-notice', 'payment-status'] as $role) {
            $this->assertStringNotContainsString('data-role="' . $role . '"', $html);
        }
    }
}
