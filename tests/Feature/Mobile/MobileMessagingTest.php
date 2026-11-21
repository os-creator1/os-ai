<?php

namespace Tests\Feature\Mobile;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Usage\UsageWalletManager;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessMessagingNumber;
use App\Models\ChatBox;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UsageMeterRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Phase 2A: SMS reply / retry / mark-read over the mobile API, through the SAME ConversationSender the desktop uses.
 * The messaging provider is the in-memory FakeMessagingAdapter and every HTTP call is faked: no SMS can leave the test.
 */
class MobileMessagingTest extends TestCase
{
    use CreatesAutomationFixtures, CreatesCustomerContextFixtures {
        CreatesAutomationFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesCustomerContextFixtures;
        CreatesAutomationFixtures::platformAdminId insteadof CreatesCustomerContextFixtures;
    }
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const PERSON = '14155552671';
    private const PASSWORD = 'correct-horse-battery';
    private const RATE_MICRO = 10_000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->bindFakeAdapter();
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /** @return array{0: Customer, 1: Business, 2: Workspace, 3: object} */
    private function managedTenant(string $number = '+14155550199'): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $number, true);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business->fresh(), $workspace, $identity];
    }

    private function conversation(Business $business, string $person = self::PERSON): ChatBox
    {
        $box = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550199', $person);
        $box->reply_by_customer = true;
        $box->save();

        return $box->fresh();
    }

    private function tokenFor(Customer $customer, ?array $permissions = null): string
    {
        $customer->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        if ($permissions !== null) {
            $customer->forceFill(['permissions' => json_encode($permissions)])->save();
        }

        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $customer->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => 'install-' . Str::random(8), 'platform' => 'android'],
        ])->assertOk()->json('data.token');
    }

    private function path(Workspace $w, Business $b, string $tail): string
    {
        return "/api/mobile/v1/workspaces/{$w->uid}/businesses/{$b->uid}/{$tail}";
    }

    private function mobile(string $token, string $method, string $url, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])->json($method, $url, $body);
    }

    private function reply(string $token, Workspace $w, Business $b, ChatBox $box, string $text, ?string $key = null)
    {
        return $this->mobile($token, 'POST', $this->path($w, $b, "conversations/{$box->uid}/reply"), ['body' => $text, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function outbound(): \Illuminate\Support\Collection
    {
        return DB::table('chat_box_messages')->where('direction', 'outgoing')->get();
    }

    private function fundAndPrice(Business $business, int $micro): void
    {
        Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $manager = app(UsageWalletManager::class);

        if (! DB::table('business_usage_wallets')->where('business_id', $business->id)->exists()) {
            $manager->initializeWalletForNewBusiness($business->id);
        }
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $micro, 'refundable_paid_available_micro' => $micro]);

        if (! DB::table('usage_meters')->where('meter_key', \App\Library\Messaging\MessageMeters::SMS_OUTBOUND)->exists()) {
            $currencyId = Currency::query()->first()->id;
            $actorId = User::create(['first_name' => 'T', 'last_name' => 'A', 'email' => 'a' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin'])->id;
            app(UsageMeterRepository::class)->create([
                'meter_key' => \App\Library\Messaging\MessageMeters::SMS_OUTBOUND, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null,
                'currency_id' => $currencyId, 'description' => 'Managed messaging transport (per segment).', 'updated_by_user_id' => $actorId,
            ]);
            $manager->setActiveRate(\App\Library\Messaging\MessageMeters::SMS_OUTBOUND, (string) self::RATE_MICRO, '5000', 'per segment', $currencyId, $actorId, 'Fixture.');
            $manager->activateMetering(\App\Library\Messaging\MessageMeters::SMS_OUTBOUND, $actorId, 'Fixture.');
        }
    }

    // ---- reply: success, idempotency -----------------------------------------------------------------------------

    public function test_a_reply_is_sent_through_the_managed_provider_and_recorded_with_its_delivery_state(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);
        $key = (string) Str::uuid();

        $res = $this->reply($token, $workspace, $business, $box, 'Are you free Saturday?', $key)->assertOk();

        $res->assertJsonPath('data.message.send_uid', $key)->assertJsonPath('data.message.status', 'sent');
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $row = $this->outbound()->sole();
        $this->assertSame('Are you free Saturday?', $row->message);
        $this->assertSame($key, $row->send_uid);

        $thread = $this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))->assertOk();
        $last = collect($thread->json('data.messages'))->last();
        $this->assertSame('outbound', $last['direction']);
        $this->assertSame('sent', $last['status']);
        $this->assertFalse($last['retryable']);
    }

    public function test_the_same_idempotency_key_never_sends_or_bills_twice(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $this->fundAndPrice($business, 1_000_000);
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);
        $key = (string) Str::uuid();

        $a = $this->reply($token, $workspace, $business, $box, 'Once only', $key)->assertOk();
        $b = $this->reply($token, $workspace, $business, $box, 'Once only', $key)->assertOk();
        $c = $this->reply($token, $workspace, $business, $box, 'Changed text, same key', $key)->assertOk();

        $this->assertSame($a->json('data.message.send_uid'), $b->json('data.message.send_uid'));
        $this->assertSame($key, $c->json('data.message.send_uid'));
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'One provider call for three submissions.');
        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame((string) (1_000_000 - self::RATE_MICRO), (string) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_validation_rejects_empty_bodies_and_bad_keys_before_any_send(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);
        $url = $this->path($workspace, $business, "conversations/{$box->uid}/reply");

        $this->mobile($token, 'POST', $url, ['body' => '   ', 'idempotency_key' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->mobile($token, 'POST', $url, ['body' => 'hi', 'idempotency_key' => 'not-a-uuid'])->assertStatus(422);
        $this->mobile($token, 'POST', $url, ['body' => 'hi'])->assertStatus(422);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_mobile_rejects_legacy_non_managed_senders_before_any_provider_attempt(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant();
        $owner->forceFill(['permissions' => json_encode(Customer::customerPermissions())])->save();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);

        // Without a managed identity or an explicitly selected BYO channel,
        // legacy SMS cannot promise replay-safe idempotency after a timeout.
        $key = (string) Str::uuid();
        $this->reply($token, $workspace, $business, $box, 'Do not send twice', $key)
            ->assertStatus(422)->assertJsonPath('error.code', 'messaging_unavailable');
        $this->reply($token, $workspace, $business, $box, 'Do not send twice', $key)
            ->assertStatus(422)->assertJsonPath('error.code', 'messaging_unavailable');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
    }

    public function test_opening_mobile_thread_reconciles_a_stale_sending_claim_without_a_provider_call(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);

        $messageId = DB::table('chat_box_messages')->insertGetId([
            'box_id' => $box->id,
            'message' => 'Unconfirmed attempt',
            'direction' => 'outgoing',
            'send_by' => 'from',
            'sms_type' => 'plain',
            'send_uid' => (string) Str::uuid(),
            'send_status' => 'sending',
            'send_claimed_at' => now()->subMinutes(5),
            'retry_count' => 1,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $messages = $this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))
            ->assertOk()->json('data.messages');
        $m = collect($messages)->firstWhere('id', $messageId);

        $this->assertSame('failed', $m['status']);
        $this->assertTrue($m['retryable']);
        $this->assertSame('failed', DB::table('chat_box_messages')->where('id', $messageId)->value('send_status'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // ---- opt-out / STOP / DNC -----------------------------------------------------------------------------------

    public function test_a_stopped_number_on_the_blacklist_is_refused_with_no_provider_call(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        Blacklists::create(['uid' => (string) Str::uuid(), 'user_id' => $business->customer_id, 'business_id' => $business->id, 'number' => self::PERSON, 'reason' => 'STOP']);

        $res = $this->reply($this->tokenFor($owner), $workspace, $business, $box, 'Hello?')->assertStatus(422);

        $this->assertStringContainsStringIgnoringCase('blacklist', $res->json('error.message'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_an_unsubscribed_contact_is_refused_even_without_a_blacklist_row_and_leaves_no_bubble(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $groupId = DB::table('contact_groups')->insertGetId(['uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'g', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contacts')->insert(['uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => $groupId, 'phone' => (int) self::PERSON, 'status' => 'unsubscribe', 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->reply($this->tokenFor($owner), $workspace, $business, $box, 'Sale today!')->assertStatus(422);

        $res->assertJsonPath('error.code', 'opted_out')->assertJsonPath('error.retryable', false);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, $this->outbound()->count(), 'Nothing was attempted, so there is no failed bubble to retry.');
    }

    // ---- sender / balance ---------------------------------------------------------------------------------------

    public function test_a_business_without_a_usable_sender_is_refused_safely_and_retryable_once_fixed(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        BusinessMessagingNumber::query()->delete();
        $token = $this->tokenFor($owner);

        $res = $this->reply($token, $workspace, $business, $box, 'Any slots?')->assertStatus(422);

        $this->assertNotSame('send_refused', $res->json('error.code'));
        $this->assertTrue($res->json('error.retryable'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertStringNotContainsString('telnyx', strtolower($res->getContent()), 'No provider detail leaks.');
    }

    public function test_insufficient_balance_is_refused_before_the_provider_and_the_retry_after_funding_sends_once(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $this->fundAndPrice($business, 0);
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);

        $res = $this->reply($token, $workspace, $business, $box, 'Quote attached')->assertStatus(422);
        $res->assertJsonPath('error.code', 'insufficient_balance')->assertJsonPath('error.retryable', true);
        $sendUid = $res->json('error.send_uid');
        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'No provider contact while unfunded.');

        $failed = collect($this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))->json('data.messages'))->last();
        $this->assertSame('failed', $failed['status']);
        $this->assertTrue($failed['retryable']);

        $this->fundAndPrice($business, 1_000_000);
        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/retry"), ['send_uid' => $sendUid])
            ->assertOk()->assertJsonPath('data.message.status', 'sent');

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(1, $this->outbound()->count(), 'The same bubble, not a second one.');
        $this->assertSame((string) (1_000_000 - self::RATE_MICRO), (string) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'), 'Billed once.');
    }

    // ---- authorization ------------------------------------------------------------------------------------------

    public function test_another_businesss_conversation_cannot_be_replied_to_retried_or_read(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->managedTenant('+14155550199');
        [, $businessB, $workspaceB] = $this->managedTenant('+14155550188');
        $boxB = $this->conversation($businessB, '14155552999');
        DB::table('chat_boxes')->where('id', $boxB->id)->update(['notification' => 4]);
        $token = $this->tokenFor($ownerA);

        foreach ([[$workspaceA, $businessA], [$workspaceB, $businessB], [$workspaceA, $businessB]] as [$w, $b]) {
            $this->reply($token, $w, $b, $boxB, 'Not yours')->assertStatus(404);
            $this->mobile($token, 'POST', $this->path($w, $b, "conversations/{$boxB->uid}/retry"), ['send_uid' => (string) Str::uuid()])->assertStatus(404);
            $this->mobile($token, 'POST', $this->path($w, $b, "conversations/{$boxB->uid}/read"))->assertStatus(404);
        }

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(4, (int) DB::table('chat_boxes')->where('id', $boxB->id)->value('notification'), 'The other Business keeps its unread count.');
    }

    public function test_a_member_without_the_conversations_permission_cannot_send(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);

        $this->reply($this->tokenFor($staff, ['access_backend']), $workspace, $business, $box, 'Sneaky')->assertStatus(404);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_a_location_limited_member_cannot_reply_to_a_conversation_at_a_location_they_were_not_granted(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $granted = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $other = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $box = $this->conversation($business);
        DB::table('chat_boxes')->where('id', $box->id)->update(['location_id' => $other->id]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);
        $token = $this->tokenFor($staff, ['access_backend', 'chat_box']);

        $this->reply($token, $workspace, $business, $box, 'Wrong location')->assertStatus(404);
        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/read"))->assertStatus(404);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        // The same member CAN reach a conversation at the Location they were granted.
        DB::table('chat_boxes')->where('id', $box->id)->update(['location_id' => $granted->id]);
        $this->reply($token, $workspace, $business, $box, 'Right location')->assertOk();
    }

    public function test_a_locked_workspace_cannot_send_or_mark_read_and_nothing_reaches_the_provider(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        DB::table('chat_boxes')->where('id', $box->id)->update(['notification' => 2]);
        $token = $this->tokenFor($owner);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Lock fixture.');

        // Same contract as the read endpoints (MobileApiTest): a locked account is simply closed, never an oracle.
        $this->reply($token, $workspace, $business, $box, 'Still there?')->assertStatus(404);
        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/read"))->assertStatus(404);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(2, (int) DB::table('chat_boxes')->where('id', $box->id)->value('notification'));
    }

    public function test_a_managing_agency_cannot_send_into_a_clients_conversations_from_the_mobile_api(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $clientBusiness = $fixture['clientBusiness'];
        $this->sendableChannel($clientBusiness);
        $identity = $this->attachIdentity($clientBusiness);
        $this->attachNumber($identity, '+14155550199', true);
        $box = $this->conversation($clientBusiness->fresh());
        $agency = $fixture['agencyOwner'];
        $agency->permissions = Customer::customerPermissions();
        $agency->save();

        $this->reply($this->tokenFor($agency), $fixture['clientWorkspace'], $clientBusiness, $box, 'Agency here')->assertStatus(404);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, $this->outbound()->count());
    }

    // ---- delivery failure + desktop consistency -----------------------------------------------------------------

    public function test_a_carrier_delivery_failure_shows_on_mobile_and_its_retry_is_one_new_attempt(): void
    {
        [$owner, $business, $workspace, $identity] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);
        $key = (string) Str::uuid();
        $this->reply($token, $workspace, $business, $box, 'On my way!', $key)->assertOk();

        $row = $this->outbound()->sole();
        $operation = DB::table('business_messaging_operations')->where('id', $row->business_messaging_operation_id)->sole();
        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus, messagingProfileId: $identity->messaging_profile_id, destinationNumber: null, fromNumber: null,
            body: null, mediaUrls: [], providerMessageId: $operation->provider_message_id, deliveryStatus: 'failed', occurredAt: CarbonImmutable::now(),
        ));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();

        $m = collect($this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))->json('data.messages'))->last();
        $this->assertSame('delivery_failed', $m['status']);
        $this->assertTrue($m['retryable']);
        $this->assertNotEmpty($m['status_message']);

        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/retry"), ['send_uid' => $key])->assertOk();
        $this->assertCount(2, $this->fakeAdapter->sentRequests);
        $this->assertSame(1, $this->outbound()->count());
    }

    public function test_mobile_and_desktop_see_and_send_the_same_messages(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);

        $this->reply($token, $workspace, $business, $box, 'From the phone')->assertOk();

        // Desktop sends through the same service: one more provider call, one more row.
        $this->actingAs($owner->user)->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->postJson(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->uid]), [
            'message' => 'From the desktop', 'idempotency_token' => (string) Str::uuid(),
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests);
        $desktop = $this->postJson(route('customer.workspaces.businesses.conversations.messages', [$workspace->uid, $business->uid, $box->uid]))->json('data');
        $mobile = $this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))->json('data.messages');

        $this->assertEqualsCanonicalizing(array_column($desktop, 'message'), array_column($mobile, 'body'));
        $this->assertSame(['outbound', 'outbound'], array_slice(array_column($mobile, 'direction'), -2));
        $this->assertSame(['sent', 'sent'], array_slice(array_column($mobile, 'status'), -2));
    }

    // ---- unread -------------------------------------------------------------------------------------------------

    public function test_unread_is_shared_state_fetching_never_clears_it_and_marking_read_does_not_reorder_the_inbox(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        DB::table('chat_boxes')->where('id', $box->id)->update(['notification' => 3, 'updated_at' => '2026-01-01 10:00:00']);
        $token = $this->tokenFor($owner);
        $list = fn (string $filter = 'all') => $this->mobile($token, 'GET', $this->path($workspace, $business, 'conversations?filter=' . $filter));

        $this->assertTrue($list()->json('data.0.unread'));
        $this->mobile($token, 'GET', $this->path($workspace, $business, "conversations/{$box->uid}"))->assertOk();
        $this->assertSame(3, (int) DB::table('chat_boxes')->where('id', $box->id)->value('notification'), 'GET must not clear unread.');
        $this->assertCount(1, $list('unread')->json('data'));

        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/read"))->assertOk()->assertJsonPath('data.unread', false);

        $this->assertSame(0, (int) DB::table('chat_boxes')->where('id', $box->id)->value('notification'));
        $this->assertSame('2026-01-01 10:00:00', (string) DB::table('chat_boxes')->where('id', $box->id)->value('updated_at'), 'Reading must not bump the sort key.');
        $this->assertCount(0, $list('unread')->json('data'));
        $this->assertFalse($list()->json('data.0.unread'));

        // The desktop reads the very same column.
        $this->actingAs($owner->user)->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->postJson(route('customer.workspaces.businesses.conversations.notification', [$workspace->uid, $business->uid, $box->uid]))
            ->assertOk()->assertJson(['notification' => 0]);
    }

    public function test_a_new_inbound_message_makes_a_read_conversation_unread_again_for_both_clients(): void
    {
        [$owner, $business, $workspace] = $this->managedTenant();
        $box = $this->conversation($business);
        $token = $this->tokenFor($owner);
        $this->mobile($token, 'POST', $this->path($workspace, $business, "conversations/{$box->uid}/read"))->assertOk();

        DB::table('chat_boxes')->where('id', $box->id)->update(['notification' => DB::raw('notification + 1')]);

        $this->assertTrue($this->mobile($token, 'GET', $this->path($workspace, $business, 'conversations'))->json('data.0.unread'));
    }
}
