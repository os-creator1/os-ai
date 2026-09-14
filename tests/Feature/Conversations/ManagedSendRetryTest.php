<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Conversations\ConversationSendFailureReason;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Customer;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Conversations failed-send/retry.
 *
 * A manual send Conversations could not put through must show that
 * truthfully instead of making it disappear, and a deliberate Retry must
 * update the SAME customer-visible bubble — never a duplicate, never a
 * second provider send it did not ask for.
 *
 * ManagedOutboundConversationHistoryTest already proves: a first reply
 * (accepted), a refused reply (now a truthful failed bubble), replay
 * idempotency, and that an accepted send survives its own history write
 * failing. This file covers the retry seam specifically: a topped-up retry
 * succeeding, a double-click, a later delivery failure and its own retry,
 * and Business isolation.
 */
class ManagedSendRetryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;

    private const PERSON = '14155552671';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
    }

    // =================================================================
    // B. Balance topped up — retry succeeds
    // =================================================================

    public function test_a_retry_after_the_provider_is_fixed_succeeds_updates_the_same_bubble_and_makes_exactly_one_new_provider_call(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;

        $this->reply($workspace, $business, $box, 'Are you free Saturday?', (string) Str::uuid())
            ->assertJson(['status' => 'error']);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The one refused attempt.');

        // The condition that refused it is gone.
        $this->fakeAdapter->rejections = [];

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'Exactly one NEW provider call for the retry.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('direction', 'outgoing')->count(), 'Still one logical bubble.');

        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sent', $updated->send_status);
        $this->assertNull($updated->send_failure_reason);
        $this->assertSame('Are you free Saturday?', $updated->message, 'The original text, unchanged.');
        $this->assertSame($message->send_uid, $updated->send_uid, 'The same logical message.');
        $this->assertNotSame((int) $message->business_messaging_operation_id ?: null, (int) $updated->business_messaging_operation_id, 'Now points at the NEW, accepted operation.');

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertSame(1, substr_count($timeline, 'Are you free Saturday?'), 'One bubble, not two.');
        $this->assertStringNotContainsString(__('locale.conversations.retry'), $timeline, 'A sent message offers no Retry.');
    }

    // =================================================================
    // C. Double-click retry
    // =================================================================

    public function test_a_retry_already_in_flight_is_refused_and_makes_no_provider_call(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;

        $this->reply($workspace, $business, $box, 'Double click me', (string) Str::uuid())
            ->assertJson(['status' => 'error']);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertCount(1, $this->fakeAdapter->sentRequests);

        // The claim a first, still-in-flight retry click already made —
        // freshly stamped, so reconciliation (item 4) correctly treats it
        // as a live claim rather than a dead one and leaves it untouched.
        DB::table('chat_box_messages')->where('id', $message->id)->update(['send_status' => 'sending', 'send_claimed_at' => now()]);

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The second click made no provider call at all.');
        $this->assertSame('sending', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    // =================================================================
    // F. A later delivery failure, and its own deliberate retry
    // =================================================================

    public function test_a_delivery_failure_turns_the_bubble_into_delivery_failed_and_its_retry_is_one_new_provider_attempt(): void
    {
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        $this->reply($workspace, $business, $box, 'On my way!', (string) Str::uuid())
            ->assertJson(['status' => 'success']);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('sent', $message->send_status);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);

        $operation = DB::table('business_messaging_operations')->where('id', $message->business_messaging_operation_id)->sole();

        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus,
            messagingProfileId: $identity->messaging_profile_id,
            destinationNumber: null,
            fromNumber: null,
            body: null,
            mediaUrls: [],
            providerMessageId: $operation->provider_message_id,
            deliveryStatus: 'failed',
            occurredAt: CarbonImmutable::now(),
        ));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();

        $afterDlr = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('delivery_failed', $afterDlr->send_status);
        $this->assertSame('delivery_failed', $afterDlr->send_failure_reason);

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertStringContainsString(__('locale.conversations.retry'), $timeline);

        // The deliberate retry — a genuinely new provider attempt.
        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'One new provider attempt, not a resend of the original.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('direction', 'outgoing')->count(), 'Still one bubble.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));

        // A late/duplicate DLR for the ORIGINAL, now-superseded operation
        // changes nothing about the bubble the retry moved on to.
        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus,
            messagingProfileId: $identity->messaging_profile_id,
            destinationNumber: null,
            fromNumber: null,
            body: null,
            mediaUrls: [],
            providerMessageId: $operation->provider_message_id,
            deliveryStatus: 'delivered',
            occurredAt: CarbonImmutable::now(),
        ));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'), 'Untouched — that operation no longer owns this bubble.');
    }

    // =================================================================
    // G. Business isolation
    // =================================================================

    public function test_a_retry_cannot_reach_another_businesss_message(): void
    {
        [, $alpha, $alphaWorkspace] = $this->managedTenant('+14155550199');
        [, $bravo, $bravoWorkspace] = $this->managedTenant('+14155550288');

        $alphaBox = $this->inboundConversation($alpha, self::PERSON, '14155550199');
        $bravoBox = $this->inboundConversation($bravo, self::PERSON, '14155550288');

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($alphaWorkspace, $alpha, $alphaBox, 'Alphas private failed message', (string) Str::uuid())
            ->assertJson(['status' => 'error']);

        $alphaMessage = DB::table('chat_box_messages')->where('box_id', $alphaBox->id)->sole();
        $this->fakeAdapter->rejections = [];

        // Bravo's own owner, retrying against Bravo's own conversation, but
        // naming Alpha's send_uid.
        $this->retry($bravoWorkspace, $bravo, $bravoBox, $alphaMessage->send_uid)->assertNotFound();

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'No provider call was ever made for the foreign uid.');
        $this->assertSame('failed', DB::table('chat_box_messages')->where('id', $alphaMessage->id)->value('send_status'), "Alpha's message is untouched.");
        $this->assertSame(0, DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->count(), "Bravo's conversation gained nothing.");
    }

    /**
     * PR #301 correction item 1 — send_uid is CLIENT-chosen, untrusted
     * input, so it is never trusted as globally unique. Two independent
     * Businesses using the IDENTICAL uid produce two completely independent
     * bubbles, in their own conversations, each correctly reflecting its
     * OWN outcome — never one overwriting or being confused with the other.
     */
    public function test_the_same_send_uid_in_two_businesses_produces_two_independent_bubbles(): void
    {
        [, $alpha, $alphaWorkspace] = $this->managedTenant('+14155550199');
        [, $bravo, $bravoWorkspace] = $this->managedTenant('+14155550288');

        $alphaBox = $this->inboundConversation($alpha, self::PERSON, '14155550199');
        $bravoBox = $this->inboundConversation($bravo, self::PERSON, '14155550288');

        $sharedUid = (string) Str::uuid();

        // Alpha's send is refused; Bravo's send, using the SAME uid, is accepted.
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($alphaWorkspace, $alpha, $alphaBox, 'Alpha message', $sharedUid)->assertJson(['status' => 'error']);
        $this->fakeAdapter->rejections = [];
        $this->reply($bravoWorkspace, $bravo, $bravoBox, 'Bravo message', $sharedUid)->assertJson(['status' => 'success']);

        $this->assertSame(2, DB::table('chat_box_messages')->where('send_uid', $sharedUid)->count(), 'Two rows share the uid — one per Business.');

        $alphaMessage = DB::table('chat_box_messages')->where('box_id', $alphaBox->id)->sole();
        $bravoMessage = DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->sole();

        $this->assertSame($sharedUid, $alphaMessage->send_uid);
        $this->assertSame($sharedUid, $bravoMessage->send_uid);
        $this->assertNotSame((int) $alphaMessage->id, (int) $bravoMessage->id, 'Two distinct rows, not one shared row.');

        $this->assertSame('Alpha message', $alphaMessage->message);
        $this->assertSame('failed', $alphaMessage->send_status, "Alpha's own outcome, unaffected by Bravo's.");

        $this->assertSame('Bravo message', $bravoMessage->message);
        $this->assertSame('sent', $bravoMessage->send_status, "Bravo's own outcome, unaffected by Alpha's.");
    }

    /**
     * PR #301 correction item 1 — the SAME box using the SAME send_uid
     * twice (a replayed request, or a retry that reused rather than
     * minted a fresh key) is the idempotent case the composite
     * (box_id, send_uid) key exists to collapse to one row.
     */
    public function test_the_same_send_uid_in_the_same_box_stays_one_idempotent_row(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sharedUid = (string) Str::uuid();

        $this->reply($workspace, $business, $box, 'Once', $sharedUid)->assertJson(['status' => 'success']);
        $this->reply($workspace, $business, $box, 'Once', $sharedUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The dispatcher\'s own operation-key idempotency: one provider call.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->where('send_uid', $sharedUid)->count());
    }

    // =================================================================
    // Correction round 2, item 1 — managed messaging unavailable
    // =================================================================

    public function test_a_first_send_with_managed_messaging_disabled_records_a_failed_bubble_with_zero_provider_calls(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        config(['messaging.managed_messaging_enabled' => false]);

        $this->reply($workspace, $business, $box, 'Are you open today?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.messaging_unavailable')]);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Thrown before the adapter is even resolved.');
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->assertSame('messaging_unavailable', $message->send_failure_reason);
    }

    public function test_a_retry_while_still_unavailable_still_makes_no_provider_call(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        config(['messaging.managed_messaging_enabled' => false]);
        $this->reply($workspace, $business, $box, 'Are you open today?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.messaging_unavailable')]);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $again = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('failed', $again->send_status);
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one bubble.');
    }

    public function test_a_retry_after_availability_is_restored_makes_exactly_one_new_provider_attempt(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        config(['messaging.managed_messaging_enabled' => false]);
        $this->reply($workspace, $business, $box, 'Are you open today?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        config(['messaging.managed_messaging_enabled' => true]);

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Exactly one new provider attempt, once available again.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    // =================================================================
    // Correction round 2, item 2 — first-send preparation failures
    // =================================================================

    public function test_a_spam_refusal_on_first_send_creates_exactly_one_failed_bubble_with_zero_provider_calls(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        \App\Models\SpamWord::create(['word' => 'freegift']);

        $this->reply($workspace, $business, $box, 'Claim your freegift now', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => 'Your message contains spam words.']);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('Claim your freegift now', $message->message);
        $this->assertSame('failed', $message->send_status);
        $this->assertSame('send_failed', $message->send_failure_reason);
    }

    public function test_an_invalid_destination_refusal_on_first_send_creates_exactly_one_failed_bubble_with_zero_provider_calls(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        DB::table('chat_boxes')->where('id', $box->id)->update(['to' => 'not-a-number']);

        $this->reply($workspace, $business, $box->fresh(), 'Hello?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('Hello?', $message->message);
        $this->assertSame('failed', $message->send_status);
    }

    public function test_a_preparation_failure_that_later_succeeds_on_retry_stays_one_bubble(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        \App\Models\SpamWord::create(['word' => 'discount']);
        $sendUid = (string) Str::uuid();

        $this->reply($workspace, $business, $box, 'Huge discount inside', $sendUid)->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        // The word is removed — the same reason a real customer could retry
        // successfully (they edited the message, or the filter changed).
        \App\Models\SpamWord::query()->delete();

        $this->retry($workspace, $business, $box, $sendUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The retry is the first-ever provider call for this message.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one logical bubble.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    public function test_a_preparation_failure_on_a_non_managed_business_creates_no_bubble(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);
        \App\Models\SpamWord::create(['word' => 'freegift']);
        $box = app(\App\Library\Conversations\ConversationHistoryWriter::class)->conversationFor($business, '14155550199', self::PERSON);
        $box->save();

        $this->reply($workspace, $business, $box, 'Claim your freegift now', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => 'Your message contains spam words.']);

        $this->assertSame(0, DB::table('chat_box_messages')->count(), 'Non-managed behaviour is unchanged: no bubble at all.');
    }

    public function test_a_client_input_media_validation_failure_creates_no_bubble(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);

        $response = $this->post(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->uid]), [
            'message' => 'A message with a bad attachment',
            'idempotency_token' => (string) Str::uuid(),
            'media_image' => \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 10),
        ]);

        $response->assertOk()->assertJson(['status' => 'error']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, DB::table('chat_box_messages')->count(), 'A pure client-input validation error creates no bubble, exactly as before.');
    }

    // =================================================================
    // Correction round 3, item 1 — a racing failure never downgrades success
    // =================================================================

    /**
     * The exact race: reply() has no claim/lock of its own, so a
     * double-submitted first send reaches ManagedMessageDispatcher::dispatch()
     * twice with the SAME operation key. The loser finds the winner's
     * operation row still 'attempted' and is told "not accepted" —
     * simulated here directly against the writer, the seam
     * attemptManagedSend() itself calls into for exactly this outcome.
     */
    public function test_a_racing_failure_write_never_downgrades_an_already_accepted_send(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        // The winning copy — a real, successful send.
        $this->reply($workspace, $business, $box, 'Race condition test', $sendUid)->assertJson(['status' => 'success']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('sent', $message->send_status);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);

        // The losing copy's stale failure result, landing afterward.
        app(ConversationHistoryWriter::class)->recordManualSendFailure(
            $business,
            $box->fresh(),
            'Race condition test',
            [],
            'plain',
            $sendUid,
            ConversationSendFailureReason::MessagingNotReady->value,
        );

        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'), 'The racing failure write must never downgrade an already-accepted send.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'One bubble.');
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'No second provider call was ever made.');

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertStringNotContainsString(__('locale.conversations.retry'), $timeline, 'A sent message offers no Retry.');
    }

    // =================================================================
    // Correction round 3, item 2 — an MMS attachment survives a later
    // preparation failure, and retry reuses it verbatim
    // =================================================================

    public function test_an_mms_attachment_survives_a_later_preparation_failure_and_retry_reuses_it(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        DB::table('chat_boxes')->where('id', $box->id)->update(['to' => 'not-a-number']);
        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);
        $sendUid = (string) Str::uuid();

        $response = $this->post(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->fresh()->uid]), [
            'message' => 'Here is the flyer',
            'idempotency_token' => $sendUid,
            'media_image' => \Illuminate\Http\UploadedFile::fake()->image('flyer.jpg'),
        ]);

        $response->assertOk()->assertJson(['status' => 'error']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->assertSame('Here is the flyer', $message->message);
        $this->assertNotNull($message->media_url, 'The already-uploaded attachment must survive a later preparation failure.');
        $this->assertSame('mms', $message->sms_type);
        $originalMediaUrl = $message->media_url;

        // The destination is fixed.
        DB::table('chat_boxes')->where('id', $box->id)->update(['to' => self::PERSON]);

        $this->retry($workspace, $business, $box->fresh(), $sendUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Exactly one provider call — the retry.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'One logical bubble.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sent', $updated->send_status);
        $this->assertSame($originalMediaUrl, $updated->media_url, 'Retry used the ORIGINAL attachment, never re-uploaded.');
        $this->assertSame('mms', $updated->sms_type);
    }

    // =================================================================
    // Correction round 3, item 3 — a historical bubble still reaches
    // retry checks even once the managed identity is no longer active
    // =================================================================

    public function test_a_historical_bubble_reaches_retry_checks_after_the_identity_is_deactivated_and_recovers_once_restored(): void
    {
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        // Sender-id verification is left at the Plan's own DEFAULT ('yes')
        // deliberately (correction round 4, item 5) — round 3's workaround
        // of disabling it to make this test pass is no longer needed, and
        // masked exactly the bug round 4 item 5 closes: with the round 4
        // item 2 fix, retry() refuses a missing managed identity BEFORE
        // buildManualSendInput() ever runs, so this legacy-specific check
        // never has a chance to interfere regardless of its setting.
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $business, $box, 'Still there?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->fakeAdapter->rejections = [];
        $this->assertCount(1, $this->fakeAdapter->sentRequests);

        // The identity is archived — the Business is not "managed" right
        // now. Correction round 4, item 2 — retry() now refuses a
        // historical managed bubble outright the moment its Business has no
        // CURRENT usable managed identity, with zero provider calls of any
        // kind (managed OR legacy — this fixture's managedTenant() also
        // wires a perfectly usable legacy/BYO sending server, and the old
        // behaviour of falling through to it is exactly what this
        // correction closes). The reason is the same,
        // precise 'messaging_not_ready' every other zero-identity refusal
        // in this suite already reports.
        DB::table('business_messaging_identities')->where('id', $identity->id)->update(['status' => BusinessMessagingIdentityStatus::Archived->value]);

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.messaging_not_ready')]);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'No provider call while the identity is gone — the bubble is found, not 404, and never falls back to legacy.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('failed', $updated->send_status);
        $this->assertSame('messaging_not_ready', $updated->send_failure_reason);
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one bubble — the same one, updated.');

        // Restored.
        DB::table('business_messaging_identities')->where('id', $identity->id)->update(['status' => BusinessMessagingIdentityStatus::Active->value]);

        $this->retry($workspace, $business, $box, $message->send_uid)->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'Exactly one new attempt, once restored.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    public function test_a_legacy_row_still_cannot_use_the_retry_endpoint(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        // A legacy-shaped row: no send_uid at all — exactly what every
        // non-Conversations-manual writer has always produced.
        ChatBoxMessage::create([
            'box_id' => $box->id,
            'message' => 'Legacy outbound',
            'sms_type' => 'plain',
            'direction' => 'outgoing',
            'send_by' => 'from',
        ]);

        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);
        $this->postJson(route('customer.workspaces.businesses.conversations.retry', [$workspace->uid, $business->uid, $box->uid]), [
            'send_uid' => (string) Str::uuid(),
        ])->assertNotFound();

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // =================================================================
    // Correction round 3, item 4 — a retry must not stay "sending…" forever
    // =================================================================

    public function test_a_stale_sending_claim_with_no_operation_is_safely_recovered_and_completes_a_fresh_attempt(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        // A claim that died before ManagedMessageDispatcher ever recorded
        // an attempt for it — no operation row exists at all — old enough
        // that it cannot plausibly still be a live request.
        $message = ChatBoxMessage::create([
            'box_id' => $box->id, 'message' => 'Stuck, no operation', 'sms_type' => 'plain',
            'direction' => 'outgoing', 'send_by' => 'from',
            'send_uid' => $sendUid, 'send_status' => 'sending', 'retry_count' => 1,
            'send_claimed_at' => now()->subMinutes(10),
        ]);

        $this->retry($workspace, $business, $box, $sendUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Exactly one provider call — the fresh attempt reconciliation released it into.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sent', $updated->send_status);
        $this->assertSame(2, $updated->retry_count, 'The fresh attempt claimed and incremented once more.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one bubble.');
    }

    public function test_reconciliation_of_an_accepted_operation_reconciles_to_sent_without_resending(): void
    {
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        $message = ChatBoxMessage::create([
            'box_id' => $box->id, 'message' => 'Accepted but the request died after', 'sms_type' => 'plain',
            'direction' => 'outgoing', 'send_by' => 'from',
            'send_uid' => $sendUid, 'send_status' => 'sending', 'retry_count' => 1,
            'send_claimed_at' => now(),
        ]);
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => 'conversation:' . $box->id . ':' . $sendUid . ':retry:1',
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_stuck',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->retry($workspace, $business, $box, $sendUid)
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Reconciliation only — never resent for an operation the provider already accepted.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sent', $updated->send_status);
        $this->assertSame($operationId, (int) $updated->business_messaging_operation_id);
        $this->assertSame(1, $updated->retry_count, 'No new attempt was claimed — reconciliation to an accepted operation is terminal.');

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertStringNotContainsString(__('locale.conversations.retry'), $timeline);
    }

    public function test_reconciliation_of_a_rejected_operation_lets_the_same_click_complete_a_fresh_attempt(): void
    {
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        $message = ChatBoxMessage::create([
            'box_id' => $box->id, 'message' => 'Rejected, request died before reconciling', 'sms_type' => 'plain',
            'direction' => 'outgoing', 'send_by' => 'from',
            'send_uid' => $sendUid, 'send_status' => 'sending', 'retry_count' => 1,
            'send_claimed_at' => now(),
        ]);
        DB::table(ManagedMessageDispatcher::TABLE)->insert([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => 'conversation:' . $box->id . ':' . $sendUid . ':retry:1',
            'status' => MessagingOperationStatus::Rejected->value,
            'error_category' => ProviderErrorCategory::Terminal->value,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->retry($workspace, $business, $box, $sendUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Reconciliation made zero provider calls; this is the one FRESH attempt.');
        $this->assertSame(2, DB::table(ManagedMessageDispatcher::TABLE)->where('business_id', $business->id)->count(), 'The old rejected operation, plus one new attempt — never resent under the old key.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sent', $updated->send_status);
        $this->assertSame(2, $updated->retry_count, 'The fresh attempt incremented it again.');
    }

    public function test_reconciliation_of_a_genuinely_ambiguous_attempted_operation_never_resends(): void
    {
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        $message = ChatBoxMessage::create([
            'box_id' => $box->id, 'message' => 'Ambiguous', 'sms_type' => 'plain',
            'direction' => 'outgoing', 'send_by' => 'from',
            'send_uid' => $sendUid, 'send_status' => 'sending', 'retry_count' => 1,
            // Old enough that a NO-operation claim would be treated as
            // stale — proving the ambiguous-Attempted case is never
            // resolved by elapsed time, unlike the no-operation case.
            'send_claimed_at' => now()->subMinutes(10),
        ]);
        DB::table(ManagedMessageDispatcher::TABLE)->insert([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => 'conversation:' . $box->id . ':' . $sendUid . ':retry:1',
            'status' => MessagingOperationStatus::Attempted->value,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->retry($workspace, $business, $box, $sendUid)
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.retry_in_progress')]);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Never guesses — no resend for a genuinely ambiguous outcome, however old the claim.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('sending', $updated->send_status, 'Left exactly as found.');
        $this->assertSame(1, $updated->retry_count);
    }

    // =================================================================
    // Correction round 4, item 1 — an ambiguous provider outcome must
    // never offer Retry
    // =================================================================

    public function test_a_transport_timeout_produces_one_attempt_and_an_ambiguous_bubble_with_no_retry(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        // TelnyxMessagingAdapter's own transport-exception/timeout branch —
        // the provider may already have accepted the message.
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Retryable;

        $this->reply($workspace, $business, $box, 'Are you open?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.ambiguous')]);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Exactly one provider attempt — the outcome is unconfirmed, never assumed either way.');
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('ambiguous', $message->send_status);
        $this->assertSame('ambiguous', $message->send_failure_reason);

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertStringContainsString(__('locale.conversations.ambiguous_label'), $timeline, 'Still visible, and truthfully labelled.');
        $this->assertStringNotContainsString(__('locale.conversations.retry'), $timeline, 'No Retry control for an unconfirmed outcome — a second click could double-send.');
    }

    public function test_a_direct_retry_post_against_an_ambiguous_bubble_is_refused_with_zero_additional_provider_calls(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Retryable;
        $this->reply($workspace, $business, $box, 'Are you open?', (string) Str::uuid());
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('ambiguous', $message->send_status);
        $this->fakeAdapter->rejections = [];

        // The server-side claim transaction refuses it structurally — never
        // merely a hidden Blade button — even posted to directly with the
        // bubble's own send_uid.
        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Zero additional provider calls.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('ambiguous', $updated->send_status, 'Untouched — never silently resolved into a retryable state either.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
    }

    public function test_an_unconfirmed_two_hundred_response_gets_the_same_conservative_ambiguous_treatment(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        // TelnyxMessagingAdapter's own "2xx we cannot correlate" branch.
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Unknown;

        $this->reply($workspace, $business, $box, 'Are you open?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.ambiguous')]);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('ambiguous', $message->send_status);

        $this->retry($workspace, $business, $box, $message->send_uid)->assertJson(['status' => 'error']);
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Still refused — Unknown gets the identical conservative treatment as Retryable.');
    }

    public function test_a_conclusive_terminal_rejection_remains_deliberately_retryable(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;

        $this->reply($workspace, $business, $box, 'Are you open?', (string) Str::uuid())->assertJson(['status' => 'error']);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status, 'A conclusive provider rejection stays plainly failed — never ambiguous.');

        $timeline = $this->openTimeline($workspace, $business, $box)->json('timeline');
        $this->assertStringContainsString(__('locale.conversations.retry'), $timeline, 'Terminal/Configuration failures stay retryable.');

        $this->fakeAdapter->rejections = [];
        $this->retry($workspace, $business, $box, $message->send_uid)->assertJson(['status' => 'success']);
        $this->assertCount(2, $this->fakeAdapter->sentRequests);
    }

    // =================================================================
    // Correction round 4, item 2 — a historical managed retry bubble must
    // never fall back to legacy/BYO transport
    // =================================================================

    public function test_a_historical_managed_retry_never_falls_back_to_a_usable_legacy_sending_server(): void
    {
        // managedTenant() wires BOTH a managed identity AND a perfectly
        // usable legacy/BYO sending server (via sendableChannel()) — the
        // exact configuration item 2's bug required to actually matter.
        [, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $business, $box, 'Still there?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->fakeAdapter->rejections = [];

        // A legacy send — success OR failure — always writes a Reports row
        // (EloquentCampaignRepository::quickSend()'s legacy path). Counting
        // it before/after is how this proves the legacy provider was never
        // reached at all, not merely that it didn't "succeed".
        $reportsBefore = DB::table('reports')->count();

        DB::table('business_messaging_identities')->where('id', $identity->id)->update(['status' => BusinessMessagingIdentityStatus::Archived->value]);

        $sentBeforeRetryAttempt = count($this->fakeAdapter->sentRequests);

        $this->retry($workspace, $business, $box, $message->send_uid)
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.conversations.send_failure.messaging_not_ready')]);

        $this->assertCount($sentBeforeRetryAttempt, $this->fakeAdapter->sentRequests, 'Zero ADDITIONAL provider calls of any kind — managed or legacy.');
        $this->assertSame($reportsBefore, DB::table('reports')->count(), 'Zero LEGACY sends either — never silently fell back.');
        $updated = DB::table('chat_box_messages')->where('id', $message->id)->sole();
        $this->assertSame('failed', $updated->send_status);
        $this->assertSame('messaging_not_ready', $updated->send_failure_reason);
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one bubble — the same one.');

        // Restored — exactly one MANAGED attempt, still never a legacy one.
        DB::table('business_messaging_identities')->where('id', $identity->id)->update(['status' => BusinessMessagingIdentityStatus::Active->value]);

        $this->retry($workspace, $business, $box, $message->send_uid)->assertJson(['status' => 'success']);

        $this->assertCount($sentBeforeRetryAttempt + 1, $this->fakeAdapter->sentRequests, 'Exactly one new managed attempt, once restored.');
        $this->assertSame($reportsBefore, DB::table('reports')->count(), 'Still zero Reports rows — managed transport writes no Report.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    public function test_normal_legacy_byo_conversations_replies_are_unaffected_by_the_managed_retry_guard(): void
    {
        // A genuinely NON-managed Business. Item 2's guard lives entirely
        // inside retry(), gated on finding a bubble by its persisted
        // send_uid; a legacy conversation never carries one at all (see
        // test_a_legacy_row_still_cannot_use_the_retry_endpoint), so
        // reply()'s ordinary legacy refusal must be provably UNCHANGED —
        // still the exact same refusal this fixture always produced
        // (mirrors ManagedOutboundConversationHistoryTest's own
        // sender-verification-refuses-non-managed test), never the new
        // 'messaging_not_ready' reason a managed-only Business gets.
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);
        $customer->user->sms_unit = 1000;
        $customer->user->save();

        $box = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550199', self::PERSON);
        $box->save();

        $this->reply($workspace, $business, $box->fresh(), 'A normal legacy reply', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => '14155550199'])]);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Never reaches managed transport — this Business has no managed identity at all.');
        $this->assertSame(0, DB::table('chat_box_messages')->count(), 'Non-managed behaviour is unchanged (item 8 H): no bubble at all, exactly like every other non-managed preparation refusal in this suite.');
    }

    // =================================================================
    // Correction round 4, item 3 — operation keys must also be scoped to
    // the conversation, not only to the Business
    // =================================================================

    public function test_the_same_send_uid_in_two_conversations_of_one_business_produces_two_independent_sends(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $alphaBox = $this->inboundConversation($business, '14155552671');
        $bravoBox = $this->inboundConversation($business, '14155553333');
        $sharedUid = (string) Str::uuid();

        $this->reply($workspace, $business, $alphaBox, 'For Alpha', $sharedUid)->assertJson(['status' => 'success']);
        $this->reply($workspace, $business, $bravoBox, 'For Bravo', $sharedUid)->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'Two independent provider sends — the shared client token never collapsed them into one.');
        $this->assertSame('+14155552671', $this->fakeAdapter->sentRequests[0]->toNumber);
        $this->assertSame('+14155553333', $this->fakeAdapter->sentRequests[1]->toNumber);
        $this->assertNotSame(
            $this->fakeAdapter->sentRequests[0]->operationKey,
            $this->fakeAdapter->sentRequests[1]->operationKey,
            'Two distinct, conversation-scoped operation keys.',
        );

        $this->assertSame(2, DB::table('business_messaging_operations')->where('business_id', $business->id)->count(), 'Two independent operation rows.');

        $alphaMessage = DB::table('chat_box_messages')->where('box_id', $alphaBox->id)->sole();
        $bravoMessage = DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->sole();
        $this->assertSame($sharedUid, $alphaMessage->send_uid);
        $this->assertSame($sharedUid, $bravoMessage->send_uid);
        $this->assertSame('For Alpha', $alphaMessage->message);
        $this->assertSame('For Bravo', $bravoMessage->message);
        $this->assertNotSame((int) $alphaMessage->business_messaging_operation_id, (int) $bravoMessage->business_messaging_operation_id);
    }

    public function test_the_same_box_and_send_uid_replayed_still_causes_only_one_provider_attempt(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sharedUid = (string) Str::uuid();

        $this->reply($workspace, $business, $box, 'Once', $sharedUid)->assertJson(['status' => 'success']);
        $this->reply($workspace, $business, $box, 'Once', $sharedUid)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The SAME conversation-scoped key both requests resolve to keeps the dispatcher\'s own idempotency intact.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->where('send_uid', $sharedUid)->count());
    }

    public function test_retries_in_two_conversations_with_the_same_send_uid_cannot_collide(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $alphaBox = $this->inboundConversation($business, '14155552671');
        $bravoBox = $this->inboundConversation($business, '14155553333');
        $sharedUid = (string) Str::uuid();

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $business, $alphaBox, 'Alpha, first try', $sharedUid)->assertJson(['status' => 'error']);
        $this->reply($workspace, $business, $bravoBox, 'Bravo, first try', $sharedUid)->assertJson(['status' => 'error']);
        $this->fakeAdapter->rejections = [];

        $this->retry($workspace, $business, $alphaBox, $sharedUid)->assertJson(['status' => 'success']);

        $this->assertCount(3, $this->fakeAdapter->sentRequests, "Alpha's own retry — never resolved against Bravo's still-failed row of the same send_uid.");
        $this->assertSame('sent', DB::table('chat_box_messages')->where('box_id', $alphaBox->id)->value('send_status'));
        $this->assertSame('failed', DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->value('send_status'), "Bravo's row is completely untouched by Alpha's retry.");

        $this->retry($workspace, $business, $bravoBox, $sharedUid)->assertJson(['status' => 'success']);
        $this->assertCount(4, $this->fakeAdapter->sentRequests);
        $this->assertSame('sent', DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->value('send_status'));
    }

    // Correction round 4, item 4 — an accepted insert that loses a race to
    // a concurrent failure write must still reconcile to sent, never a
    // second bubble. Covered in its own file,
    // tests/Feature/Conversations/AcceptedInsertRaceTest.php — it cannot
    // use RefreshDatabase (see that file's own docblock for why a genuine
    // cross-connection race needs committed rows, not a transaction this
    // suite never commits).

    // -----------------------------------------------------------------

    /**
     * An entitled, sendable Business with a managed identity and one active
     * primary number.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: \App\Models\BusinessMessagingIdentity}
     */
    private function managedTenant(string $managedNumber = '+14155550199'): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);

        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $managedNumber, true);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business->fresh(), $workspace, $identity];
    }

    /** The conversation managed inbound (#285) keys for this person. */
    private function inboundConversation(Business $business, string $person, string $businessDigits = '14155550199'): ChatBox
    {
        $box = app(ConversationHistoryWriter::class)->conversationFor($business, $businessDigits, $person);
        $box->reply_by_customer = true;
        $box->save();

        return $box->fresh();
    }

    private function reply(Workspace $workspace, Business $business, ChatBox $box, string $message, string $token): TestResponse
    {
        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);

        return $this->postJson(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->uid]), [
            'message' => $message,
            'idempotency_token' => $token,
        ]);
    }

    private function retry(Workspace $workspace, Business $business, ChatBox $box, string $sendUid): TestResponse
    {
        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);

        return $this->postJson(route('customer.workspaces.businesses.conversations.retry', [$workspace->uid, $business->uid, $box->uid]), [
            'send_uid' => $sendUid,
        ]);
    }

    private function openTimeline(Workspace $workspace, Business $business, ChatBox $box): TestResponse
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $box->uid]));
    }
}
