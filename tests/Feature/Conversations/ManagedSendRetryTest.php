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
