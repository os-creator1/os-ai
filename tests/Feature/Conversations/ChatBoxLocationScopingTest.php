<?php

namespace Tests\Feature\Conversations;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Http\Controllers\Customer\DLRController;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerBasedPricingPlan;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 06 §5/§13/§14 — Location attribution at every live
 * ChatBox creation path.
 *
 * THE RULE, once, for all four: a Location is written only where the evidence
 * proves it — the conversation's own Business, with exactly one ACTIVE
 * Location. Zero, several, archived-only, or no Business at all: NULL. No
 * Business is ever derived to obtain a Location, and a Business the customer
 * happens to own elsewhere is never evidence about this conversation.
 *
 * The four paths (contract §3), each driven through its REAL production entry
 * point — nothing below re-enacts a writer's code:
 *   1. DLRController::inboundDLR()                       — inbound
 *   2. ConversationHistoryWriter                         — recordManagedInbound()
 *                                                          and recordManagedOutbound()
 *   3. EloquentCampaignRepository::quickSend()           — two-way conversation
 *   4. EloquentCampaignRepository::campaignBuilder()     — legacy AI-Prospecting
 *
 * The only stand-in is the provider itself: quickSend() is handed a partial
 * Campaigns mock whose sendPlainSMS() reports Delivered, exactly as
 * ChatBoxSecurityTest drives it, so no network call is made and every line of
 * the repository's own two-way block still runs.
 */
class ChatBoxLocationScopingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesLocationCapacityFixtures;
    use CreatesMessagingFixtures;

    private const RECEIVING_NUMBER = '14155550100';

    // -----------------------------------------------------------------
    // §5 — the column and the shared rule
    // -----------------------------------------------------------------

    public function test_the_column_is_nullable_indexed_and_restricts_location_deletion(): void
    {
        $this->assertTrue(Schema::hasColumn('chat_boxes', 'location_id'));
        $this->assertTrue(Schema::hasIndex('chat_boxes', 'chat_boxes_location_id_index'));

        $column = DB::selectOne(
            'select IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            ['chat_boxes', 'location_id'],
        );
        $this->assertSame('YES', $column->is_nullable);

        $rule = DB::selectOne(
            'select DELETE_RULE as delete_rule from information_schema.referential_constraints
             where constraint_schema = database() and constraint_name = ?',
            ['chat_boxes_location_id_foreign'],
        );
        $this->assertSame('RESTRICT', $rule->delete_rule, 'Location attribution is audit-relevant and must never vanish with a deleted Location.');
    }

    public function test_the_message_table_gains_no_location_column(): void
    {
        $this->assertFalse(Schema::hasColumn('chat_box_messages', 'location_id'), 'A message inherits Location from its box (contract §4).');
        $this->assertFalse(Schema::hasColumn('business_messaging_numbers', 'location_id'), 'Out of scope (contract §15).');
    }

    public function test_the_shared_rule_resolves_only_a_single_active_location(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $only = $business->activeLocations()->sole();

        $this->assertSame((int) $only->id, ChatBox::singleActiveLocationIdFor((int) $business->id));

        // A second active Location makes it ambiguous — never "the primary".
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        $this->assertNull(ChatBox::singleActiveLocationIdFor((int) $business->id));

        $this->assertNull(ChatBox::singleActiveLocationIdFor(null), 'No Business, no Location.');
        $this->assertNull(ChatBox::singleActiveLocationIdFor(0));
    }

    public function test_an_archived_location_is_never_chosen(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $first = $business->activeLocations()->sole();
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);

        // Two active: ambiguous. Archive one: the survivor resolves.
        $this->assertNull(ChatBox::singleActiveLocationIdFor((int) $business->id));

        $this->archive((int) $second->id);
        $this->assertSame((int) $first->id, ChatBox::singleActiveLocationIdFor((int) $business->id));

        // And when the ONLY Location is archived, nothing is attributed.
        $this->archive((int) $first->id);
        $this->assertNull(ChatBox::singleActiveLocationIdFor((int) $business->id));
    }

    public function test_another_businesss_location_is_never_borrowed(): void
    {
        [, $mine] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        [, $theirs] = $this->locationTenant(WorkspacePlanTier::Core, 1);

        $this->assertNull(ChatBox::singleActiveLocationIdFor((int) $mine->id), 'A Business with no Location of its own resolves to nothing.');
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $theirs->id));
    }

    // -----------------------------------------------------------------
    // Site 1 — DLRController::inboundDLR()
    // -----------------------------------------------------------------

    public function test_site_one_inbound_attributes_a_single_active_location(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();
        [$server, $number] = $this->receivingNumber($business, $business);

        DLRController::inboundDLR('14155554001', 'hello', $server, 0, $number->number);

        $box = ChatBox::query()->sole();
        $this->assertSame((int) $business->id, (int) $box->business_id);
        $this->assertSame((int) $location->id, (int) $box->location_id);
        $this->assertSame(1, ChatBoxMessage::query()->where('box_id', $box->id)->count(), 'The inbound message itself is still recorded.');
    }

    public function test_site_one_inbound_leaves_a_multi_location_business_null(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        [$server, $number] = $this->receivingNumber($business, $business);

        DLRController::inboundDLR('14155554002', 'hello', $server, 0, $number->number);

        $box = ChatBox::query()->sole();
        $this->assertSame((int) $business->id, (int) $box->business_id, 'The Business is still proven...');
        $this->assertNull($box->location_id, '...but several active Locations are never resolved by picking one.');
    }

    public function test_site_one_inbound_leaves_a_business_without_locations_null(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        [$server, $number] = $this->receivingNumber($business, $business);

        DLRController::inboundDLR('14155554003', 'hello', $server, 0, $number->number);

        $box = ChatBox::query()->sole();
        $this->assertSame((int) $business->id, (int) $box->business_id);
        $this->assertNull($box->location_id);
    }

    /**
     * The receiving number carries a Business that is not this customer's, so
     * inboundDLR() leaves business_id NULL — and Location follows it, even
     * though that foreign Business has exactly one active Location.
     */
    public function test_site_one_inbound_with_an_unproven_business_writes_neither(): void
    {
        [, $mine] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        [, $foreign] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $foreign->id), 'Precondition: the foreign Business is resolvable in isolation.');

        [$server, $number] = $this->receivingNumber($mine, $foreign);

        DLRController::inboundDLR('14155554004', 'hello', $server, 0, $number->number);

        $box = ChatBox::query()->sole();
        $this->assertSame((int) $mine->customer_id, (int) $box->user_id);
        $this->assertNull($box->business_id);
        $this->assertNull($box->location_id, 'No proven Business means no Location.');
    }

    public function test_site_one_a_replayed_inbound_keeps_the_location_its_thread_was_opened_with(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $first = $business->activeLocations()->sole();
        [$server, $number] = $this->receivingNumber($business, $business);

        DLRController::inboundDLR('14155554005', 'first', $server, 0, $number->number);
        $this->assertSame((int) $first->id, (int) ChatBox::query()->sole()->location_id);

        // A second active Location appears; the live thread must not re-file.
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        DLRController::inboundDLR('14155554005', 'second', $server, 0, $number->number);

        $box = ChatBox::query()->sole();
        $this->assertSame((int) $first->id, (int) $box->location_id);
        $this->assertSame(2, ChatBoxMessage::query()->where('box_id', $box->id)->count(), 'Still one thread — Location is not part of conversation identity.');
    }

    // -----------------------------------------------------------------
    // Site 2 — ConversationHistoryWriter, both persistence paths
    // -----------------------------------------------------------------

    public function test_site_two_prepares_the_location_before_either_save(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();

        $prepared = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550111', '14155550112');

        $this->assertFalse($prepared->exists, 'conversationFor() prepares, it does not persist (contract §3).');
        $this->assertSame((int) $location->id, (int) $prepared->location_id);
    }

    public function test_site_two_persists_the_location_through_managed_inbound(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();

        app(ConversationHistoryWriter::class)->recordManagedInbound($business, '14155550121', '14155550122', 'hello', [], 'plain');

        $saved = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame((int) $location->id, (int) $saved->location_id);
        $this->assertSame(1, ChatBoxMessage::query()->where('box_id', $saved->id)->where('direction', 'incoming')->count());
    }

    public function test_site_two_persists_the_location_through_managed_outbound(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $location = $business->activeLocations()->sole();

        $message = $this->recordManagedOutbound($business, '14155552671');

        $this->assertNotNull($message, 'The accepted operation is recorded into the conversation.');
        $saved = ChatBox::query()->whereKey($message->box_id)->sole();
        $this->assertSame((int) $business->id, (int) $saved->business_id);
        $this->assertSame((int) $location->id, (int) $saved->location_id, 'The Location conversationFor() prepared survives recordManagedOutbound()\'s own save.');
        $this->assertSame('outgoing', $message->direction);
    }

    public function test_site_two_managed_outbound_leaves_a_multi_location_business_null(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);

        $message = $this->recordManagedOutbound($business, '14155552672');

        $this->assertNotNull($message);
        $this->assertNull(ChatBox::query()->whereKey($message->box_id)->sole()->location_id);
    }

    public function test_site_two_uses_an_explicitly_supplied_location_instead_of_the_fallback(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $second = $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);

        // Two active Locations: the fallback alone would resolve to NULL.
        $this->assertNull(ChatBox::singleActiveLocationIdFor((int) $business->id));

        $prepared = app(ConversationHistoryWriter::class)
            ->conversationFor($business, '14155550141', '14155550142', $second);

        $this->assertSame((int) $second->id, (int) $prepared->location_id, 'A caller that knows the sending Location beats the fallback.');
    }

    public function test_site_two_ignores_a_supplied_location_of_another_business(): void
    {
        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        [, $other] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $mine = $business->activeLocations()->sole();
        $theirs = $other->activeLocations()->sole();

        $prepared = app(ConversationHistoryWriter::class)
            ->conversationFor($business, '14155550151', '14155550152', $theirs);

        $this->assertSame((int) $mine->id, (int) $prepared->location_id, 'A foreign Location is ignored, and the fallback still applies.');
        $this->assertNotSame((int) $theirs->id, (int) $prepared->location_id);
    }

    public function test_site_two_ignores_a_supplied_archived_location_of_the_same_business(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $active = $business->activeLocations()->sole();
        $retired = $this->locations()->createLocation($business, $this->locationAttributes('Retired'), (int) $customer->user_id);
        $this->archive((int) $retired->id);

        $prepared = app(ConversationHistoryWriter::class)
            ->conversationFor($business, '14155550155', '14155550156', $retired->fresh());

        $this->assertSame((int) $active->id, (int) $prepared->location_id, 'An archived Location is never chosen, even when a caller names it.');
    }

    public function test_site_two_leaves_an_existing_conversations_location_alone(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $first = $business->activeLocations()->sole();

        app(ConversationHistoryWriter::class)->recordManagedInbound($business, '14155550161', '14155550162', 'first', [], 'plain');
        $opened = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame((int) $first->id, (int) $opened->location_id);

        // A second active Location appears; the live thread must not re-file.
        $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id);
        app(ConversationHistoryWriter::class)->recordManagedInbound($business, '14155550161', '14155550162', 'second', [], 'plain');

        $this->assertSame((int) $first->id, (int) ChatBox::query()->whereKey($opened->id)->sole()->location_id);
        $this->assertSame(1, ChatBox::query()->where('business_id', $business->id)->count(), 'Still one thread — Location is not part of conversation identity.');
    }

    // -----------------------------------------------------------------
    // Site 3 — quickSend()'s two-way conversation
    // -----------------------------------------------------------------

    public function test_site_three_quicksend_with_business_id_attributes_a_single_active_location(): void
    {
        $fx = $this->sendableLocationBusiness(1);
        $location = $fx['business']->activeLocations()->sole();

        $response = $this->quickSendTwoWay($fx, ['business_id' => $fx['business']->id], '4155557001');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));
        $box = ChatBox::query()->sole();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame((int) $location->id, (int) $box->location_id);
        $this->assertSame(1, ChatBoxMessage::query()->where('box_id', $box->id)->where('direction', 'outgoing')->count());
    }

    public function test_site_three_quicksend_with_conversation_business_id_attributes_a_single_active_location(): void
    {
        $fx = $this->sendableLocationBusiness(1);
        $location = $fx['business']->activeLocations()->sole();

        $response = $this->quickSendTwoWay($fx, ['conversation_business_id' => $fx['business']->id], '4155557002');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));
        $box = ChatBox::query()->sole();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame((int) $location->id, (int) $box->location_id);
    }

    public function test_site_three_quicksend_leaves_several_active_locations_null(): void
    {
        $fx = $this->sendableLocationBusiness(2);

        $response = $this->quickSendTwoWay($fx, ['business_id' => $fx['business']->id], '4155557003');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));
        $box = ChatBox::query()->sole();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertNull($box->location_id, 'Several active Locations: ambiguous, never "the primary".');
    }

    public function test_site_three_quicksend_leaves_a_business_without_locations_null(): void
    {
        $fx = $this->sendableLocationBusiness(0);

        $response = $this->quickSendTwoWay($fx, ['business_id' => $fx['business']->id], '4155557004');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));
        $box = ChatBox::query()->sole();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertNull($box->location_id);
    }

    public function test_site_three_quicksend_without_a_business_writes_neither(): void
    {
        $fx = $this->sendableLocationBusiness(1);
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $fx['business']->id), 'Precondition: the sender DOES own a resolvable Business.');

        // A caller that supplies no Business (and no conversation_business_id)
        // keys on business_id IS NULL; nothing is derived from the actor.
        $response = $this->quickSendTwoWay($fx, [], '4155557005');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));
        $box = ChatBox::query()->sole();
        $this->assertNull($box->business_id);
        $this->assertNull($box->location_id, 'The Business the sender owns is not evidence about a send that named none.');
    }

    // -----------------------------------------------------------------
    // Site 4 — legacy Agency AI-Prospecting campaignBuilder()
    // -----------------------------------------------------------------

    public function test_site_four_legacy_rows_stay_unattributed_for_a_single_business_user(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $business->id), 'Precondition: this user HAS a resolvable Business and Location.');

        $this->assertLegacyProspectingRowsStayUnattributed($customer, ['14155552701', '14155552702']);
    }

    public function test_site_four_legacy_rows_stay_unattributed_for_a_multi_business_user(): void
    {
        // Contract 13: a customer who owns several Businesses owns several
        // Workspaces, one Business each. What matters here is unchanged —
        // the acting user has more than one resolvable Business.
        [$customer, $first] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        $second = $this->createIndependentWorkspaceBusiness($customer, 'Second Business', 'Second Business Workspace')['business'];
        $this->locations()->createLocation($second, $this->locationAttributes('Second HQ'), (int) $customer->user_id);

        // Both Businesses are individually resolvable — the strongest
        // temptation to guess, and still no evidence about these rows.
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $first->id));
        $this->assertNotNull(ChatBox::singleActiveLocationIdFor((int) $second->id));

        $this->assertLegacyProspectingRowsStayUnattributed($customer, ['14155552711', '14155552712']);
    }

    public function test_site_four_legacy_rows_ignore_a_primary_business(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);
        DB::table('businesses')->where('id', $business->id)->update(['is_primary' => true]);

        $this->assertLegacyProspectingRowsStayUnattributed($customer, ['14155552721']);
    }

    public function test_site_four_writes_the_null_location_explicitly(): void
    {
        $source = file_get_contents(base_path('app/Repositories/Eloquent/EloquentCampaignRepository.php'));

        $this->assertMatchesRegularExpression(
            "/insertGetId\(\[\s*\n\s*'uid'\s*=>[^\n]*\n\s*'user_id'\s*=>[^\n]*\n\s*'location_id'\s*=>\s*null,/",
            $source,
            'Contract §5 site 4: the NULL is stated at the write site, not implied by omission.',
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function archive(int $locationId): void
    {
        DB::table('business_locations')->where('id', $locationId)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
    }

    /**
     * A legacy receiving number owned by $owner's customer and carrying
     * $numberBusiness — the one fact inboundDLR() proves a Business from.
     *
     * @return array{0: SendingServer, 1: PhoneNumbers}
     */
    private function receivingNumber(Business $owner, Business $numberBusiness): array
    {
        Http::fake();

        $user = User::query()->findOrFail($owner->customer_id);

        $server = SendingServer::create([
            'name' => 'Contract 06 inbound ' . uniqid(),
            'user_id' => $user->id,
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'two_way' => true,
            'plain' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        $number = PhoneNumbers::create([
            'user_id' => $user->id,
            'business_id' => $numberBusiness->id,
            'number' => self::RECEIVING_NUMBER,
            'status' => 'assigned',
            'capabilities' => json_encode(['sms']),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'validity_date' => now()->addMonth(),
        ]);

        return [$server, $number->fresh()];
    }

    /**
     * The real ConversationHistoryWriter::recordManagedOutbound(), with the
     * durable Accepted operation ManagedMessageDispatcher::finalize() would
     * already have written, and the Business's primary managed number.
     */
    private function recordManagedOutbound(Business $business, string $contactNumber): ?ChatBoxMessage
    {
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155550199', true);

        $operationKey = 'contract06:' . Str::uuid();

        DB::table(ManagedMessageDispatcher::TABLE)->insert([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed',
            'provider' => 'telnyx',
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => $operationKey,
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_contract06_' . uniqid(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(ConversationHistoryWriter::class)->recordManagedOutbound(
            $business,
            $contactNumber,
            'Are you open?',
            [],
            'plain',
            $operationKey,
            ConversationHistoryWriter::SOURCE_CONVERSATIONS,
        );
    }

    /**
     * A Business with N active Locations that quickSend() can genuinely send
     * from: an assigned two-way server and receiving number, a subscription
     * and pricing — the same shape ChatBoxSecurityTest::sendableBusiness()
     * builds, on a Location-aware tenant.
     *
     * @return array{business: Business, owner: User, server: SendingServer, number: PhoneNumbers}
     */
    private function sendableLocationBusiness(int $activeLocations): array
    {
        Http::fake();

        [, $business] = $this->locationTenant(WorkspacePlanTier::Core, 1);

        if ($activeLocations === 0) {
            $this->archive((int) $business->activeLocations()->sole()->id);
        }

        for ($i = 2; $i <= $activeLocations; $i++) {
            $this->locations()->createLocation($business, $this->locationAttributes("Branch {$i}"), (int) $business->customer_id);
        }

        $this->assertSame($activeLocations, $this->activeCount($business), 'Precondition: the fixture has exactly the active Locations the test names.');

        $owner = User::query()->findOrFail($business->customer_id);
        $owner->forceFill(['sms_unit' => '-1'])->save();

        [$country, $plan] = $this->planAndSubscription((int) $owner->id);

        $server = SendingServer::create([
            'name' => 'Contract 06 Twilio ' . uniqid(),
            'user_id' => $owner->id,
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'two_way' => true,
            'plain' => true,
            'mms' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        CustomerBasedPricingPlan::create([
            'user_id' => $owner->id,
            'country_id' => $country->id,
            'plan_id' => $plan->id,
            'sending_server' => $server->id,
            'options' => json_encode([
                'plain_sms' => 0.05, 'voice_sms' => 0.10, 'mms_sms' => 0.10,
                'whatsapp_sms' => 0.10, 'viber_sms' => 0.10, 'otp_sms' => 0.10,
            ]),
            'status' => true,
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'sending_server' => $server->id,
            'status' => true,
        ]);

        $number = PhoneNumbers::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'number' => self::RECEIVING_NUMBER,
            'status' => 'assigned',
            'capabilities' => json_encode(['sms', 'mms']),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'validity_date' => now()->addMonth(),
        ]);

        return [
            'business' => $business->fresh(),
            'owner' => $owner->fresh(),
            'server' => $server,
            'number' => $number->fresh(),
        ];
    }

    /**
     * The real EloquentCampaignRepository::quickSend() two-way path:
     * originator phone_number, plain SMS, a two-way server, and a provider
     * that reports Delivered.
     *
     * @param  array<string, int>  $businessKeys
     */
    private function quickSendTwoWay(array $fx, array $businessKeys, string $recipient): JsonResponse
    {
        $provider = \Mockery::mock(Campaigns::class)->makePartial();
        $provider->shouldReceive('sendPlainSMS')->andReturn((object) [
            'id' => 1, 'uid' => (string) Str::uuid(), 'status' => 'Delivered', 'customer_status' => 'Delivered',
            'cost' => 0, 'sms_count' => 1, 'media_url' => null,
        ]);

        return app(EloquentCampaignRepository::class)->quickSend($provider, [
            'user' => $fx['owner'],
            'sender_id' => $fx['number']->number,
            'phone_number' => $fx['number']->number,
            'originator' => 'phone_number',
            'sms_type' => 'plain',
            'message' => 'Contract 06 two-way send',
            'recipient' => $recipient,
            'country_code' => '1',
            'region_code' => 'US',
            'sending_server' => $fx['server']->id,
        ] + $businessKeys);
    }

    /**
     * Drives the real campaignBuilder() down its legacy no-Business branch and
     * proves every AI-Prospecting row it opens stays unattributed, while the
     * branch's own behaviour — one Stage-1 row per subscribed contact, mapped
     * to the campaign — is unchanged.
     *
     * @param  list<string>  $phones
     */
    private function assertLegacyProspectingRowsStayUnattributed(Customer $customer, array $phones): void
    {
        $this->actingAs($customer->user);
        $plan = $this->planAndSubscription((int) $customer->user_id)[1];
        $customer->user->forceFill(['sms_unit' => 1000])->save();

        $group = ContactGroups::create(['customer_id' => $customer->user_id, 'name' => 'Prospects ' . uniqid(), 'status' => true]);

        foreach ($phones as $phone) {
            Contacts::create(['customer_id' => $customer->user_id, 'group_id' => $group->id, 'phone' => $phone, 'status' => 'subscribe']);
        }

        Senderid::create(['user_id' => $customer->user_id, 'sender_id' => 'LEGACYSENDER', 'status' => 'active']);

        $locationQueries = [];
        DB::listen(function ($query) use (&$locationQueries): void {
            if (str_contains($query->sql, '`business_locations`')) {
                $locationQueries[] = $query->sql;
            }
        });

        // No `business_id` in the input: $outreachBusinessId === null, the
        // legacy Agency AI-Prospecting branch.
        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Legacy Prospecting ' . uniqid(),
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['LEGACYSENDER'],
            'plan_id' => $plan->id,
        ]);

        $this->assertSame('success', $result->getData()->status, (string) ($result->getData()->message ?? ''));

        $boxes = DB::table('chat_boxes')->orderBy('id')->get();
        $this->assertCount(count($phones), $boxes, 'The row is still created — one per subscribed contact.');

        foreach ($boxes as $box) {
            $this->assertSame((int) $customer->user_id, (int) $box->user_id);
            $this->assertSame(1, (int) $box->ai_stage, 'AI-stage behaviour is unchanged.');
            $this->assertSame('LEGACYSENDER', $box->from);
            $this->assertTrue(Str::isUuid((string) $box->uid));
            $this->assertNull($box->business_id, 'The legacy branch writes no Business.');
            $this->assertNull($box->location_id, 'No Business, so no Location — never guessed from a Business the user owns elsewhere.');
        }

        $campaign = Campaigns::query()->latest('id')->firstOrFail();
        $mapped = array_map('intval', DB::table('ai_box_campaign_map')->where('campaign_id', $campaign->id)->pluck('box_id')->all());
        $expected = array_map('intval', $boxes->pluck('id')->all());
        sort($mapped);
        sort($expected);
        $this->assertSame($expected, $mapped, 'The campaign mapping is unchanged: every row, and only these rows.');

        $this->assertSame([], $locationQueries, 'No BusinessLocation lookup is made to guess a Location for a Business-less row.');
    }

    /**
     * @return array{0: Country, 1: Plan}
     */
    private function planAndSubscription(int $userId): array
    {
        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::query()->where('code', 'USD')->first()
            ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '${PRICE}', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Contract 06 Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
            'custom_order' => 0,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return [$country, $plan];
    }
}
