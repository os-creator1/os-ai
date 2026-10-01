<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\DocumentStatus;
use App\Events\DocumentSent;
use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Payments & Invoices V1 completion — the invoice lifecycle, end to end, with
 * the properties the customer-facing flow depends on:
 *
 *   - re-sending the CURRENT issued version rotates the link and queues the
 *     same after-commit delivery, changing no commercial content (the
 *     recovery path for a lost email — send() consumes a draft, so before
 *     this there was no way to re-send without revising);
 *   - a re-send can never re-arm a link the lifecycle has closed;
 *   - a double submit of Send is one issued version and one email;
 *   - a paid invoice accepts no mutation of any kind.
 */
class InvoiceLifecycleAndResendTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    /** A sent, no-signature invoice with a ready connection. @return array{0: array, 1: BusinessDocument, 2: string} */
    private function sentInvoice(): array
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business'], 'acct_inv' . ++$this->payableAccountSequence);
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice', 'title' => 'Kitchen invoice']);
        [$document, $token] = $this->sendAndCaptureToken($draft);

        return [$tenant, $document, $token];
    }

    private function snapshotOf(BusinessDocument $document): array
    {
        $document->refresh();

        return [
            'current_version_id' => $document->current_version_id,
            'hash' => (string) $document->currentVersion->content_hash,
            'total' => (int) $document->currentVersion->total_minor,
            'versions' => $document->versions()->count(),
            'recipient' => $document->recipient_email_snapshot,
            'sent_at' => (string) $document->sent_at,
            'status' => $document->status->value,
        ];
    }

    // =================================================================
    // Re-send
    // =================================================================

    public function test_resending_rotates_the_link_and_changes_no_content(): void
    {
        [, $document, $first] = $this->sentInvoice();
        $before = $this->snapshotOf($document);

        Event::fake([DocumentSent::class]);
        Notification::fake();
        $resent = app(DocumentManager::class)->resendLink($document);

        $this->assertSame($before, $this->snapshotOf($resent), 'Content, version, hash, recipient and sent_at are untouched.');
        $this->assertFalse(Hash::check($first, (string) $resent->access_token_hash), 'The earlier link dies at once.');
        Event::assertNotDispatched(DocumentSent::class);

        $token = null;
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification, $channels, $notifiable) use (&$token) {
            $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

            return $notifiable->routes['mail'] === 'client@example.test';
        });
        $this->assertTrue(Hash::check($token, (string) $resent->access_token_hash), 'The NEW token verifies.');
        $this->assertNotSame($first, $token);

        // And the new link actually opens the page; the old one does not.
        $this->get($this->publicUrl($resent, $token))->assertOk();
        $this->get($this->publicUrl($resent, $first))->assertNotFound();
    }

    public function test_resending_queues_the_encrypted_after_commit_delivery_job(): void
    {
        [, $document] = $this->sentInvoice();

        Queue::fake();
        app(DocumentManager::class)->resendLink($document);

        Queue::assertPushed(SendDocumentLinkEmail::class, 1);
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldBeEncrypted::class,
            class_implements(SendDocumentLinkEmail::class),
            'The plaintext token must never sit in the queue table in the clear.'
        );
    }

    public function test_two_resends_leave_exactly_the_latest_link_valid(): void
    {
        [, $document] = $this->sentInvoice();
        $tokens = [];

        foreach ([1, 2] as $ignored) {
            Notification::fake();
            app(DocumentManager::class)->resendLink($document->refresh());
            Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$tokens) {
                $tokens[] = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

                return true;
            });
        }

        $document->refresh();
        $this->assertFalse(Hash::check($tokens[0], (string) $document->access_token_hash));
        $this->assertTrue(Hash::check($tokens[1], (string) $document->access_token_hash));
    }

    public function test_a_closed_document_can_never_be_re_armed_by_a_resend(): void
    {
        // Every fixture is built BEFORE the queue is faked: building one sends
        // a real link email, which a faked queue would swallow.
        $tenant = $this->sendableTenant();
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);

        [, $voided] = $this->sentInvoice();
        app(DocumentManager::class)->void($voided, 'Cancelled.');

        [, $expired] = $this->sentInvoice();
        BusinessDocument::query()->whereKey($expired->id)->update(['status' => 'expired', 'expired_at' => now()]);

        $paid = $this->paidInvoice();

        [, $lapsed] = $this->sentInvoice();
        BusinessDocument::query()->whereKey($lapsed->id)->update(['expires_at' => now()->subDay()]);

        foreach ([$draft, $voided, $expired, $paid, $lapsed] as $closed) {
            $this->assertRefusedResend($closed);
        }
    }

    private function assertRefusedResend(BusinessDocument $document): void
    {
        $hashBefore = BusinessDocument::query()->find($document->id)->access_token_hash;
        Queue::fake();

        try {
            app(DocumentManager::class)->resendLink($document);
            $this->fail('A ' . $document->refresh()->status->value . ' document must not be re-sendable.');
        } catch (ValidationException) {
        }

        Queue::assertNothingPushed();
        $this->assertSame($hashBefore, BusinessDocument::query()->find($document->id)->access_token_hash, 'A refused resend rotates nothing.');
    }

    public function test_a_resend_that_loses_to_a_void_is_refused_cleanly(): void
    {
        [, $document] = $this->sentInvoice();
        app(DocumentManager::class)->void($document, 'Cancelled.');

        // `$document` is a stale in-memory model that still says `sent`; the
        // status is re-read under the lock, so the stale view cannot win.
        $this->assertSame(DocumentStatus::Sent, $document->status);
        $this->assertRefusedResend($document);
    }

    // =================================================================
    // Send: idempotent, and failure never marks it sent
    // =================================================================

    public function test_a_double_submitted_send_is_one_issued_version_and_one_email(): void
    {
        $tenant = $this->sendableTenant();
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);
        Notification::fake();

        $sent = app(DocumentManager::class)->send($draft);

        // The second submit has no open draft left to issue, so it is a REPLAY
        // (Proposals lane, Contract 17A): it returns the already-sent document
        // without a new token, a new version or a second email.
        $replay = app(DocumentManager::class)->send($draft);
        $this->assertSame($sent->access_token_hash, $replay->access_token_hash);
        $this->assertSame($sent->current_version_id, $replay->current_version_id);

        $this->assertSame(1, $sent->versions()->where('state', 'issued')->count());
        $this->assertSame(1, $sent->versions()->count());
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
    }

    public function test_a_send_that_fails_validation_leaves_the_document_a_draft_with_no_email(): void
    {
        $tenant = $this->sendableTenant();
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);
        BusinessDocument::query()->whereKey($draft->id)->update(['recipient_email_snapshot' => null]);
        Notification::fake();
        Event::fake([DocumentSent::class]);

        try {
            app(DocumentManager::class)->send($draft->refresh());
            $this->fail('No recipient, no send.');
        } catch (ValidationException) {
        }

        $draft->refresh();
        $this->assertSame(DocumentStatus::Draft, $draft->status);
        $this->assertNull($draft->sent_at);
        $this->assertNull($draft->access_token_hash);
        $this->assertNull($draft->current_version_id);
        Notification::assertNothingSent();
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_a_queued_delivery_that_fails_does_not_unsend_or_corrupt_the_document(): void
    {
        // Sending is the committed state change; delivery is the job. A mail
        // outage must therefore leave a correct `sent` document that the owner
        // can re-send — never a half-sent one.
        $tenant = $this->sendableTenant();
        $draft = $this->draftDocument($tenant, ['kind' => 'invoice']);
        Queue::fake();

        $sent = app(DocumentManager::class)->send($draft);
        Queue::assertPushed(SendDocumentLinkEmail::class, 1);

        // The job is never run (the transport is down). Nothing about the
        // document depends on it, and a re-send is available.
        $this->assertSame(DocumentStatus::Sent, $sent->refresh()->status);
        app(DocumentManager::class)->resendLink($sent);
        Queue::assertPushed(SendDocumentLinkEmail::class, 2);
    }

    // =================================================================
    // Stale in-memory models cannot author a document
    // =================================================================

    public function test_a_stale_location_or_contact_model_cannot_create_a_document(): void
    {
        $tenant = $this->sendableTenant();
        $manager = app(DocumentManager::class);
        $user = $tenant['customer']->user;

        // The models below were loaded while everything was valid…
        $location = $tenant['location'];
        $contact = $tenant['contact'];

        // …then the Location is archived underneath them.
        DB::table('business_locations')->where('id', $location->id)->update(['lifecycle_state' => 'archived']);
        $this->assertRefusedCreate(fn () => $manager->create($tenant['business'], $location, $contact, null, 'invoice', 'Stale location', $user));

        // Restored; now the Contact is moved to another Location underneath its stale model.
        DB::table('business_locations')->where('id', $location->id)->update(['lifecycle_state' => 'active']);
        $other = \App\Models\BusinessLocation::create(['business_id' => $tenant['business']->id, 'name' => 'Branch', 'service_mode' => 'storefront', 'country_code' => 'US']);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $other->id]);
        $this->assertRefusedCreate(fn () => $manager->create($tenant['business'], $location, $contact, null, 'invoice', 'Stale contact', $user));

        $this->assertSame(0, BusinessDocument::query()->count(), 'Nothing was created from either stale view.');

        // Positive control: with the Contact back at the Location it is accepted.
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);
        $this->assertSame(1, $manager->create($tenant['business'], $location, $contact, null, 'invoice', 'Fresh', $user)->versions()->count());
    }

    private function assertRefusedCreate(\Closure $create): void
    {
        try {
            $create();
            $this->fail('A stale Location/Contact model must not create a document.');
        } catch (ValidationException) {
        }
    }
    // =================================================================
    // A paid invoice is immutable
    // =================================================================

    private function paidInvoice(): BusinessDocument
    {
        [$tenant, $document, $token] = $this->sentInvoice();
        app(PaymentManager::class)->start($this->accessFor($document, $token));
        $payment = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $connection = \App\Models\BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);

        [$body, $headers] = $this->webhookPayload(
            'payment_intent.succeeded',
            (string) $payment->provider_payment_intent_id,
            (string) $connection->stripe_account_id,
            (int) $payment->amount_minor,
            (string) $payment->currency_code,
            (string) $payment->local_idempotency_key,
        );
        $this->postWebhook($body, $headers)->assertOk();
        $document->refresh();
        $this->assertSame(DocumentStatus::Paid, $document->status);

        return $document;
    }

    public function test_a_paid_invoice_accepts_no_mutation_of_any_kind(): void
    {
        $document = $this->paidInvoice();
        $manager = app(DocumentManager::class);
        $tables = ['business_documents', 'business_document_versions', 'business_document_line_items', 'business_document_payment_schedule_items'];
        $before = $this->fingerprint($tables);
        $line = $document->currentVersion->lineItems()->firstOrFail();

        $attempts = [
            'edit title' => fn () => $manager->edit($document, ['title' => 'Renamed']),
            'edit recipient' => fn () => $manager->edit($document, ['recipient_email_snapshot' => 'attacker@example.test']),
            'edit content' => fn () => $manager->edit($document, ['content' => ['body' => 'x']]),
            'add custom line' => fn () => $manager->addCustomLine($document, 'Extra', null, 1, 100),
            'remove line' => fn () => $manager->removeLine($document, $line),
            'reorder' => fn () => $manager->reorderLines($document, [$line->id]),
            'set schedule' => fn () => $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 1, 'currency_code' => 'USD']]),
            'send' => fn () => $manager->send($document),
            'revise' => fn () => $manager->revise($document, $document->creator ?? \App\Models\User::query()->firstOrFail()),
            'void' => fn () => $manager->void($document, 'Nope'),
            'resend' => fn () => $manager->resendLink($document),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("A paid invoice must refuse: {$label}.");
            } catch (ValidationException) {
            }
        }

        $this->assertSame($before, $this->fingerprint($tables), 'Not one row of the invoice moved.');
        $this->assertSame(DocumentStatus::Paid, $document->refresh()->status);
    }

    public function test_the_public_link_of_a_paid_invoice_offers_no_payment(): void
    {
        [, $document, $token] = $this->sentInvoice();
        app(PaymentManager::class)->start($this->accessFor($document, $token));
        $payment = BusinessDocumentPayment::query()->firstOrFail();
        $connection = \App\Models\BusinessStripeConnection::query()->find($payment->business_stripe_connection_id);
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id,
            (string) $connection->stripe_account_id, (int) $payment->amount_minor, (string) $payment->currency_code, (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('Payment received', false)
            ->assertDontSee('data-role="pay-button"', false);

        // And a second start is refused: nothing is left to pay.
        $this->postJson($this->payUrl($document, $token))->assertNotFound();
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
    }
}
