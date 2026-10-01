<?php

namespace Tests\Feature\Documents;

use App\Events\DocumentSent;
use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §7.1 / §11.3 — SEND is idempotent, and delivery
 * is honest.
 *
 * A browser retry must never produce a second lifecycle transition, a second
 * DocumentSent or a second email; and a mail-provider failure must leave the
 * document RETRYABLE — it never un-sends it, never 500s the owner, and never
 * lets the document read as delivered when it was not.
 */
class DocumentSendIdempotencyAndDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    // -----------------------------------------------------------------
    // Idempotent send
    // -----------------------------------------------------------------

    public function test_a_second_send_is_a_replay_with_no_new_link_email_or_event(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        Event::fake([DocumentSent::class]);
        Notification::fake();
        $manager = app(DocumentManager::class);

        $first = $manager->send($document);
        $hash = $first->access_token_hash;
        $versionId = $first->current_version_id;
        $sentAt = $first->sent_at;

        $second = $manager->send($document->refresh());
        $third = $manager->send($document->refresh());

        foreach ([$second, $third] as $replay) {
            $this->assertSame($hash, $replay->access_token_hash, 'A replay must not rotate the link.');
            $this->assertSame($versionId, $replay->current_version_id);
            $this->assertSame($sentAt->toDateTimeString(), $replay->sent_at->toDateTimeString());
        }

        $this->assertSame(1, BusinessDocumentVersion::query()->where('business_document_id', $document->id)->count());
        Event::assertDispatchedTimes(DocumentSent::class, 1);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
    }

    public function test_a_send_that_is_refused_emits_nothing_and_a_replay_never_hides_a_real_refusal(): void
    {
        Event::fake([DocumentSent::class]);
        Notification::fake();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        \Illuminate\Support\Facades\DB::table('business_documents')->where('id', $document->id)->update(['recipient_email_snapshot' => null]);

        try {
            app(DocumentManager::class)->send($document->refresh());
            $this->fail('A draft with no recipient must be refused, not replayed.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('draft', $document->fresh()->status->value);
        Event::assertNotDispatched(DocumentSent::class);
        Notification::assertNothingSent();
    }

    public function test_send_emits_an_event_carrying_the_documents_identity_and_a_stable_occurrence_key(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        Event::fake([DocumentSent::class]);
        Notification::fake();
        $sent = app(DocumentManager::class)->send($document);

        Event::assertDispatched(DocumentSent::class, function (DocumentSent $event) use ($sent, $tenant) {
            $this->assertSame((int) $sent->id, $event->documentId);
            $this->assertSame((int) $tenant['business']->id, $event->businessId);
            $this->assertSame((int) $tenant['location']->id, $event->businessLocationId);
            $this->assertSame((int) $tenant['contact']->id, $event->contactId);
            $this->assertSame('document_version_sent:' . $sent->current_version_id, $event->occurrenceKey());

            return true;
        });
    }

    public function test_lifecycle_events_are_never_emitted_for_a_rolled_back_unit_of_work(): void
    {
        $tenant = $this->sendableTenant();
        $manager = app(DocumentManager::class);
        Event::fake([DocumentSent::class, \App\Events\DocumentSigned::class, \App\Events\DocumentVoided::class]);
        Notification::fake();

        // Send succeeds inside an enclosing transaction that later fails.
        $draft = $this->draftDocument($tenant);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($manager, $draft) {
                $manager->send($draft);
                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame('draft', $draft->fresh()->status->value);

        // Sign and void likewise.
        [$sent] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        Event::fake([\App\Events\DocumentSigned::class, \App\Events\DocumentVoided::class]);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($manager, $sent) {
                $manager->sign($sent->refresh(), [
                    'displayed_version_uid' => \Tests\Support\Documents\ShownVersion::uid($sent),
                    'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
                    'ip_address' => '127.0.0.1', 'user_agent' => null,
                ]);
                $manager->void($sent->refresh(), 'x');
                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('sent', $sent->fresh()->status->value);
        Event::assertNotDispatched(\App\Events\DocumentSigned::class);
        Event::assertNotDispatched(\App\Events\DocumentVoided::class);
    }

    // -----------------------------------------------------------------
    // Delivery state — recorded, honest, retryable
    // -----------------------------------------------------------------

    public function test_a_successful_delivery_is_recorded_on_the_current_link(): void
    {
        $tenant = $this->sendableTenant();
        $document = app(DocumentManager::class)->send($this->draftDocument($tenant));

        $document = $document->fresh();
        $this->assertNotNull($document->link_delivered_at);
        $this->assertNull($document->link_delivery_failed_at);
    }

    public function test_a_provider_failure_never_unsends_never_500s_and_is_recorded_as_a_failed_delivery(): void
    {
        $this->useFailingMailTransport();
        Event::fake([DocumentSent::class]);
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);

        // The owner's request completes: the document IS issued and sent.
        $sent = app(DocumentManager::class)->send($document);

        $fresh = $sent->fresh();
        $this->assertSame('sent', $fresh->status->value);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNotNull($fresh->current_version_id);
        // ... but the document does NOT read as delivered.
        $this->assertNull($fresh->link_delivered_at);
        $this->assertNotNull($fresh->link_delivery_failed_at);
        // The lifecycle fact is still announced, exactly once.
        Event::assertDispatchedTimes(DocumentSent::class, 1);
    }

    public function test_resending_the_link_recovers_a_failed_delivery_without_a_new_transition(): void
    {
        $this->useFailingMailTransport();
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant);
        $sent = app(DocumentManager::class)->send($document)->fresh();
        $this->assertNotNull($sent->link_delivery_failed_at);

        // The provider recovers.
        $this->useWorkingMailTransport();
        Event::fake([DocumentSent::class]);
        Notification::fake();
        $token = null;
        $resent = app(DocumentManager::class)->resendLink($sent);
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$token) {
            $token = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

            return true;
        });

        // The link was rotated (every earlier link is dead) ...
        $this->assertNotSame($sent->access_token_hash, $resent->access_token_hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check((string) $token, (string) $resent->access_token_hash));
        // ... and nothing about the document's lifecycle moved.
        $this->assertSame($sent->status, $resent->status);
        $this->assertSame($sent->sent_at->toDateTimeString(), $resent->sent_at->toDateTimeString());
        $this->assertSame($sent->current_version_id, $resent->current_version_id);
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_a_recovered_resend_is_recorded_as_delivered_through_the_real_job(): void
    {
        $this->useFailingMailTransport();
        $tenant = $this->sendableTenant();
        $sent = app(DocumentManager::class)->send($this->draftDocument($tenant))->fresh();
        $this->assertNotNull($sent->link_delivery_failed_at);

        $this->useWorkingMailTransport();
        app(DocumentManager::class)->resendLink($sent);

        $fresh = $sent->fresh();
        $this->assertNotNull($fresh->link_delivered_at);
        $this->assertNull($fresh->link_delivery_failed_at);
    }

    public function test_a_job_holding_a_rotated_token_neither_emails_nor_records_anything(): void
    {
        $tenant = $this->sendableTenant();
        Notification::fake();
        $document = app(DocumentManager::class)->send($this->draftDocument($tenant));
        $oldToken = null;
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$oldToken) {
            $oldToken = (new \ReflectionProperty($notification, 'plaintextToken'))->getValue($notification);

            return true;
        });

        // The link is rotated by a re-send; the OLD token's job runs late.
        app(DocumentManager::class)->resendLink($document->refresh());
        Notification::fake();
        $before = BusinessDocument::findOrFail($document->id)->only(['link_delivered_at', 'link_delivery_failed_at']);

        (new SendDocumentLinkEmail((int) $document->id, (string) $oldToken))->handle();
        (new SendDocumentLinkEmail((int) $document->id, (string) $oldToken))->failed(new \RuntimeException('late failure'));

        Notification::assertNothingSent();
        $this->assertEquals($before, BusinessDocument::findOrFail($document->id)->only(['link_delivered_at', 'link_delivery_failed_at']));
    }

    public function test_the_link_is_resent_only_for_a_document_that_still_has_a_live_link(): void
    {
        $tenant = $this->sendableTenant();
        $manager = app(DocumentManager::class);

        // A draft has no link.
        $draft = $this->draftDocument($tenant);
        $this->assertResendRefused($manager, $draft);

        // A voided document's link is revoked for good.
        [$voidable] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $manager->void($voidable->refresh(), 'cancelled');
        $this->assertResendRefused($manager, $voidable->refresh());

        // A signed proposal IS still payable, so its link may be re-sent (Payments
        // lane): that rotates the token and changes nothing else.
        [$signed] = $this->sendAndCaptureToken($this->draftDocument($tenant));
        $signature = $manager->sign($signed->refresh(), [
            'displayed_version_uid' => \Tests\Support\Documents\ShownVersion::uid($signed),
            'signer_name' => 'Pat', 'signer_email' => 'p@example.test', 'typed_name' => 'Pat',
            'ip_address' => '127.0.0.1', 'user_agent' => null,
        ]);
        Event::fake([DocumentSent::class, \App\Events\DocumentSigned::class]);
        Notification::fake();
        $before = $signed->fresh();
        $after = $manager->resendLink($before);
        $this->assertNotSame($before->access_token_hash, $after->access_token_hash);
        $this->assertSame('signed', $after->status->value);
        $this->assertSame($before->current_version_id, $after->current_version_id);
        $this->assertSame($signature->signed_content_hash, BusinessDocumentVersion::findOrFail($after->current_version_id)->content_hash);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        Event::assertNotDispatched(DocumentSent::class);
        Event::assertNotDispatched(\App\Events\DocumentSigned::class);
    }

    private function assertResendRefused(DocumentManager $manager, BusinessDocument $document): void
    {
        Notification::fake();
        $hash = $document->access_token_hash;

        try {
            $manager->resendLink($document);
            $this->fail('resendLink() must be refused for status ' . $document->status->value);
        } catch (ValidationException) {
            // expected
        }

        Notification::assertNothingSent();
        $this->assertSame($hash, $document->fresh()->access_token_hash);
    }

    // -----------------------------------------------------------------
    // Mail transport helpers — a REAL mailer that fails / succeeds, so the
    // real notification, job and failed() path run. No live provider.
    // -----------------------------------------------------------------

    private function useFailingMailTransport(): void
    {
        Mail::extend('document_test_failing', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new \RuntimeException('simulated provider outage');
            }

            public function __toString(): string
            {
                return 'document_test_failing';
            }
        });
        config(['mail.mailers.document_test_failing' => ['transport' => 'document_test_failing'], 'mail.default' => 'document_test_failing']);
        Mail::purge();
        $this->app->forgetInstance('mailer');
    }

    private function useWorkingMailTransport(): void
    {
        config(['mail.default' => 'array']);
        Mail::purge();
        $this->app->forgetInstance('mailer');
    }
}
