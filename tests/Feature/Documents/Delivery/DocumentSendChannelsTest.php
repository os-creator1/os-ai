<?php

namespace Tests\Feature\Documents\Delivery;

use App\Events\DocumentSent;
use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Jobs\Documents\SendDocumentLinkSms;
use App\Library\Documents\Delivery\DocumentLinkSmsSender;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\Contacts;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Feature\Documents\Editor\EditorTestHelpers;
use Tests\TestCase;

/**
 * Contract 17B §7 — the send channels. ONE Draft->Sent transition and ONE token,
 * delivered over email and/or text message; the text goes only through the
 * Business SMS seam (CampaignRepository::checkQuickSendValidation + quickSend).
 */
class DocumentSendChannelsTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments, CreatesAutomationFixtures {
        SendsDocuments::platformAdminId insteadof CreatesAutomationFixtures;
        SendsDocuments::ensureRequiredAppConfigRowsExist insteadof CreatesAutomationFixtures;
    }
    use EditorTestHelpers;
    use BuildsActionWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** A draft with email AND a phone on its frozen snapshot. */
    private function document(array $tenant, bool $phone = true): BusinessDocument
    {
        $document = $this->draftDocument($tenant);

        if ($phone) {
            app(DocumentManager::class)->edit($document, ['recipient_phone_snapshot' => '+14155550123']);
        }

        return $document->refresh();
    }

    private function token(object $job): string
    {
        return (string) (new ReflectionProperty($job, 'plaintextToken'))->getValue($job);
    }

    public function test_email_only_dispatches_the_email_job_and_no_text(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $document = $this->document($this->sendableTenant());

        $sent = app(DocumentManager::class)->send($document, ['email']);

        $this->assertSame('sent', $sent->status->value);
        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
        Bus::assertNotDispatched(SendDocumentLinkSms::class);
    }

    public function test_omitting_channels_keeps_the_email_only_behaviour(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);

        app(DocumentManager::class)->send($this->document($this->sendableTenant()));

        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
        Bus::assertNotDispatched(SendDocumentLinkSms::class);
    }

    public function test_sms_only_dispatches_the_text_job_and_no_email(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);

        app(DocumentManager::class)->send($this->document($tenant), ['sms']);

        Bus::assertDispatchedTimes(SendDocumentLinkSms::class, 1);
        Bus::assertNotDispatched(SendDocumentLinkEmail::class);
    }

    public function test_both_channels_are_one_transition_one_token_one_send_event(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        Event::fake([DocumentSent::class]);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $document = $this->document($tenant);

        $sent = app(DocumentManager::class)->send($document, ['email', 'sms']);

        $this->assertSame('sent', $sent->status->value);
        $this->assertNotNull($sent->access_token_hash);
        Event::assertDispatchedTimes(DocumentSent::class, 1);
        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
        Bus::assertDispatchedTimes(SendDocumentLinkSms::class, 1);

        // Both jobs carry the SAME single plaintext token, and it is the one the
        // document's one stored hash verifies.
        $tokens = [];
        Bus::assertDispatched(SendDocumentLinkEmail::class, function ($job) use (&$tokens) { $tokens[] = $this->token($job); return true; });
        Bus::assertDispatched(SendDocumentLinkSms::class, function ($job) use (&$tokens) { $tokens[] = $this->token($job); return true; });
        $this->assertCount(2, $tokens);
        $this->assertSame($tokens[0], $tokens[1]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($tokens[0], (string) $sent->refresh()->access_token_hash));
    }

    public function test_no_channel_is_refused(): void
    {
        $document = $this->document($this->sendableTenant());

        try {
            app(DocumentManager::class)->send($document, []);
            $this->fail('An empty channel set must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('channels', $e->errors());
        }

        $this->assertSame('draft', $document->refresh()->status->value);
    }

    public function test_editor_send_with_no_channel_is_a_422(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->document($tenant);

        $this->postJson($this->ed('send', $tenant, $document), ['channels' => [], 'expected_lock_version' => $this->lock($document)])
            ->assertStatus(422);
        $this->postJson($this->ed('send', $tenant, $document), ['expected_lock_version' => $this->lock($document)])
            ->assertStatus(422);
        $this->postJson($this->ed('send', $tenant, $document), ['channels' => ['fax'], 'expected_lock_version' => $this->lock($document)])
            ->assertStatus(422);

        $this->assertSame('draft', $document->refresh()->status->value);
    }

    public function test_an_unsubscribed_contact_records_a_text_failure_without_blocking_email_or_the_send(): void
    {
        Notification::fake();
        $core = $this->captureSendCore(0);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $tenant['contact']->forceFill(['status' => Contacts::STATUS_UNSUBSCRIBE])->save();
        $document = $this->document($tenant);

        $manager = app(DocumentManager::class);
        $sent = $manager->send($document, ['email', 'sms']);

        $sent->refresh();
        $this->assertSame('sent', $sent->status->value);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        $this->assertNotNull($sent->link_delivered_at);
        $this->assertNull($sent->sms_link_delivered_at);
        $this->assertNotNull($sent->sms_link_delivery_failed_at);
        $this->assertSame(0, $core->count());

        $result = $manager->lastDelivery();
        $this->assertSame('queued', $result['email']['status']);
        $this->assertSame('failed', $result['sms']['status']);
        $this->assertSame(DocumentLinkSmsSender::REASON_UNSUBSCRIBED, $result['sms']['reason']);
        $this->assertNotEmpty($result['sms']['message']);
    }

    public function test_no_phone_records_a_text_failure_and_a_text_only_send_requires_one(): void
    {
        Notification::fake();
        $core = $this->captureSendCore(0);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $manager = app(DocumentManager::class);

        // Email + text, but no phone: the send goes through, the text is a recorded failure.
        $sent = $manager->send($this->document($tenant, false), ['email', 'sms'])->refresh();
        $this->assertSame('sent', $sent->status->value);
        $this->assertNotNull($sent->sms_link_delivery_failed_at);
        $this->assertSame(DocumentLinkSmsSender::REASON_NO_PHONE, $manager->lastDelivery()['sms']['reason']);

        // Text ONLY with no phone to send to: nothing to deliver, so refused up front.
        $other = $this->document($this->sendableTenant('Other Studio'), false);
        $this->expectException(ValidationException::class);
        $manager->send($other, ['sms']);
    }

    public function test_replaying_a_send_does_not_dispatch_again(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $document = $this->document($tenant);
        $manager = app(DocumentManager::class);

        $manager->send($document, ['email', 'sms']);
        $hash = (string) $document->refresh()->access_token_hash;
        $manager->send($document->refresh(), ['email', 'sms']);

        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
        Bus::assertDispatchedTimes(SendDocumentLinkSms::class, 1);
        $this->assertSame($hash, (string) $document->refresh()->access_token_hash);
    }

    public function test_resend_clears_both_markers_and_accepts_channels(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $document = $this->document($tenant);
        $manager = app(DocumentManager::class);
        $manager->send($document, ['email']);

        BusinessDocument::query()->whereKey($document->id)->update([
            'link_delivered_at' => now(), 'link_delivery_failed_at' => now(),
            'sms_link_delivered_at' => now(), 'sms_link_delivery_failed_at' => now(),
        ]);

        $manager->resendLink($document->refresh(), ['sms']);

        $fresh = $document->refresh();
        $this->assertNull($fresh->link_delivered_at);
        $this->assertNull($fresh->link_delivery_failed_at);
        $this->assertNull($fresh->sms_link_delivered_at);
        $this->assertNull($fresh->sms_link_delivery_failed_at);
        Bus::assertDispatchedTimes(SendDocumentLinkSms::class, 1);
        Bus::assertDispatchedTimes(SendDocumentLinkEmail::class, 1);
    }

    public function test_the_text_goes_only_through_the_business_sms_seam_and_carries_the_real_link(): void
    {
        Notification::fake();
        $core = $this->captureSendCore(1);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);
        $document = $this->document($tenant);

        $sent = app(DocumentManager::class)->send($document, ['sms'], 'Hi Pat, here is the proposal.')->refresh();

        $this->assertSame(1, $core->count(), 'quickSend() must be called exactly once.');
        $payload = $core->lastPayload();
        $this->assertSame((int) $tenant['business']->id, (int) $payload['business_id']);
        $this->assertSame('AUTOSENDER', $payload['sender_id']);
        $this->assertSame((int) $tenant['business']->id, (int) $core->campaignBusinessIds[0]);
        $this->assertSame('415', substr((string) $payload['recipient'], 0, 3));
        $this->assertStringStartsWith('Hi Pat, here is the proposal. ', $payload['message']);
        $this->assertMatchesRegularExpression('#/documents/' . $document->uid . '/[A-Za-z0-9]{64}$#', $payload['message']);
        $this->assertNotNull($sent->sms_link_delivered_at);
        $this->assertNull($sent->sms_link_delivery_failed_at);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 0);
        Http::assertNothingSent();
    }

    public function test_a_rejected_text_is_recorded_as_failed(): void
    {
        Notification::fake();
        $this->captureSendCore(1, false);
        $tenant = $this->sendableTenant();
        $this->sendableChannel($tenant['business']);

        $sent = app(DocumentManager::class)->send($this->document($tenant), ['sms'])->refresh();

        $this->assertSame('sent', $sent->status->value);
        $this->assertNull($sent->sms_link_delivered_at);
        $this->assertNotNull($sent->sms_link_delivery_failed_at);
    }

    public function test_the_default_message_is_safe_and_the_link_is_always_appended(): void
    {
        $tenant = $this->sendableTenant('Harbor <b>Lane</b>');
        $document = $this->document($tenant);
        $sender = app(DocumentLinkSmsSender::class);
        $url = 'https://example.test/documents/x/y';

        $default = $sender->compose($document, $url, null);
        $this->assertStringContainsString('sent you "Kitchen renovation proposal". Review and sign: ' . $url, $default);

        $custom = $sender->compose($document, $url, str_repeat('a', 1000) . "\x07");
        $this->assertStringEndsWith(' ' . $url, $custom);
        $this->assertLessThanOrEqual(DocumentLinkSmsSender::MAX_CUSTOM_MESSAGE + 1 + strlen($url), mb_strlen($custom));
        $this->assertStringNotContainsString("\x07", $custom);
    }

    public function test_editor_send_returns_per_channel_results_and_accepts_a_typed_recipient(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->editorTenant();
        $this->sendableChannel($tenant['business']);
        $document = $this->document($tenant, false);

        $response = $this->postJson($this->ed('send', $tenant, $document), [
            'channels' => ['email', 'sms'],
            'recipient_phone' => '+14155550199',
            'message' => 'Take a look',
            'expected_lock_version' => $this->lock($document),
        ])->assertOk()
            ->assertJsonPath('document_status', 'sent')
            ->assertJsonPath('delivery.email.status', 'queued')
            ->assertJsonPath('delivery.sms.status', 'queued');

        $this->assertSame('+14155550199', $document->refresh()->recipient_phone_snapshot);
        $this->assertSame('sent', $document->status->value);
        Bus::assertDispatchedTimes(SendDocumentLinkSms::class, 1);
        $this->assertArrayHasKey('lock_version', $response->json());
    }

    public function test_a_typed_recipient_never_overrides_a_prefilled_snapshot(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->editorTenant();
        $document = $this->document($tenant);

        $this->postJson($this->ed('send', $tenant, $document), [
            'channels' => ['email'],
            'recipient_email' => 'someone-else@example.test',
            'expected_lock_version' => $this->lock($document),
        ])->assertOk();

        $this->assertSame('client@example.test', $document->refresh()->recipient_email_snapshot);
    }

    public function test_editor_send_with_a_stale_lock_version_is_a_409_and_sends_nothing(): void
    {
        Bus::fake([SendDocumentLinkEmail::class, SendDocumentLinkSms::class]);
        $tenant = $this->editorTenant();
        $document = $this->document($tenant);

        $this->postJson($this->ed('send', $tenant, $document), ['channels' => ['email'], 'expected_lock_version' => $this->lock($document) + 5])
            ->assertStatus(409);

        $this->assertSame('draft', $document->refresh()->status->value);
        Bus::assertNothingDispatched();
    }

    public function test_the_editor_bootstrap_carries_recipient_and_subscription_info(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->document($tenant);

        $delivery = app(\App\Library\Documents\Editor\DocumentEditorState::class)->bootstrap($document)['delivery'];

        $this->assertSame('client@example.test', $delivery['email']);
        $this->assertSame('+14155550123', $delivery['phone']);
        $this->assertTrue($delivery['contact_subscribed']);
        $this->assertArrayHasKey('sms_default_message', $delivery);
        $this->assertSame(DocumentLinkSmsSender::MAX_CUSTOM_MESSAGE, $delivery['sms_max_message']);
    }

    public function test_text_delivery_lives_in_its_own_classes_and_reaches_no_provider_directly(): void
    {
        $root = dirname(__DIR__, 4);
        $strip = function (string $file) use ($root): string {
            $source = '';
            foreach (token_get_all((string) file_get_contents($root . $file)) as $token) {
                if (is_array($token)) {
                    if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $source .= $token[1];
                    }
                } else {
                    $source .= $token;
                }
            }

            return $source;
        };

        $sender = $strip('/app/Library/Documents/Delivery/DocumentLinkSmsSender.php');
        $job = $strip('/app/Jobs/Documents/SendDocumentLinkSms.php');

        // The Business SMS seam, and only it.
        $this->assertStringContainsString('checkQuickSendValidation', $sender);
        $this->assertStringContainsString('quickSend', $sender);
        $this->assertStringContainsString('DocumentLinkSmsSender', $job);

        foreach (['Twilio', 'Nexmo', 'Telnyx', 'Http::', 'Guzzle', 'curl_', 'ManagedMessageDispatcher', 'sms_unit', 'Wallet', 'Notification::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sender, "The text sender must not reach [{$forbidden}].");
            $this->assertStringNotContainsString($forbidden, $job, "The text job must not reach [{$forbidden}].");
        }

        // The job is encrypted, after-commit and never retried, like its email twin.
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, class_implements(SendDocumentLinkSms::class));
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldQueueAfterCommit::class, class_implements(SendDocumentLinkSms::class));

        // DocumentManager itself holds no text-message code at all.
        $manager = $strip('/app/Library/Documents/DocumentManager.php');
        foreach (['quickSend', 'Sms', 'sms'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $manager);
        }
    }
}
