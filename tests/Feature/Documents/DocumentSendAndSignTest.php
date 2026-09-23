<?php

namespace Tests\Feature\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §7.1 / §5.2 / §5.5 / §11.3 — SEND and SIGN.
 */
class DocumentSendAndSignTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    // -----------------------------------------------------------------
    // Send freezes the version and mints the link
    // -----------------------------------------------------------------

    public function test_send_issues_the_draft_version_and_records_the_link(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        [$document, $token] = $this->sendAndCaptureToken($document);

        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->assertSame(DocumentStatus::Sent, $document->status);
        $this->assertNotNull($document->sent_at);
        $this->assertSame(DocumentVersionState::Issued, $version->state);
        $this->assertNotNull($version->issued_at);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $version->content_hash);
        $this->assertSame(1, (int) $version->version_number);
        $this->assertNotNull($document->access_token_hash);
        $this->assertNotNull($document->access_token_rotated_at);
        $this->assertNotNull($document->access_token_expires_at);
    }

    public function test_the_content_hash_is_the_canonical_bytes_of_the_frozen_version(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $this->assertSame(app(DocumentContentHasher::class)->hash($version), $version->content_hash);
    }

    public function test_the_token_is_64_characters_and_verifies_only_through_hash_check(): void
    {
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->assertSame(64, strlen($token));
        $this->assertTrue(Hash::check($token, $document->access_token_hash));
        $this->assertNotSame($token, $document->access_token_hash);
    }

    public function test_the_plaintext_token_is_never_stored_in_any_document_column(): void
    {
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $row = (array) DB::table('business_documents')->where('id', $document->id)->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "[{$column}] must never hold the plaintext token.");
        }

        // ...and it is not queryable, because it is not a lookup key.
        $this->assertSame(0, DB::table('business_documents')->where('access_token_hash', $token)->count());
    }

    public function test_the_link_expiry_never_outlives_the_document_offer(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $offerExpiry = now()->addDays(3)->startOfSecond();
        DB::table('business_documents')->where('id', $document->id)->update(['expires_at' => $offerExpiry]);

        [$document] = $this->sendAndCaptureToken($document->refresh());

        $this->assertSame($offerExpiry->toDateTimeString(), $document->access_token_expires_at->toDateTimeString());
    }

    public function test_without_a_document_expiry_the_link_uses_the_configured_ttl(): void
    {
        config(['documents.link_ttl_days' => 7]);
        $tenant = $this->sendableTenant();

        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        $this->assertSame(7, (int) now()->startOfDay()->diffInDays($document->access_token_expires_at->startOfDay()));
    }

    // -----------------------------------------------------------------
    // Delivery: email only, after commit, to the frozen snapshot
    // -----------------------------------------------------------------

    public function test_delivery_is_dispatched_after_commit_to_the_frozen_recipient(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['recipient' => 'frozen@example.test']);

        Notification::fake();
        app(DocumentManager::class)->send($document);

        // Delivered on demand to the document's OWN frozen snapshot, never to
        // a notifiable resolved from the live Contact.
        Notification::assertSentOnDemand(
            DocumentIssuedNotification::class,
            function (DocumentIssuedNotification $notification, array $channels, object $notifiable) {
                $this->assertSame(['mail'], $channels);

                return $notifiable->routes['mail'] === 'frozen@example.test';
            }
        );

        $this->assertSame('frozen@example.test', $document->refresh()->recipient_email_snapshot);
    }

    public function test_delivery_uses_no_sms_messaging_or_wallet_path(): void
    {
        // §11.3 — this slice spends no messaging credit and has no wallet
        // side effect. Proven at the source, since a fake cannot prove an
        // absence of code.
        $source = '';

        foreach ([
            '/app/Library/Documents/DocumentManager.php',
            '/app/Library/Documents/PublicDocumentGuard.php',
            '/app/Jobs/Documents/SendDocumentLinkEmail.php',
            '/app/Notifications/Documents/DocumentIssuedNotification.php',
            '/app/Http/Controllers/Public/PublicDocumentController.php',
        ] as $file) {
            // Comments are stripped: a docblock SAYING "no wallet" must not
            // itself trip a scan for wallet CODE.
            foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . $file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $source .= $token[1];
                } else {
                    $source .= $token;
                }
            }
        }

        foreach (['quickSend', 'ManagedMessageDispatcher', 'App\\Library\\Messaging', 'App\\Library\\Usage', 'SendingServer', 'Wallet', 'Twilio', 'Nexmo', 'sms', 'Sms', 'SMS'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "Document delivery must not reach [{$forbidden}].");
        }
    }

    public function test_a_failed_send_dispatches_no_email_at_all(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        // Break the schedule so send() refuses after it has begun work.
        DB::table('business_document_payment_schedule_items')
            ->where('business_document_version_id', $document->versions()->first()->id)
            ->update(['amount_minor' => 1]);

        Notification::fake();

        try {
            app(DocumentManager::class)->send($document);
            $this->fail('Expected a refusal.');
        } catch (ValidationException) {
            // expected
        }

        Notification::assertNothingSent();
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);
        $this->assertNull($document->access_token_hash);
    }

    public function test_send_emits_document_sent_with_ids_and_no_pii(): void
    {
        Event::fake([DocumentSent::class]);
        Notification::fake();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        app(DocumentManager::class)->send($document);

        Event::assertDispatched(DocumentSent::class, function (DocumentSent $event) use ($document) {
            $payload = json_encode(get_object_vars($event));
            $this->assertStringNotContainsString('@', (string) $payload, 'No recipient address may ride on the event.');

            return $event->documentId === $document->id && $event->versionNumber === 1;
        });
    }

    public function test_the_recipient_email_is_required_and_validated_before_send(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DB::table('business_documents')->where('id', $document->id)->update(['recipient_email_snapshot' => null]);

        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->send($document->refresh());
    }

    public function test_the_recipient_snapshot_freezes_at_send_and_never_rereads_the_contact(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['recipient' => 'first@example.test']));

        // A later edit of the live Contact must never redirect delivery.
        DB::table('contacts')->where('id', $tenant['contact']->id)->update(['phone' => '19998887777']);

        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->edit($document, ['recipient_email_snapshot' => 'attacker@example.test']);
    }

    public function test_resending_rotates_the_token_so_every_earlier_link_dies(): void
    {
        $tenant = $this->sendableTenant();
        [$document, $first] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $firstHash = $document->access_token_hash;

        app(DocumentManager::class)->revise($document, $tenant['customer']->user);
        [$document, $second] = $this->sendAndCaptureToken($document->refresh());

        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstHash, $document->access_token_hash);
        $this->assertFalse(Hash::check($first, $document->access_token_hash), 'The old token must no longer verify.');
        $this->assertTrue(Hash::check($second, $document->access_token_hash));
    }

    // -----------------------------------------------------------------
    // Send refusals
    // -----------------------------------------------------------------

    public function test_send_requires_a_line_a_total_and_a_schedule(): void
    {
        $tenant = $this->sendableTenant();
        $manager = app(DocumentManager::class);

        $bare = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null, 'proposal', 'Empty', $tenant['customer']->user);
        $manager->edit($bare, ['recipient_email_snapshot' => 'c@example.test']);

        try {
            $manager->send($bare);
            $this->fail('A document with no line must not be sendable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('line', strtolower(implode(' ', $e->errors()['document'])));
        }

        $manager->addCustomLine($bare, 'Work', null, 1, 1000);

        try {
            $manager->send($bare->refresh());
            $this->fail('A document with no schedule must not be sendable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('schedule', strtolower(implode(' ', $e->errors()['document'])));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsendableStatuses(): array
    {
        return ['signed' => ['signed'], 'paid' => ['paid'], 'expired' => ['expired'], 'void' => ['void']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsendableStatuses')]
    public function test_a_terminal_or_signed_document_cannot_be_sent(string $status): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        DB::table('business_documents')->where('id', $document->id)->update(['status' => $status]);

        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->send($document->refresh());
    }

    // -----------------------------------------------------------------
    // Sign
    // -----------------------------------------------------------------

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Test)',
        ], $overrides);
    }

    public function test_signing_binds_the_exact_version_and_content_hash(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);

        $signature = app(DocumentManager::class)->sign($document, $this->evidence());

        $this->assertSame((int) $version->id, (int) $signature->business_document_version_id);
        $this->assertSame($version->content_hash, $signature->signed_content_hash);
        $this->assertSame('typed', $signature->signature_method->value);
        $this->assertSame(DocumentManager::CONSENT_STATEMENT, $signature->consent_statement);
        $this->assertSame(hash('sha256', DocumentManager::CONSENT_STATEMENT), $signature->consent_statement_hash);
        $this->assertSame('203.0.113.7', $signature->ip_address);
        $this->assertSame('Mozilla/5.0 (Test)', $signature->user_agent);
        $this->assertNotNull($signature->signed_at);
        $this->assertSame(DocumentStatus::Signed, $document->refresh()->status);
        $this->assertNotNull($document->signed_at);
    }

    public function test_the_signer_identity_may_differ_from_the_delivery_recipient(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['recipient' => 'office@example.test']));

        $signature = app(DocumentManager::class)->sign($document, $this->evidence(['signer_email' => 'owner@example.test']));

        $this->assertSame('office@example.test', $document->refresh()->recipient_email_snapshot);
        $this->assertSame('owner@example.test', $signature->signer_email);
    }

    public function test_signing_twice_is_impossible_and_the_second_attempt_is_a_clean_refusal(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        app(DocumentManager::class)->sign($document, $this->evidence());

        try {
            app(DocumentManager::class)->sign($document->refresh(), $this->evidence(['typed_name' => 'Someone Else']));
            $this->fail('A second signature must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been signed', implode(' ', $e->errors()['document']));
        }

        $this->assertSame(1, BusinessDocumentSignature::query()->count());
        $this->assertSame('Pat Rivera', BusinessDocumentSignature::query()->sole()->typed_name);
    }

    public function test_signing_emits_document_signed_without_signer_pii(): void
    {
        Event::fake([DocumentSigned::class]);
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        app(DocumentManager::class)->sign($document, $this->evidence());

        Event::assertDispatched(DocumentSigned::class, function (DocumentSigned $event) {
            $payload = json_encode(get_object_vars($event));
            $this->assertStringNotContainsString('@', (string) $payload);
            $this->assertStringNotContainsString('Pat', (string) $payload);

            return $event->signatureId > 0;
        });
    }

    public function test_an_invoice_that_needs_no_signature_cannot_be_signed(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['kind' => 'invoice']));

        $this->assertFalse((bool) $document->requires_signature);
        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->sign($document, $this->evidence());
    }

    public function test_an_expired_offer_cannot_be_signed(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        DB::table('business_documents')->where('id', $document->id)->update(['expires_at' => now()->subDay()]);

        $this->expectException(ValidationException::class);
        app(DocumentManager::class)->sign($document->refresh(), $this->evidence());
    }

    public function test_malformed_signature_evidence_is_refused_and_writes_nothing(): void
    {
        $tenant = $this->sendableTenant();
        [$document] = $this->sendAndCaptureToken($this->draftDocument($tenant));

        foreach ([['typed_name' => ''], ['signer_name' => ''], ['signer_email' => 'not-an-email']] as $bad) {
            try {
                app(DocumentManager::class)->sign($document->refresh(), $this->evidence($bad));
                $this->fail('Malformed evidence must be refused.');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, BusinessDocumentSignature::query()->count());
        $this->assertSame(DocumentStatus::Sent, $document->refresh()->status);
    }

    // -----------------------------------------------------------------
    // §7.3 — signature is a payability gate
    // -----------------------------------------------------------------

    public function test_a_requires_signature_document_is_not_payable_while_merely_sent(): void
    {
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $this->allowPublicEntitlement();

        $html = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();

        $this->assertStringContainsString('can be paid once it has been signed', $html);
        // Sub-slice E owns PAY START; no payment entry point exists yet.
        $this->assertStringNotContainsString('payment-start', $html);
        $this->assertSame(0, DB::table('business_document_payments')->count());
    }
}
