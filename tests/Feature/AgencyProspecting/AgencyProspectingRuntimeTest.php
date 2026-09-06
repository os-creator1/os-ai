<?php

namespace Tests\Feature\AgencyProspecting;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Jobs\AgencyProspectingFollowUpJob;
use App\Jobs\AgencyProspectingInitialSendJob;
use App\Jobs\AgencyProspectingRespondJob;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Library\AgencyProspecting\FakeAgencyProspectingAiClient;
use App\Library\AgencyProspecting\FakeAgencyProspectingMessageSender;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use App\Models\AppConfig;
use App\Models\Blacklists;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Agency AI Prospecting runtime pass — channel ownership, campaign/channel
 * binding, one-open-conversation invariant, initial send, inbound webhook,
 * deterministic STOP, AI responder, follow-up, Business/B1/B2 separation,
 * and phone normalization. Uses FakeAgencyProspectingMessageSender/
 * FakeAgencyProspectingAiClient exclusively — never a real provider/model
 * call.
 */
class AgencyProspectingRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgencyProspectingMessageSender $sender;

    private FakeAgencyProspectingAiClient $aiClient;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->ensureRequiredAppConfigRowsExist();

        $this->sender = new FakeAgencyProspectingMessageSender();
        $this->aiClient = new FakeAgencyProspectingAiClient();
        $this->app->instance(AgencyProspectingMessageSender::class, $this->sender);
        $this->app->instance(AgencyProspectingAiClient::class, $this->aiClient);
    }

    // -----------------------------------------------------------------
    // Channel ownership.
    // -----------------------------------------------------------------

    public function test_owner_can_connect_twilio(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Twilio']), [
            'sender_number' => '+12025551000',
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ])->assertSessionHas('flash_success');

        $channel = AgencyProspectingChannel::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($channel);
        $this->assertSame('12025551000', $channel->sender_number);
        $this->assertSame(SendingServer::TYPE_TWILIO, $channel->provider);
        $this->assertSame($owner->id, $channel->sendingServer->user_id);
    }

    public function test_owner_can_connect_telnyx(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Telnyx']), [
            'sender_number' => '+12025552000',
            'api_key' => 'key_test',
            'c1' => 'profile_test',
        ])->assertSessionHas('flash_success');

        $channel = AgencyProspectingChannel::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($channel);
        $this->assertSame(SendingServer::TYPE_TELNYX, $channel->provider);
    }

    public function test_active_admin_can_connect_a_channel(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $admin = $this->createCustomerUser();
        $this->makeMembership($workspace, $admin, WorkspaceMembershipRole::Admin, true);
        $this->authenticateAsCustomer($admin);

        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Twilio']), [
            'sender_number' => '+12025553000',
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ])->assertSessionHas('flash_success');
    }

    public function test_staff_is_denied_connecting_a_channel(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $staff = $this->createCustomerUser();
        $this->makeMembership($workspace, $staff, WorkspaceMembershipRole::Staff, true);
        $this->authenticateAsCustomer($staff);

        $this->get(route('customer.workspaces.prospecting.channels.index', $workspace->uid))->assertStatus(404);
    }

    public function test_core_workspace_is_denied_channel_access(): void
    {
        [$owner, $workspace] = $this->workspaceOnTier(WorkspacePlanTier::Core);
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.channels.index', $workspace->uid))->assertStatus(404);
    }

    public function test_inactive_workspace_is_denied_channel_access(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $workspace->update(['is_active' => false]);
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.channels.index', $workspace->uid))->assertStatus(404);
    }

    public function test_foreign_workspace_channel_is_denied(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $channelB = $this->createChannel($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->get(route('customer.workspaces.prospecting.channels.show', [$workspaceA->uid, $channelB->uid]))->assertStatus(404);
    }

    public function test_channel_credentials_are_never_rendered(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace, SendingServer::TYPE_TWILIO, ['auth_token' => 'super-secret-token']);
        $this->authenticateAsCustomer($owner);

        $response = $this->get(route('customer.workspaces.prospecting.channels.show', [$workspace->uid, $channel->uid]));
        $response->assertOk();
        $response->assertDontSee('super-secret-token');
    }

    public function test_blank_channel_credential_update_preserves_existing_value(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace, SendingServer::TYPE_TWILIO, ['account_sid' => 'AC_ORIGINAL', 'auth_token' => 'token_original']);
        $this->authenticateAsCustomer($owner);

        $this->put(route('customer.workspaces.prospecting.channels.update', [$workspace->uid, $channel->uid]), [
            'account_sid' => '',
            'auth_token' => '',
        ])->assertSessionHas('flash_success');

        $server = $channel->sendingServer->fresh();
        $this->assertSame('AC_ORIGINAL', $server->account_sid);
        $this->assertSame('token_original', $server->auth_token);
    }

    public function test_provider_allowlist_rejects_anything_else(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $response = $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, SendingServer::TYPE_PLIVO]), [
            'sender_number' => '+12025554000',
        ]);

        $response->assertSessionHas('flash_error');
        $this->assertSame(0, AgencyProspectingChannel::where('workspace_id', $workspace->id)->count());
    }

    public function test_connecting_a_channel_never_creates_a_business_customer_based_sending_server(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Twilio']), [
            'sender_number' => '+12025555000',
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ]);

        $this->assertSame(0, CustomerBasedSendingServer::count());
    }

    // -----------------------------------------------------------------
    // Campaign/channel binding.
    // -----------------------------------------------------------------

    public function test_campaign_can_select_a_same_workspace_active_channel(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channel->uid,
            'opening_message' => 'Hi {{contact_name}}!',
        ])->assertSessionHas('flash_success');

        $this->assertSame($channel->id, $campaign->fresh()->channel_id);
    }

    public function test_foreign_channel_is_rejected_from_campaign_config(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $channelB = $this->createChannel($workspaceB);
        $campaignA = $this->createCampaign($workspaceA);
        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspaceA->uid, $campaignA->uid]), [
            'channel_uid' => $channelB->uid,
        ])->assertStatus(404);

        $this->assertNull($campaignA->fresh()->channel_id);
    }

    public function test_campaign_with_disabled_channel_cannot_start(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $channel->update(['status' => AgencyProspectingChannel::STATUS_DISABLED]);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi!']);
        $prospect = $this->createProspect($workspace);
        $this->enrollDirectly($workspace, $campaign, $prospect);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_campaign_without_opener_cannot_start(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id]);
        $prospect = $this->createProspect($workspace);
        $this->enrollDirectly($workspace, $campaign, $prospect);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_campaign_remains_draft_until_explicit_start(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.store', $workspace->uid), [
            'name' => 'New Campaign',
        ]);

        $campaign = AgencyProspectCampaign::where('workspace_id', $workspace->id)->first();
        $this->assertSame('draft', $campaign->status->value);
    }

    // -----------------------------------------------------------------
    // One-open-conversation invariant.
    // -----------------------------------------------------------------

    public function test_starting_a_campaign_is_rejected_when_a_prospect_has_another_open_active_campaign(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        $campaignA = $this->createCampaign($workspace, ['name' => 'A', 'channel_id' => $channel->id, 'opening_message' => 'Hi A']);
        $this->enrollDirectly($workspace, $campaignA, $prospect);
        $this->startCampaignDirectly($workspace, $campaignA);

        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channel->id, 'opening_message' => 'Hi B']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignB->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $campaignB->fresh()->status->value);
    }

    public function test_a_terminal_prior_membership_permits_a_later_new_campaign(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        $campaignA = $this->createCampaign($workspace, ['name' => 'A', 'channel_id' => $channel->id, 'opening_message' => 'Hi A']);
        $memberA = $this->enrollDirectly($workspace, $campaignA, $prospect);
        $memberA->update(['stage' => AgencyProspectStage::Booked->value]);

        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channel->id, 'opening_message' => 'Hi B']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignB->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('active', $campaignB->fresh()->status->value);
    }

    // -----------------------------------------------------------------
    // Initial send.
    // -----------------------------------------------------------------

    public function test_start_dispatches_the_initial_send_exactly_once_per_eligible_member(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi {{company_name}}']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)->count());
        $this->assertSame(1, count($this->sender->sentMessages));
        $this->assertSame('Hi Test Prospect Co', $this->sender->sentMessages[0]['body']);
    }

    public function test_retry_of_initial_send_does_not_duplicate_after_success(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        $this->runInitialSend($member->id);
        $this->runInitialSend($member->id);

        $this->assertSame(1, count($this->sender->sentMessages));
        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)->where('status', 'sent')->count());
    }

    public function test_stopped_prospect_is_skipped_by_initial_send(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace, ['status' => 'stopped', 'stopped_at' => now()]);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaign->id, 'prospect_id' => $prospect->id, 'stage' => 99, 'enrolled_at' => now()]);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_booked_prospect_is_skipped_by_initial_send(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace, ['status' => 'booked', 'booked_at' => now()]);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaign->id, 'prospect_id' => $prospect->id, 'stage' => 6, 'enrolled_at' => now()]);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_paused_campaign_is_skipped_by_initial_send(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_provider_failure_does_not_advance_stage(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        $this->sender->nextSendSucceeds = false;
        $this->runInitialSend($member->id);

        $this->assertSame(1, $member->fresh()->stage->value);
        $this->assertSame('failed', AgencyProspectMessage::where('campaign_member_id', $member->id)->first()->status);
    }

    // -----------------------------------------------------------------
    // Webhook.
    // -----------------------------------------------------------------

    public function test_invalid_verification_token_is_rejected(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);

        $this->post(route('prospecting.webhooks.twilio', [$channel->uid, 'wrong-token']), [
            'From' => '+12025556000', 'To' => '+' . $channel->sender_number, 'Body' => 'Hello', 'MessageSid' => 'SM123',
        ])->assertStatus(404);

        $this->assertSame(0, AgencyProspectMessage::count());
    }

    public function test_duplicate_provider_message_id_is_idempotent(): void
    {
        [$workspace, $member] = $this->activeConversation();

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id,
            'direction' => 'inbound', 'provider_message_id' => 'DUPLICATE-ID', 'body' => 'Hi', 'status' => 'received', 'received_at' => now(),
        ]);

        $this->postTelnyxInbound($member, 'Hello again', 'DUPLICATE-ID');

        $this->assertSame(1, AgencyProspectMessage::where('provider_message_id', 'DUPLICATE-ID')->count());
    }

    public function test_wrong_to_number_is_rejected_with_no_attribution(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+19998887777', 'Body' => 'Hello', 'MessageSid' => 'SM-WRONG-TO',
        ])->assertOk();

        $this->assertSame(0, AgencyProspectMessage::where('provider_message_id', 'SM-WRONG-TO')->count());
    }

    public function test_unknown_from_number_does_not_guess_a_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+19998887777', 'To' => '+' . $channel->sender_number, 'Body' => 'Hello', 'MessageSid' => 'SM-UNKNOWN',
        ])->assertOk();

        $this->assertSame(0, AgencyProspectMessage::where('provider_message_id', 'SM-UNKNOWN')->count());
    }

    public function test_inbound_maps_to_the_exact_prospect_and_persists_once(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Tell me more', 'MessageSid' => 'SM-EXACT',
        ])->assertOk();

        $this->assertSame(1, AgencyProspectMessage::where('provider_message_id', 'SM-EXACT')->count());
        $inbound = AgencyProspectMessage::where('provider_message_id', 'SM-EXACT')->first();
        $this->assertSame($member->id, $inbound->campaign_member_id);
    }

    public function test_ambiguous_active_membership_sends_no_reply(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $prospect = $member->prospect;

        $secondCampaign = $this->createCampaign($workspace, ['name' => 'Second', 'channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $secondCampaign->id, 'prospect_id' => $prospect->id, 'stage' => 1, 'enrolled_at' => now()]);

        $this->postTwilioInbound($channel, [
            'From' => '+' . $prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Hello', 'MessageSid' => 'SM-AMBIGUOUS',
        ])->assertOk();

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(0, count($this->aiClient->receivedMessages));
    }

    // -----------------------------------------------------------------
    // STOP.
    // -----------------------------------------------------------------

    public function test_stop_keyword_stops_prospect_and_all_memberships(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-STOP',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(99, $member->fresh()->stage->value);
    }

    public function test_unsubscribe_variant_stops_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'please unsubscribe', 'MessageSid' => 'SM-UNSUB',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
    }

    public function test_wrong_number_stops_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'wrong number', 'MessageSid' => 'SM-WRONGNUM',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
    }

    public function test_not_interested_stops_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'not interested, thanks', 'MessageSid' => 'SM-NOTINT',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
    }

    public function test_stop_never_creates_a_business_blacklist(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-STOP-BL',
        ]);

        $this->assertSame(0, Blacklists::count());
    }

    public function test_stop_in_one_workspace_does_not_affect_the_same_phone_in_another(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $phone = $member->prospect->phone;

        [, $otherWorkspace] = $this->agencyWorkspace();
        $otherProspect = $this->createProspect($otherWorkspace, ['phone' => $phone]);

        $this->postTwilioInbound($channel, [
            'From' => '+' . $phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-STOP-ISO',
        ]);

        $this->assertSame('active', $otherProspect->fresh()->status->value);
    }

    public function test_hard_stop_never_calls_the_ai_client(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-STOP-NOAI',
        ]);

        $this->assertSame(0, count($this->aiClient->receivedMessages));
    }

    public function test_no_followup_is_scheduled_after_stop(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $member->update(['booking_link_sent_at' => now()->subHour(), 'followup_at' => now()->addHours(23)]);

        Queue::fake();

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-STOP-NOFU',
        ]);

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // AI.
    // -----------------------------------------------------------------

    public function test_valid_bounded_decision_sends_a_reply_and_advances_stage(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Sure, happy to help!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(2, $member->fresh()->stage->value);
        $this->assertSame(1, count($this->sender->sentMessages));
    }

    public function test_invalid_json_sends_nothing_and_advances_nothing(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = 'not valid json {{{';

        $this->runRespond($member->id, $workspace);

        $this->assertSame(1, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_invalid_stage_sends_nothing(): void
    {
        [$workspace, $member] = $this->activeConversation();

        // From stage 1, only 2/3/99 are allowed transitions — 5 is a
        // DTO-valid next_stage value in isolation, but not a valid
        // transition from stage 1.
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Hi', 'next_stage' => 5, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(1, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_ai_cannot_set_stage_6(): void
    {
        [$workspace, $member] = $this->activeConversation();
        // next_stage=6 is not in AgencyProspectAiDecision::ALLOWED_NEXT_STAGES at all, so the whole decision is invalid.
        $raw = '{"intent":"positive","reply":"Great, you are booked!","next_stage":6,"send_booking_link":false,"proposed_slot":null}';
        $this->aiClient->nextRawResponse = $raw;

        $this->runRespond($member->id, $workspace);

        $this->assertSame(1, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_ai_cannot_move_backwards(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 4]);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(4, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_terminal_member_never_replies(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 99]);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(0, count($this->aiClient->receivedMessages));
    }

    public function test_hard_negative_ai_decision_stops_the_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'hard_negative', 'reply' => 'Understood.', 'next_stage' => null, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(99, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages), 'A hard-negative decision must never send further sales copy.');
    }

    public function test_configured_agent_setup_context_is_passed_to_the_ai_client(): void
    {
        [$workspace, $member] = $this->activeConversation();
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['agency_name' => 'Acme Growth Partners', 'niche' => 'dental clinics']);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'other', 'reply' => 'Hi', 'next_stage' => null, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $systemMessage = $this->aiClient->receivedMessages[0][0]['content'];
        $this->assertStringContainsString('Acme Growth Partners', $systemMessage);
        $this->assertStringContainsString('dental clinics', $systemMessage);
    }

    public function test_no_hardcoded_agency_specific_content_in_responder_source(): void
    {
        $source = file_get_contents(base_path('app/Jobs/AgencyProspectingRespondJob.php'));

        foreach (['jazmin', 'photobooth', 'photo booth'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $source);
        }
    }

    public function test_booking_url_is_server_controlled_not_ai_supplied(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://real-booking.example.com/agency']);
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'booking', 'reply' => 'Here you go: https://fake-ai-invented-url.example.com',
            'next_stage' => 4, 'send_booking_link' => true, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $sentBody = $this->sender->sentMessages[0]['body'];
        $this->assertStringContainsString('https://real-booking.example.com/agency', $sentBody);
    }

    // -----------------------------------------------------------------
    // Follow-up.
    // -----------------------------------------------------------------

    public function test_followup_is_scheduled_after_booking_link_send(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://book.example.com', 'follow_up_delay_hours' => 24]);
        Queue::fake();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'booking', 'reply' => 'Here is the link:', 'next_stage' => 4, 'send_booking_link' => true, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        Queue::assertPushed(AgencyProspectingFollowUpJob::class);
        $this->assertNotNull($member->fresh()->booking_link_sent_at);
        $this->assertNotNull($member->fresh()->followup_at);
    }

    public function test_followup_sends_at_most_once(): void
    {
        [, $member] = $this->bookingLinkSentConversation();

        $this->runFollowUp($member->id);
        $this->runFollowUp($member->id);

        $this->assertSame(1, count($this->sender->sentMessages));
    }

    public function test_followup_is_a_no_op_when_stopped(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->prospect->update(['status' => 'stopped', 'stopped_at' => now()]);
        $member->update(['stage' => 99]);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertNull($member->fresh()->followup_sent_at);
    }

    public function test_followup_is_a_no_op_when_booked(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->prospect->update(['status' => 'booked', 'booked_at' => now()]);
        $member->update(['stage' => 6]);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_followup_is_a_no_op_when_campaign_paused(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->campaign->update(['status' => 'paused']);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_followup_is_a_no_op_when_channel_disabled(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->campaign->channel->update(['status' => AgencyProspectingChannel::STATUS_DISABLED]);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_followup_obeys_a_later_inbound_reply(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->update(['last_inbound_at' => now()]);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertNotNull($member->fresh()->followup_sent_at, 'Must still be marked handled so it is never retried.');
    }

    public function test_successful_followup_records_an_outbound_ledger_row(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();

        $this->runFollowUp($member->id);

        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)
            ->where('direction', 'outbound')->where('status', 'sent')->count());
        $this->assertNotNull($member->fresh()->followup_sent_at);
    }

    // -----------------------------------------------------------------
    // Separation.
    // -----------------------------------------------------------------

    public function test_agency_channel_never_appears_as_a_business_connection(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);

        $this->assertSame(0, CustomerBasedSendingServer::where('sending_server', $channel->sending_server_id)->count());
    }

    // -----------------------------------------------------------------
    // Phone normalization.
    // -----------------------------------------------------------------

    public function test_plus_formatted_number_normalizes_to_canonical(): void
    {
        $this->assertSame('12025551234', AgencyProspectPhoneNormalizer::normalize('+1 202 555 1234'));
    }

    public function test_spaced_formatted_number_normalizes_the_same_as_canonical(): void
    {
        $a = AgencyProspectPhoneNormalizer::normalize('+1 (202) 555-1234');
        $b = AgencyProspectPhoneNormalizer::normalize('12025551234');
        $this->assertSame($a, $b);
    }

    public function test_a_number_with_no_country_code_fails_to_normalize(): void
    {
        $this->assertNull(AgencyProspectPhoneNormalizer::normalize('5551234'));
    }

    public function test_inbound_provider_form_maps_to_the_canonical_prospect(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $spacedFrom = '+1 (' . substr($member->prospect->phone, 1, 3) . ') ' . substr($member->prospect->phone, 4, 3) . '-' . substr($member->prospect->phone, 7);

        $this->postTwilioInbound($channel, [
            'From' => $spacedFrom, 'To' => '+' . $channel->sender_number, 'Body' => 'Hello', 'MessageSid' => 'SM-SPACED-FORM',
        ])->assertOk();

        $this->assertSame(1, AgencyProspectMessage::where('provider_message_id', 'SM-SPACED-FORM')
            ->where('campaign_member_id', $member->id)->count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createCustomerUser(): User
    {
        $user = User::create([
            'first_name' => 'Test', 'last_name' => 'User',
            'email' => 'user' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);

        Customer::create(['user_id' => $user->id]);

        return $user;
    }

    private function createPlatformAdmin(): int
    {
        return User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOnTier(WorkspacePlanTier $tier): array
    {
        $owner = $this->createCustomerUser();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createPlatformAdmin(), 'Fixture assignment.', true, 0);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function agencyWorkspace(): array
    {
        return $this->workspaceOnTier(WorkspacePlanTier::Agency);
    }

    private function makeMembership(Workspace $workspace, User $user, WorkspaceMembershipRole $role, bool $isActive): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => $isActive,
        ]);
    }

    private function createProspect(Workspace $workspace, array $overrides = []): AgencyProspect
    {
        return AgencyProspect::create(array_merge([
            'workspace_id' => $workspace->id,
            'company_name' => 'Test Prospect Co',
            'phone' => '12025551000',
            'status' => 'active',
        ], $overrides));
    }

    private function createCampaign(Workspace $workspace, array $overrides = []): AgencyProspectCampaign
    {
        return AgencyProspectCampaign::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Test Campaign',
            'status' => 'draft',
        ], $overrides));
    }

    private function createChannel(Workspace $workspace, string $provider = SendingServer::TYPE_TWILIO, array $overrides = []): AgencyProspectingChannel
    {
        $defaults = $provider === SendingServer::TYPE_TWILIO
            ? ['account_sid' => 'AC_DEFAULT', 'auth_token' => 'default_token']
            : ['api_key' => 'default_key', 'c1' => 'default_profile'];

        $serverAttributes = array_intersect_key(array_merge($defaults, $overrides), array_flip(['account_sid', 'auth_token', 'api_key', 'c1', 'c2']));

        $server = SendingServer::create(array_merge([
            'name' => $provider,
            'settings' => $provider,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $workspace->owner_user_id,
        ], $serverAttributes));

        return AgencyProspectingChannel::create([
            'workspace_id' => $workspace->id,
            'sending_server_id' => $server->id,
            'provider' => $provider,
            'sender_number' => $overrides['sender_number'] ?? ('1202555' . random_int(1000, 9999)),
            'status' => AgencyProspectingChannel::STATUS_ACTIVE,
            'created_by_user_id' => $workspace->owner_user_id,
        ]);
    }

    private function enrollDirectly(Workspace $workspace, AgencyProspectCampaign $campaign, AgencyProspect $prospect): AgencyProspectCampaignMember
    {
        return AgencyProspectCampaignMember::create([
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaign->id,
            'prospect_id' => $prospect->id,
            'stage' => 1,
            'enrolled_at' => now(),
        ]);
    }

    private function startCampaignDirectly(Workspace $workspace, AgencyProspectCampaign $campaign): void
    {
        $campaign->update(['status' => 'active']);
    }

    /**
     * @return array{0: Workspace, 1: AgencyProspectCampaignMember}
     */
    private function activeConversation(): array
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        return [$workspace, $member->fresh(['campaign.channel', 'prospect'])];
    }

    /**
     * @return array{0: Workspace, 1: AgencyProspectCampaignMember}
     */
    private function bookingLinkSentConversation(): array
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update([
            'stage' => 4,
            'booking_link_sent_at' => now()->subDay(),
            'followup_at' => now()->subMinute(),
        ]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://book.example.com']);

        return [$workspace, $member->fresh(['campaign.channel', 'prospect'])];
    }

    private function twilioWebhookUrl(AgencyProspectingChannel $channel): string
    {
        return route('prospecting.webhooks.twilio', [$channel->uid, AgencyProspectingWebhookToken::forChannel($channel->uid)]);
    }

    /**
     * Posts an inbound Twilio webhook with a genuinely valid
     * X-Twilio-Signature header, computed the same way Twilio's own SDK
     * (Twilio\Security\RequestValidator) does, using the channel's own
     * auth_token — proving the controller's real signature verification
     * path, not bypassing it.
     */
    private function postTwilioInbound(AgencyProspectingChannel $channel, array $data): \Illuminate\Testing\TestResponse
    {
        $url = $this->twilioWebhookUrl($channel);
        $validator = new \Twilio\Security\RequestValidator($channel->sendingServer->auth_token);
        $signature = $validator->computeSignature($url, $data);

        return $this->withHeaders(['X-Twilio-Signature' => $signature])->post($url, $data);
    }

    private function postTelnyxInbound(AgencyProspectCampaignMember $member, string $text, string $providerMessageId): void
    {
        $channel = $member->campaign->channel;
        $url = route('prospecting.webhooks.telnyx', [$channel->uid, AgencyProspectingWebhookToken::forChannel($channel->uid)]);

        $this->postJson($url, [
            'data' => ['payload' => [
                'direction' => 'inbound',
                'from' => ['phone_number' => '+' . $member->prospect->phone],
                'to' => [['phone_number' => '+' . $channel->sender_number]],
                'text' => $text,
                'id' => $providerMessageId,
            ]],
        ]);
    }

    private function runInitialSend(int $memberId): void
    {
        $this->app->call([new AgencyProspectingInitialSendJob($memberId), 'handle']);
    }

    private function runRespond(int $memberId, Workspace $workspace): void
    {
        $this->app->call([new AgencyProspectingRespondJob($memberId), 'handle']);
    }

    private function runFollowUp(int $memberId): void
    {
        $this->app->call([new AgencyProspectingFollowUpJob($memberId), 'handle']);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function authenticateAsCustomer(User $user): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }
}
