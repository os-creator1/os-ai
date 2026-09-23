<?php

namespace Tests\Feature\Documents;

use App\Library\Documents\DocumentActivityCenterReader;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use ReflectionProperty;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.G, §13 item 2 — the smallest focused
 * end-to-end acceptance path, run against the real authenticated and public
 * routes with the feature genuinely Available (no allowEntitlement() /
 * allowPublicEntitlement() bypass anywhere in this file): a Business owner
 * creates and sends a proposal, the end customer signs and pays it through
 * the existing Sub-slice E flow via the emailed secure link, and the owner
 * sees the resulting state. No live Stripe/network — the fake gateway only.
 */
class PaymentsContractsAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
    }

    public function test_owner_sends_customer_signs_and_pays_and_owner_sees_it_paid(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $this->authenticateAs($tenant['customer']);

        $base = route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]);

        // --- Business owner: create, price, schedule and send a proposal ---
        $this->post($base, [
            'kind' => 'proposal',
            'title' => 'Kitchen renovation proposal',
            'location_uid' => $tenant['location']->uid,
            'contact_uid' => $tenant['contact']->uid,
        ])->assertRedirect();

        $document = BusinessDocument::where('business_id', $tenant['business']->id)->firstOrFail();
        $documentUrl = $base . '/' . $document->uid;

        $this->post($documentUrl . '/custom-lines', [
            'name' => 'Design work', 'quantity' => 1, 'unit_price_minor' => 50000,
        ])->assertRedirect();

        $this->put($documentUrl . '/schedule', [
            'terms' => [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']],
        ])->assertRedirect();

        $this->patch($documentUrl, [
            'recipient_email_snapshot' => 'client@example.test',
            'recipient_name_snapshot' => 'Pat Rivera',
        ])->assertRedirect();

        // Captured off the real send — deliberately NOT Notification::fake(),
        // which would also swallow the real `database`-channel
        // DocumentActivityCenterNotification this slice's Activity Center
        // depends on for every lifecycle step below. MAIL_MAILER=array in
        // the test environment, so this still sends no live email.
        $token = null;
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$token): void {
            if ($event->notification instanceof DocumentIssuedNotification) {
                $token = (new ReflectionProperty($event->notification, 'plaintextToken'))->getValue($event->notification);
            }
        });

        $this->post($documentUrl . '/send')->assertRedirect();

        $this->assertNotEmpty($token, 'The secure emailed link token must have been minted by send().');

        $document = $document->refresh();

        // --- End customer: the secure emailed link, no account ---
        $this->get($this->publicUrl($document, (string) $token))
            ->assertOk()
            ->assertSee('Kitchen renovation proposal');

        $this->post($this->signUrl($document, (string) $token), [
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Signature recorded');

        $paymentStart = $this->postJson($this->payUrl($document, (string) $token))->assertOk()->json();
        $this->assertArrayHasKey('client_secret', $paymentStart);

        $payment = BusinessDocumentPayment::where('business_document_id', $document->id)->sole();

        [$webhookBody, $headers] = $this->webhookPayload(
            'payment_intent.succeeded',
            (string) $payment->provider_payment_intent_id,
            'acct_ready001',
            50000,
            'USD',
            (string) $payment->local_idempotency_key,
        );
        $this->postWebhook($webhookBody, $headers)->assertOk();

        // --- Business owner: sees the resulting document/payment state ---
        $this->get($documentUrl)->assertOk();

        $document = $document->refresh();
        $this->assertSame('paid', $document->status->value);
        $this->assertNotNull($document->signed_at);
        $this->assertNotNull($document->paid_at);
        $this->assertSame('succeeded', $payment->fresh()->status->value);

        // This slice's own integrations reflect the same real outcome: the
        // timeline (proved separately by DocumentActivityTimelineTest) reads
        // these same durable rows, and the owner's Activity Center shows an
        // authorized item for every lifecycle step along the way (the
        // read-time re-authorization itself is proved by
        // DocumentActivityCenterTest).
        $this->assertCount(4, app(DocumentActivityCenterReader::class)->unreadFor($tenant['customer']->user));
    }
}
