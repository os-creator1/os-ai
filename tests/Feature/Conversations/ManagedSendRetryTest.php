<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Models\Business;
use App\Models\ChatBox;
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

        // The claim a first, still-in-flight retry click already made.
        DB::table('chat_box_messages')->where('id', $message->id)->update(['send_status' => 'sending']);

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
