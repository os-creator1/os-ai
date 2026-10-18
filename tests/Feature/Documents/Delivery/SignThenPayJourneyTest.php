<?php

namespace Tests\Feature\Documents\Delivery;

use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Documents\Editor\EditorTestHelpers;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * Contract 17B §7 — signing never ends on a dead page. After a successful
 * signature the recipient is led to the payment section of the secure document
 * page when something is payable now, and otherwise told what is due next. The
 * Stripe Payment Element on that page stays the execution UI; nothing here
 * changes PaymentManager.
 */
class SignThenPayJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;
    use EditorTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->bindFakeGateway();
    }

    private function signOver(BusinessDocument $document, string $token)
    {
        return $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
        ]);
    }

    private function payAnchor(BusinessDocument $document, string $token): string
    {
        return $this->publicUrl($document, $token) . '#pay';
    }

    public function test_full_payment_sign_leads_to_the_full_item(): void
    {
        $fixture = $this->payableDocument(null, false);
        ['document' => $document, 'token' => $token] = $fixture;

        $response = $this->signOver($document, $token)->assertOk()
            ->assertSee('Signed successfully')
            ->assertSee('Continue to payment')
            ->assertSee('500.00 USD is due now')
            ->assertSee(e($this->payAnchor($document, $token)), false);
        $this->assertStringContainsString('http-equiv="refresh"', $response->getContent());

        // The CTA target is the secure page, with a payment section to land on.
        $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('Due now: 500.00 USD');

        // And paying starts on the FULL item.
        $this->postJson($this->payUrl($document, $token))->assertOk();
        $this->assertSame(50000, (int) BusinessDocumentPayment::where('business_document_id', $document->id)->sole()->amount_minor);
    }

    public function test_deposit_sign_leads_to_the_deposit_item_and_the_balance_stays_pending_after_it_is_paid(): void
    {
        $due = '2027-04-01 12:00:00';
        $fixture = $this->payableDocument([
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD', 'due_at' => $due],
        ], false);
        ['document' => $document, 'token' => $token] = $fixture;

        $this->signOver($document, $token)->assertOk()
            ->assertSee('Continue to payment')
            ->assertSee('200.00 USD is due now');

        $this->postJson($this->payUrl($document, $token))->assertOk();
        $payment = BusinessDocumentPayment::where('business_document_id', $document->id)->sole();
        $this->assertSame(20000, (int) $payment->amount_minor);

        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id, 'acct_ready001', 20000, 'USD', (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $document->refresh();
        $this->assertSame('signed', $document->status->value, 'A paid deposit alone does not complete the document.');

        $balance = $document->versions()->first()->paymentScheduleItems()->where('kind', 'balance')->sole();
        $this->assertSame('pending', $balance->status->value);
        $this->assertSame($due, $balance->due_at->format('Y-m-d H:i:s'), 'The balance keeps its frozen due date.');

        $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('Due 1 April 2027')
            ->assertSee('Due now: 300.00 USD');
    }

    public function test_when_nothing_is_payable_the_signed_page_names_the_next_due_date_in_the_business_timezone(): void
    {
        // No connected account: signed, but online payment is not available.
        $tenant = $this->sendableTenant();
        $tenant['business']->forceFill(['timezone' => 'America/New_York'])->save();
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->setSchedule($document, [
            // 03:00 UTC is still the evening before in New York.
            ['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD', 'due_at' => '2027-03-01 03:00:00'],
        ]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        $response = $this->signOver($document, $token)->assertOk()
            ->assertSee('Signed successfully')
            ->assertSee('due on 28 February 2027')
            ->assertDontSee('Continue to payment');
        $this->assertStringNotContainsString('http-equiv="refresh"', $response->getContent());
    }

    public function test_a_balance_that_waits_on_the_deposit_says_so(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        app(DocumentManager::class)->setSchedule($document, [
            ['kind' => 'deposit', 'amount_minor' => 20000, 'currency_code' => 'USD'],
            ['kind' => 'balance', 'amount_minor' => 30000, 'currency_code' => 'USD'],
        ]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        // Deposit is the next item here (no account), so name its amount; the
        // "after the deposit" wording is for the balance once the deposit is paid
        // elsewhere. Either way the page is never a dead end.
        $this->signOver($document, $token)->assertOk()
            ->assertSee('Signed successfully')
            ->assertSee('Your payment of 200.00 USD is due once online payment is available')
            ->assertDontSee('Continue to payment');
    }

    public function test_a_block_document_shows_the_payment_section_with_its_anchor_after_signing(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $document = $this->draftDocument($tenant);
        $manager = app(DocumentManager::class);
        $manager->saveBlocks($document, $this->standardBlocks(), null);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        $this->signOver($document, $token)->assertOk()->assertSee('Continue to payment');

        $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('id="pay"', false)
            ->assertSee('data-role="pay-button"', false);
    }
}
