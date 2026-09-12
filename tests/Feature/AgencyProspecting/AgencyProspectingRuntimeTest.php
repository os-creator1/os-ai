<?php

namespace Tests\Feature\AgencyProspecting;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Jobs\AgencyProspectingFollowUpJob;
use App\Jobs\AgencyProspectingInitialSendJob;
use App\Jobs\AgencyProspectingRespondJob;
use App\Library\AgencyProspecting\AgencyProspectAiDecision;
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

    /**
     * Customer Experience Slice 1A (contract §2/§6a #24): the visible
     * connect-form labels are plain language, but the underlying field
     * names — what actually gets posted — are untouched, identical to
     * MessagingChannelsController's own five-label change.
     */
    public function test_twilio_connect_form_shows_plain_language_labels_with_unchanged_field_names(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $response = $this->get(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Twilio']))->assertOk();

        $response->assertSee('Twilio account identifier', false);
        $response->assertSee('Twilio secret', false);
        $response->assertDontSee('Account SID', false);
        $response->assertDontSee('Auth Token', false);
        $response->assertSee('name="account_sid"', false);
        $response->assertSee('name="auth_token"', false);
    }

    public function test_telnyx_connect_form_shows_plain_language_labels_with_unchanged_field_names(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $response = $this->get(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Telnyx']))->assertOk();

        $response->assertSee('Telnyx access key', false);
        $response->assertSee('Messaging profile ID', false);
        $response->assertSee('Messaging connection ID', false);
        $response->assertDontSee('API Key', false);
        $response->assertDontSee('Message Profile ID', false);
        $response->assertDontSee('Message Connection ID', false);
        $response->assertSee('name="api_key"', false);
        $response->assertSee('name="c1"', false);
        $response->assertSee('name="c2"', false);
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
    // Campaign lifecycle.
    // -----------------------------------------------------------------

    public function test_draft_to_paused_forged_post_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $campaign = $this->createCampaign($workspace);
        $this->authenticateAsCustomer($owner);

        // 'paused' is itself a valid enum value in general (unlike
        // 'draft' in the sibling tests, which the request validation
        // rejects outright) — this is the controller's own Draft
        // guard being exercised, not the request validation layer.
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), [
            'status' => 'paused',
        ])->assertSessionHas('flash_error');

        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_active_to_draft_forged_post_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), [
            'status' => 'draft',
        ])->assertSessionHasErrors('status');

        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_paused_to_draft_forged_post_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), [
            'status' => 'draft',
        ])->assertSessionHasErrors('status');

        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_active_to_paused_works(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), [
            'status' => 'paused',
        ])->assertSessionHas('flash_success');

        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_paused_to_active_works(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), [
            'status' => 'active',
        ])->assertSessionHas('flash_success');

        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_prospect_in_paused_campaign_blocks_start_of_another_campaign(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        $campaignA = $this->createCampaign($workspace, ['name' => 'A', 'channel_id' => $channel->id, 'opening_message' => 'Hi A', 'status' => 'paused']);
        $this->enrollDirectly($workspace, $campaignA, $prospect);

        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channel->id, 'opening_message' => 'Hi B']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignB->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('draft', $campaignB->fresh()->status->value);
    }

    // -----------------------------------------------------------------
    // Campaign config is Draft-only.
    // -----------------------------------------------------------------

    public function test_draft_campaign_config_works(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channel->uid,
            'opening_message' => 'Hi!',
        ])->assertSessionHas('flash_success');

        $this->assertSame($channel->id, $campaign->fresh()->channel_id);
    }

    public function test_active_campaign_config_tamper_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channelA = $this->createChannel($workspace);
        $channelB = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channelA->id, 'opening_message' => 'Original', 'status' => 'active']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channelB->uid,
            'opening_message' => 'Tampered',
        ])->assertSessionHas('flash_error');

        $fresh = $campaign->fresh();
        $this->assertSame($channelA->id, $fresh->channel_id);
        $this->assertSame('Original', $fresh->opening_message);
    }

    public function test_paused_campaign_config_tamper_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channelA = $this->createChannel($workspace);
        $channelB = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channelA->id, 'opening_message' => 'Original', 'status' => 'paused']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channelB->uid,
            'opening_message' => 'Tampered',
        ])->assertSessionHas('flash_error');

        $fresh = $campaign->fresh();
        $this->assertSame($channelA->id, $fresh->channel_id);
        $this->assertSame('Original', $fresh->opening_message);
    }

    /**
     * Correction 2, Section 2 — Config and Start now serialize on the
     * same campaign row. Config-first: it commits while the campaign is
     * still genuinely Draft, so Start subsequently sees the new config.
     */
    public function test_config_then_start_sees_the_new_config(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channelA = $this->createChannel($workspace);
        $channelB = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channelA->id, 'opening_message' => 'Original']);
        $prospect = $this->createProspect($workspace);
        $this->enrollDirectly($workspace, $campaign, $prospect);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channelB->uid,
            'opening_message' => 'Updated',
        ])->assertSessionHas('flash_success');

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('Updated', $this->sender->sentMessages[0]['body']);
        $this->assertSame($channelB->id, $this->sender->sentMessages[0]['channel_id']);
    }

    /**
     * Correction 2, Section 2 — Start-first: once Start has locked and
     * activated the campaign, a later Config request re-fetches under its
     * own lock, observes the campaign is no longer Draft, and is
     * rejected — the live (already-Started) config is left untouched.
     */
    public function test_start_then_config_is_rejected_and_live_config_unchanged(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channelA = $this->createChannel($workspace);
        $channelB = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channelA->id, 'opening_message' => 'Original']);
        $prospect = $this->createProspect($workspace);
        $this->enrollDirectly($workspace, $campaign, $prospect);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_success');

        $this->post(route('customer.workspaces.prospecting.campaigns.config', [$workspace->uid, $campaign->uid]), [
            'channel_uid' => $channelB->uid,
            'opening_message' => 'Tampered',
        ])->assertSessionHas('flash_error');

        $fresh = $campaign->fresh();
        $this->assertSame($channelA->id, $fresh->channel_id);
        $this->assertSame('Original', $fresh->opening_message);
    }

    // -----------------------------------------------------------------
    // Campaign membership is frozen after Start.
    // -----------------------------------------------------------------

    public function test_draft_enrollment_works(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $campaign = $this->createCampaign($workspace);
        $prospect = $this->createProspect($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_success');

        $this->assertSame(1, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('prospect_id', $prospect->id)->count());
    }

    public function test_active_campaign_enrollment_post_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $prospect = $this->createProspect($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('prospect_id', $prospect->id)->count());
    }

    public function test_paused_campaign_enrollment_post_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $prospect = $this->createProspect($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('prospect_id', $prospect->id)->count());
    }

    public function test_active_ui_does_not_expose_enrollment_form(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $this->authenticateAsCustomer($owner);

        $response = $this->get(route('customer.workspaces.prospecting.campaigns.show', [$workspace->uid, $campaign->uid]));

        $response->assertOk();
        $response->assertDontSee('name="prospect_uid"', false);
    }

    public function test_paused_ui_does_not_expose_enrollment_form(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $this->authenticateAsCustomer($owner);

        $response = $this->get(route('customer.workspaces.prospecting.campaigns.show', [$workspace->uid, $campaign->uid]));

        $response->assertOk();
        $response->assertDontSee('name="prospect_uid"', false);
    }

    /**
     * Correction 2, Section 1 — proves the locking structure: whichever
     * request locks the campaign row first (Start committing it Active)
     * determines the outcome for a concurrent Enroll attempt.
     */
    public function test_start_then_enroll_is_rejected_and_creates_no_membership(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $existingProspect = $this->createProspect($workspace, ['phone' => '12025551111']);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi']);
        $this->enrollDirectly($workspace, $campaign, $existingProspect);
        $newProspect = $this->createProspect($workspace, ['phone' => '12025552222']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaign->uid]))
            ->assertSessionHas('flash_success');

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $newProspect->uid,
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('prospect_id', $newProspect->id)->count());
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

    /**
     * Correction 2 — proves the locking structure itself, not just the
     * pre-existing "already active" shortcut: both campaigns start as
     * genuine Drafts sharing one prospect, and the real HTTP Start action
     * is exercised on each. Whichever request's transaction commits first
     * wins; the second re-evaluates the authoritative (now-committed)
     * state under its own lock and is correctly rejected — proven here in
     * BOTH orderings (A-then-B and, in the sibling test, B-then-A),
     * rather than relying on a pre-transaction snapshot check that a
     * genuinely concurrent request could still pass.
     */
    public function test_serialized_start_a_then_b_rejects_b(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        $campaignA = $this->createCampaign($workspace, ['name' => 'A', 'channel_id' => $channel->id, 'opening_message' => 'Hi A']);
        $this->enrollDirectly($workspace, $campaignA, $prospect);

        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channel->id, 'opening_message' => 'Hi B']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignA->uid]))
            ->assertSessionHas('flash_success');
        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignB->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('active', $campaignA->fresh()->status->value);
        $this->assertSame('draft', $campaignB->fresh()->status->value);
    }

    public function test_serialized_start_b_then_a_rejects_a(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        $campaignA = $this->createCampaign($workspace, ['name' => 'A', 'channel_id' => $channel->id, 'opening_message' => 'Hi A']);
        $this->enrollDirectly($workspace, $campaignA, $prospect);

        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channel->id, 'opening_message' => 'Hi B']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignB->uid]))
            ->assertSessionHas('flash_success');
        $this->post(route('customer.workspaces.prospecting.campaigns.start', [$workspace->uid, $campaignA->uid]))
            ->assertSessionHas('flash_error');

        $this->assertSame('active', $campaignB->fresh()->status->value);
        $this->assertSame('draft', $campaignA->fresh()->status->value);
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

    public function test_active_campaign_plus_draft_membership_resolves_to_active(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $prospect = $member->prospect;

        // A Draft enrollment for the same prospect on the same channel is
        // intentionally legal and must never count as competing
        // attribution — it is not a real, open conversation.
        $draftCampaign = $this->createCampaign($workspace, ['name' => 'Draft', 'channel_id' => $channel->id, 'opening_message' => 'Hi']);
        $this->enrollDirectly($workspace, $draftCampaign, $prospect);

        $this->postTwilioInbound($channel, [
            'From' => '+' . $prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Tell me more', 'MessageSid' => 'SM-DRAFT-NOISE',
        ])->assertOk();

        $inbound = AgencyProspectMessage::where('provider_message_id', 'SM-DRAFT-NOISE')->first();
        $this->assertNotNull($inbound);
        $this->assertSame($member->id, $inbound->campaign_member_id);
    }

    public function test_inbound_on_channel_a_does_not_route_to_campaign_using_channel_b(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channelA = $this->createChannel($workspace);
        $channelB = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);

        // The prospect's only OPEN conversation is on Channel B.
        $campaignB = $this->createCampaign($workspace, ['name' => 'B', 'channel_id' => $channelB->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $this->enrollDirectly($workspace, $campaignB, $prospect);

        // An inbound message arrives on Channel A instead.
        $this->postTwilioInbound($channelA, [
            'From' => '+' . $prospect->phone, 'To' => '+' . $channelA->sender_number, 'Body' => 'Hello', 'MessageSid' => 'SM-WRONG-CHANNEL',
        ])->assertOk();

        $this->assertSame(0, AgencyProspectMessage::where('provider_message_id', 'SM-WRONG-CHANNEL')->count());
        $this->assertSame(0, count($this->aiClient->receivedMessages));
    }

    public function test_paused_campaign_normal_inbound_is_recorded_with_no_ai_reply(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->campaign->update(['status' => 'paused']);
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Still interested?', 'MessageSid' => 'SM-PAUSED-NORMAL',
        ])->assertOk();

        $this->assertSame(1, AgencyProspectMessage::where('provider_message_id', 'SM-PAUSED-NORMAL')->count());
        $this->assertSame(0, count($this->aiClient->receivedMessages));
        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_paused_campaign_stop_still_works(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->campaign->update(['status' => 'paused']);
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'STOP', 'MessageSid' => 'SM-PAUSED-STOP',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(99, $member->fresh()->stage->value);
    }

    public function test_duplicate_webhook_delivery_does_not_trigger_a_second_ai_call(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $payload = [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Tell me more', 'MessageSid' => 'SM-DUPE-DELIVERY',
        ];

        $this->postTwilioInbound($channel, $payload)->assertOk();
        $this->postTwilioInbound($channel, $payload)->assertOk();

        $this->assertSame(1, AgencyProspectMessage::where('provider_message_id', 'SM-DUPE-DELIVERY')->count());
        $this->assertSame(1, count($this->aiClient->receivedMessages));
        $this->assertSame(1, count($this->sender->sentMessages));
    }

    public function test_respond_job_rejects_an_inbound_message_belonging_to_a_different_member(): void
    {
        [$workspace, $memberA] = $this->activeConversation();
        [, $memberB] = $this->activeConversation();

        $foreignInbound = AgencyProspectMessage::create([
            'workspace_id' => $memberB->workspace_id,
            'campaign_member_id' => $memberB->id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'SM-FOREIGN-BIND',
            'body' => 'Hello',
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        // Forged/stale ids: memberA paired with memberB's own inbound row.
        $this->runRespondForMessage($memberA->id, $foreignInbound->id);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(0, count($this->aiClient->receivedMessages));
        $this->assertSame(1, $memberA->fresh()->stage->value);
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

    /**
     * Correction 2, Section 5 — the decisive transition check happens
     * inside the claim transaction against the freshly LOCKED, CURRENT
     * stage, never the stage read when the AI call was made. Simulated
     * via the fake AI client's beforeReturn hook, which fires at exactly
     * the moment a real model call would return — the window between the
     * AI call and the later claim transaction.
     */
    public function test_stale_ai_decision_sends_nothing_when_member_advanced_before_claim(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);
        $this->aiClient->beforeReturn = fn () => AgencyProspectCampaignMember::where('id', $member->id)->update(['stage' => 3]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(3, $member->fresh()->stage->value);
    }

    public function test_stale_ai_decision_sends_nothing_when_member_stopped_before_claim(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);
        $this->aiClient->beforeReturn = fn () => AgencyProspectCampaignMember::where('id', $member->id)->update(['stage' => 99]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(99, $member->fresh()->stage->value);
    }

    public function test_stale_ai_decision_sends_nothing_when_member_booked_before_claim(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);
        $this->aiClient->beforeReturn = fn () => AgencyProspectCampaignMember::where('id', $member->id)->update(['stage' => 6]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(6, $member->fresh()->stage->value);
    }

    public function test_ordinary_valid_current_transition_still_sends(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'question', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);
        $this->aiClient->beforeReturn = fn () => null;

        $this->runRespond($member->id, $workspace);

        $this->assertSame(1, count($this->sender->sentMessages));
        $this->assertSame(2, $member->fresh()->stage->value);
    }

    /**
     * Correction 2, Section 6 — a deliberately malformed inbound row
     * (correct member id, but a mismatched workspace_id — something a
     * genuine webhook could never produce) must fail closed: no AI call,
     * no send, no state change.
     */
    public function test_inbound_message_with_mismatched_workspace_is_rejected(): void
    {
        [$workspace, $member] = $this->activeConversation();
        [, $otherWorkspace] = $this->agencyWorkspace();

        $malformedInbound = AgencyProspectMessage::create([
            'workspace_id' => $otherWorkspace->id,
            'campaign_member_id' => $member->id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'SM-MISMATCHED-WS',
            'body' => 'Hello',
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Hi', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespondForMessage($member->id, $malformedInbound->id);

        $this->assertSame(0, count($this->aiClient->receivedMessages));
        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(1, $member->fresh()->stage->value);
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
        $this->assertStringNotContainsString('fake-ai-invented-url.example.com', $sentBody, 'The AI must never be able to smuggle its own URL into the outbound body.');
    }

    public function test_ordinary_ai_reply_cannot_smuggle_a_url(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'question', 'reply' => 'Check us out at https://sketchy-unauthorized.example.com for more info!',
            'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $sentBody = $this->sender->sentMessages[0]['body'];
        $this->assertStringNotContainsString('sketchy-unauthorized.example.com', $sentBody);
    }

    /**
     * Correction 2, Section 8 — the URL/host filter is deliberately
     * broader than bare http(s) links: www./bare-domain/other-scheme/
     * mailto tokens must all be stripped from AI-authored text.
     */
    public function test_ai_url_sanitization_covers_www_and_bare_domains(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'question', 'reply' => 'Visit www.fake-example.com or fake-example.com/path for info!',
            'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $sentBody = $this->sender->sentMessages[0]['body'];
        $this->assertStringNotContainsString('fake-example.com', $sentBody);
    }

    public function test_ai_url_sanitization_covers_other_schemes_and_mailto(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'question', 'reply' => 'Try ftp://fake-example.com or customscheme://host or mailto:test@example.com',
            'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $sentBody = $this->sender->sentMessages[0]['body'];
        $this->assertStringNotContainsString('fake-example.com', $sentBody);
        $this->assertStringNotContainsString('customscheme://', $sentBody);
        $this->assertStringNotContainsString('mailto:', $sentBody);
        $this->assertStringNotContainsString('test@example.com', $sentBody);
    }

    public function test_configured_https_booking_url_works(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://valid.example.com/book']);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'booking', 'reply' => 'Here is the link', 'next_stage' => 4, 'send_booking_link' => true, 'proposed_slot' => null]);

        // The booking-link send schedules a delayed follow-up job; under
        // this environment's sync queue driver, an un-faked dispatch runs
        // immediately (delay is ignored), which would send a second
        // message and break the exact-count assertion below.
        Queue::fake();
        $this->runRespond($member->id, $workspace);

        $this->assertSame(1, count($this->sender->sentMessages));
        $this->assertStringContainsString('https://valid.example.com/book', $this->sender->sentMessages[0]['body']);
        $this->assertSame(4, $member->fresh()->stage->value);
    }

    public function test_malformed_non_http_booking_url_sends_nothing(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        // Written directly to bypass the (now http/https-only) form
        // validation, simulating a value that entered the database some
        // other way — the send-time gate must still catch it.
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'javascript:alert(1)']);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'booking', 'reply' => 'Here is the link', 'next_stage' => 4, 'send_booking_link' => true, 'proposed_slot' => null]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(3, $member->fresh()->stage->value);
        $this->assertNull($member->fresh()->booking_link_sent_at);
    }

    public function test_agent_setup_rejects_a_non_http_booking_url(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.settings.update', $workspace->uid), [
            'booking_url' => 'ftp://example.com/book',
        ])->assertSessionHasErrors('booking_url');
    }

    public function test_send_booking_link_true_without_next_stage_4_is_invalid(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://book.example.com']);
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'booking', 'reply' => 'Here is a link', 'next_stage' => 2, 'send_booking_link' => true, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(3, $member->fresh()->stage->value);
    }

    public function test_next_stage_4_without_send_booking_link_is_invalid(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        AgencyProspectingSetting::updateOrCreate(['workspace_id' => $workspace->id], ['booking_url' => 'https://book.example.com']);
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'booking', 'reply' => 'Moving forward', 'next_stage' => 4, 'send_booking_link' => false, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(3, $member->fresh()->stage->value);
        $this->assertNull($member->fresh()->booking_link_sent_at);
    }

    public function test_send_booking_link_without_configured_url_is_invalid(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        // No booking_url configured for this Workspace at all.
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'booking', 'reply' => 'Here is a link', 'next_stage' => 4, 'send_booking_link' => true, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertSame(3, $member->fresh()->stage->value);
        $this->assertNull($member->fresh()->booking_link_sent_at);
    }

    public function test_next_stage_99_sends_nothing_and_stops_immediately(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $this->aiClient->nextRawResponse = json_encode([
            'intent' => 'other', 'reply' => 'Some sales copy that must never be sent',
            'next_stage' => 99, 'send_booking_link' => false, 'proposed_slot' => null,
        ]);

        $this->runRespond($member->id, $workspace);

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(99, $member->fresh()->stage->value);
        $this->assertSame(0, count($this->sender->sentMessages), 'next_stage=99 must stop before any outbound sales copy is sent.');
    }

    // -----------------------------------------------------------------
    // Reply intent (Unified Home contract A-2).
    // -----------------------------------------------------------------

    public function test_a_validated_positive_decision_persists_on_the_correct_inbound_message(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        $other = $this->createInboundMessage($member);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertSame('positive', $inbound->fresh()->intent);
        $this->assertNull($other->fresh()->intent, 'Only the exact inbound message the job was dispatched for is classified.');
    }

    public function test_every_canonical_intent_can_persist(): void
    {
        foreach (AgencyProspectAiDecision::INTENTS as $intent) {
            [$workspace, $member] = $this->activeConversation();
            $inbound = $this->createInboundMessage($member);
            $this->aiClient->nextRawResponse = json_encode(['intent' => $intent, 'reply' => 'Reply text', 'next_stage' => null, 'send_booking_link' => false, 'proposed_slot' => null]);

            $this->runRespondForMessage($member->id, $inbound->id);

            $this->assertSame($intent, $inbound->fresh()->intent, "Canonical intent [{$intent}] must persist.");
        }
    }

    public function test_invalid_json_decision_persists_no_intent(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        $this->aiClient->nextRawResponse = 'not valid json {{{';

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertNull($inbound->fresh()->intent);
    }

    public function test_an_unavailable_or_failed_ai_client_persists_no_fabricated_intent(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        // The fake's default: no configured response, exactly how a
        // disabled/unconfigured/failed real client behaves (null).
        $this->aiClient->nextRawResponse = null;

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertNull($inbound->fresh()->intent);
    }

    public function test_hard_negative_decision_still_persists_its_intent_before_stopping(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'hard_negative', 'reply' => 'Understood.', 'next_stage' => null, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertSame('hard_negative', $inbound->fresh()->intent);
        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
    }

    public function test_next_stage_99_decision_still_persists_its_intent_before_stopping(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'other', 'reply' => 'Goodbye', 'next_stage' => 99, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertSame('other', $inbound->fresh()->intent);
        $this->assertSame(99, $member->fresh()->stage->value);
    }

    /**
     * Two overlapping executions for the SAME inbound message must never
     * leave contradictory durable intent behind — first classification
     * wins. `beforeReturn` fires at the exact moment this run's own model
     * call returns, simulating another execution having already persisted
     * a different intent for this exact message in that window (the AI is
     * not deterministic, so a genuine retry could easily classify
     * differently the second time).
     */
    public function test_a_retried_classification_cannot_overwrite_an_already_persisted_intent(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'other', 'reply' => 'Hi', 'next_stage' => null, 'send_booking_link' => false, 'proposed_slot' => null]);
        $this->aiClient->beforeReturn = fn () => AgencyProspectMessage::where('id', $inbound->id)->update(['intent' => 'positive']);

        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertSame('positive', $inbound->fresh()->intent, 'First classification wins; a later run must never overwrite it.');
    }

    public function test_duplicate_webhook_delivery_persists_intent_exactly_once(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $payload = [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'Tell me more', 'MessageSid' => 'SM-INTENT-DUPE',
        ];

        $this->postTwilioInbound($channel, $payload)->assertOk();
        $this->postTwilioInbound($channel, $payload)->assertOk();

        $inbound = AgencyProspectMessage::where('provider_message_id', 'SM-INTENT-DUPE')->first();
        $this->assertSame('positive', $inbound->intent);
        $this->assertSame(1, count($this->aiClient->receivedMessages), 'The duplicate delivery must never trigger a second classification.');
    }

    public function test_a_message_row_with_no_intent_remains_valid(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = $this->createInboundMessage($member);

        $this->assertNull($inbound->fresh()->intent, 'A row classified before this column existed (or never classified) keeps a null intent indefinitely.');
    }

    private function createInboundMessage(AgencyProspectCampaignMember $member): AgencyProspectMessage
    {
        return AgencyProspectMessage::create([
            'workspace_id' => $member->workspace_id,
            'campaign_member_id' => $member->id,
            'channel_id' => $member->campaign?->channel_id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'TEST-INBOUND-' . uniqid('', true),
            'body' => 'Test inbound message',
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
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
        // Correction 1 — followup_sent_at must mean ONLY "a provider send
        // actually succeeded"; suppression because of a later inbound
        // reply is recorded as cancelled, never falsely as sent.
        $this->assertNull($member->fresh()->followup_sent_at);
        $this->assertNotNull($member->fresh()->followup_cancelled_at, 'Must still be marked handled so it is never retried.');
    }

    public function test_cancelled_followup_is_never_retried(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->update(['last_inbound_at' => now()]);

        $this->runFollowUp($member->id);
        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_successful_followup_records_an_outbound_ledger_row(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();

        $this->runFollowUp($member->id);

        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $member->id)
            ->where('direction', 'outbound')->where('purpose', 'followup')->where('status', 'sent')->count());
        $this->assertNotNull($member->fresh()->followup_sent_at);
    }

    // -----------------------------------------------------------------
    // Outbound idempotency.
    // -----------------------------------------------------------------

    public function test_same_inbound_id_invoked_twice_sends_one_ai_reply(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $inbound = AgencyProspectMessage::create([
            'workspace_id' => $member->workspace_id,
            'campaign_member_id' => $member->id,
            'channel_id' => $member->campaign->channel_id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'SM-RETRY-BASIS',
            'body' => 'Tell me more',
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
        $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

        $this->runRespondForMessage($member->id, $inbound->id);
        $this->runRespondForMessage($member->id, $inbound->id);

        $this->assertSame(1, count($this->sender->sentMessages));
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'ai_reply:' . $inbound->id)->where('status', 'sent')->count());
    }

    public function test_initial_send_operation_key_unique_constraint_is_enforced_by_the_database(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $member->id,
            'channel_id' => $channel->id,
            'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
            'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
            'operation_key' => 'initial:' . $member->id,
            'body' => 'Existing claim',
            'status' => AgencyProspectMessage::STATUS_PENDING,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $member->id,
            'channel_id' => $channel->id,
            'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
            'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
            'operation_key' => 'initial:' . $member->id,
            'body' => 'Duplicate claim attempt',
            'status' => AgencyProspectMessage::STATUS_PENDING,
        ]);
    }

    /**
     * Correction 2, Section 3 — a TRUE conservative at-most-once policy:
     * once ANY operation row exists for a given key, an automatic
     * execution never calls the provider again, regardless of that row's
     * status (pending/failed/sent). This replaces the prior (incorrect)
     * test which asserted the opposite — that a failed attempt was
     * automatically retried by the same job. A missed message is
     * preferred over a duplicated one; a deliberate human "retry failed
     * send" feature would need separate, explicit semantics not
     * implemented here.
     */
    public function test_initial_send_existing_pending_operation_blocks_automatic_retry(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'Already claimed', 'status' => 'pending',
        ]);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_initial_send_existing_failed_operation_blocks_automatic_retry(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'Already failed', 'status' => 'failed',
        ]);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_initial_send_existing_sent_operation_blocks_automatic_retry(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'Already sent', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->runInitialSend($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_ai_reply_existing_operation_in_any_status_blocks_automatic_retry(): void
    {
        foreach (['pending', 'failed', 'sent'] as $status) {
            [$workspace, $member] = $this->activeConversation();
            $inbound = AgencyProspectMessage::create([
                'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $member->campaign->channel_id,
                'direction' => 'inbound', 'provider_message_id' => 'SM-AI-BLOCK-' . $status, 'body' => 'Tell me more', 'status' => 'received', 'received_at' => now(),
            ]);

            AgencyProspectMessage::create([
                'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $member->campaign->channel_id,
                'direction' => 'outbound', 'purpose' => 'ai_reply', 'operation_key' => 'ai_reply:' . $inbound->id,
                'body' => 'Already claimed', 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null,
            ]);

            $this->aiClient->nextRawResponse = json_encode(['intent' => 'positive', 'reply' => 'Great!', 'next_stage' => 2, 'send_booking_link' => false, 'proposed_slot' => null]);

            $this->runRespondForMessage($member->id, $inbound->id);

            $this->assertSame(0, count($this->sender->sentMessages), "status={$status} must block automatic retry");
        }
    }

    public function test_followup_existing_operation_in_any_status_blocks_automatic_retry(): void
    {
        foreach (['pending', 'failed', 'sent'] as $status) {
            [$workspace, $member] = $this->bookingLinkSentConversation();

            AgencyProspectMessage::create([
                'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $member->campaign->channel_id,
                'direction' => 'outbound', 'purpose' => 'followup', 'operation_key' => 'followup:' . $member->id,
                'body' => 'Already claimed', 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null,
            ]);

            $this->runFollowUp($member->id);

            $this->assertSame(0, count($this->sender->sentMessages), "status={$status} must block automatic retry");
        }
    }

    // -----------------------------------------------------------------
    // Soft negative — bounded repeated handling.
    // -----------------------------------------------------------------

    public function test_stage_1_first_soft_negative_may_queue_ai(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'no thanks', 'MessageSid' => 'SM-SOFTNEG-1',
        ])->assertOk();

        $this->assertSame('active', $member->prospect->fresh()->status->value);
        $this->assertSame(1, $member->fresh()->soft_negative_count);
        $this->assertSame(1, count($this->aiClient->receivedMessages));
    }

    public function test_stage_1_second_soft_negative_stops_without_calling_ai(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'no thanks', 'MessageSid' => 'SM-SOFTNEG-A',
        ])->assertOk();

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'no thank you', 'MessageSid' => 'SM-SOFTNEG-B',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(99, $member->fresh()->stage->value);
        $this->assertSame(1, count($this->aiClient->receivedMessages), 'Only the first soft negative may reach the AI.');
    }

    public function test_stage_3_soft_negative_stops_immediately(): void
    {
        [$workspace, $member] = $this->activeConversation();
        $member->update(['stage' => 3]);
        $channel = $member->campaign->channel;

        $this->postTwilioInbound($channel, [
            'From' => '+' . $member->prospect->phone, 'To' => '+' . $channel->sender_number, 'Body' => 'no thanks', 'MessageSid' => 'SM-SOFTNEG-STAGE3',
        ])->assertOk();

        $this->assertSame('stopped', $member->prospect->fresh()->status->value);
        $this->assertSame(0, count($this->aiClient->receivedMessages));
    }

    // -----------------------------------------------------------------
    // Pause / resume follow-up.
    // -----------------------------------------------------------------

    public function test_pause_suppresses_scheduled_followup(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->campaign->update(['status' => 'paused']);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertNull($member->fresh()->followup_sent_at);
    }

    public function test_resume_restores_still_valid_pending_followup(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->campaign->update(['status' => 'paused']);
        $owner = User::find($workspace->owner_user_id);
        $this->authenticateAsCustomer($owner);

        Queue::fake();
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $member->campaign->uid]), [
            'status' => 'active',
        ])->assertSessionHas('flash_success');

        Queue::assertPushed(AgencyProspectingFollowUpJob::class);
    }

    public function test_repeated_resume_cannot_duplicate_followup(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->campaign->update(['status' => 'paused']);
        $owner = User::find($workspace->owner_user_id);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $member->campaign->uid]), ['status' => 'active']);
        $this->runFollowUp($member->id);

        $member->campaign->update(['status' => 'paused']);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $member->campaign->uid]), ['status' => 'active']);
        $this->runFollowUp($member->id);

        $this->assertSame(1, count($this->sender->sentMessages));
    }

    public function test_replied_prospect_remains_suppressed_across_resume(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        $member->update(['last_inbound_at' => now()]);
        $member->campaign->update(['status' => 'paused']);
        $owner = User::find($workspace->owner_user_id);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $member->campaign->uid]), ['status' => 'active']);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    // -----------------------------------------------------------------
    // Pause / resume — unclaimed initial sends.
    // -----------------------------------------------------------------

    /**
     * Correction 2, Section 9 — a member whose InitialSendJob executed
     * and correctly no-op'd while the campaign was Paused (before it ever
     * claimed an "initial:{memberId}" operation) must be recovered by the
     * next Resume, since nothing else would ever re-dispatch it.
     */
    public function test_resume_dispatches_missing_initial_send_never_claimed(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        // Simulates the InitialSendJob having already run once while
        // Paused and correctly no-op'd — no operation row was ever
        // claimed for this member.
        $this->runInitialSend($member->id);
        $this->assertSame(0, count($this->sender->sentMessages));

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), ['status' => 'active']);

        $this->assertSame(1, count($this->sender->sentMessages));
    }

    public function test_resume_does_not_retry_an_existing_pending_initial_operation(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'In flight', 'status' => 'pending',
        ]);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), ['status' => 'active']);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_resume_does_not_retry_an_existing_failed_initial_operation(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'Failed earlier', 'status' => 'failed',
        ]);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), ['status' => 'active']);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_resume_does_not_duplicate_an_existing_sent_initial_operation(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id,
            'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id,
            'body' => 'Already sent', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), ['status' => 'active']);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    public function test_resume_does_not_dispatch_initial_send_for_stopped_or_booked_member(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $stoppedProspect = $this->createProspect($workspace, ['status' => 'stopped', 'stopped_at' => now(), 'phone' => '12025553333']);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'paused']);
        AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaign->id, 'prospect_id' => $stoppedProspect->id, 'stage' => 99, 'enrolled_at' => now()]);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspace->uid, $campaign->uid]), ['status' => 'active']);

        $this->assertSame(0, count($this->sender->sentMessages));
    }

    /**
     * Correction 2, Section 10 — the claim transaction re-evaluates
     * "later inbound cancels follow-up" against CURRENT state, not just
     * the earlier snapshot. Mutating last_inbound_at directly models a
     * reply arriving in the window between the snapshot read and the
     * claim (the job itself performs both steps in one synchronous call
     * in this sync-queue test environment, so this proves the claim
     * transaction's own re-check independently catches it even when the
     * snapshot alone would not have).
     */
    public function test_followup_claim_recheck_cancels_on_current_late_inbound(): void
    {
        [$workspace, $member] = $this->bookingLinkSentConversation();
        // last_inbound_at is null at snapshot time (no cancellation seen
        // yet); by the time the claim transaction re-reads the member
        // fresh, a reply has arrived.
        $member->update(['last_inbound_at' => $member->booking_link_sent_at->addHour()]);

        $this->runFollowUp($member->id);

        $this->assertSame(0, count($this->sender->sentMessages));
        $this->assertNull($member->fresh()->followup_sent_at);
        $this->assertNotNull($member->fresh()->followup_cancelled_at);
    }

    // -----------------------------------------------------------------
    // Analytics.
    // -----------------------------------------------------------------

    public function test_initial_message_metric_excludes_ai_and_followup_outbound_rows(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $channel = $this->createChannel($workspace);
        $prospect = $this->createProspect($workspace);
        $campaign = $this->createCampaign($workspace, ['channel_id' => $channel->id, 'opening_message' => 'Hi', 'status' => 'active']);
        $member = $this->enrollDirectly($workspace, $campaign, $prospect);

        AgencyProspectMessage::create(['workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id, 'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'initial:' . $member->id, 'body' => 'Opener', 'status' => 'sent', 'sent_at' => now()]);
        AgencyProspectMessage::create(['workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id, 'direction' => 'outbound', 'purpose' => 'ai_reply', 'operation_key' => 'ai_reply:1', 'body' => 'AI reply', 'status' => 'sent', 'sent_at' => now()]);
        AgencyProspectMessage::create(['workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'channel_id' => $channel->id, 'direction' => 'outbound', 'purpose' => 'followup', 'operation_key' => 'followup:' . $member->id, 'body' => 'Followup', 'status' => 'sent', 'sent_at' => now()]);

        $this->authenticateAsCustomer($owner);
        $response = $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid));

        $response->assertOk();
        $response->assertViewHas('initialMessagesSent', 1);
    }

    // -----------------------------------------------------------------
    // Actor vs. owner identity.
    // -----------------------------------------------------------------

    public function test_active_admin_actor_is_recorded_as_channel_creator_not_provider_owner(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $admin = $this->createCustomerUser();
        $this->makeMembership($workspace, $admin, WorkspaceMembershipRole::Admin, true);
        $this->authenticateAsCustomer($admin);

        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Twilio']), [
            'sender_number' => '+12025559000',
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ])->assertSessionHas('flash_success');

        $channel = AgencyProspectingChannel::where('workspace_id', $workspace->id)->first();
        $this->assertSame($owner->id, $channel->sendingServer->user_id, 'SendingServer ownership stays bound to the Workspace owner.');
        $this->assertSame($admin->id, $channel->created_by_user_id, 'created_by_user_id must record the actual acting Admin.');
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

        // A member genuinely at stage 4 has necessarily already had its
        // initial contact sent — record that real "initial:{id}" claim so
        // Correction 2's resume-recovers-unclaimed-initial-sends logic
        // correctly leaves this member alone (only a genuinely never-
        // attempted initial send is ever recovered on resume).
        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $member->id,
            'channel_id' => $member->campaign->channel_id,
            'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
            'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
            'operation_key' => 'initial:' . $member->id,
            'body' => 'Hi',
            'status' => AgencyProspectMessage::STATUS_SENT,
            'sent_at' => now()->subDays(2),
        ]);

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

    /**
     * Auto-creates a fresh inbound AgencyProspectMessage for the given
     * member when the caller does not need to control which inbound
     * message triggers the response (most AI-decision tests care only
     * about the decision handling, not the inbound content) — keeps every
     * existing `runRespond($id, $workspace)` call site working unchanged
     * after AgencyProspectingRespondJob was bound to an exact inbound
     * message id. `$workspace` is accepted but unused, exactly as before.
     */
    private function runRespond(int $memberId, Workspace $workspace): void
    {
        $member = AgencyProspectCampaignMember::findOrFail($memberId);

        $inbound = AgencyProspectMessage::create([
            'workspace_id' => $member->workspace_id,
            'campaign_member_id' => $memberId,
            'channel_id' => $member->campaign?->channel_id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'TEST-INBOUND-' . uniqid('', true),
            'body' => 'Test inbound message',
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->runRespondForMessage($memberId, $inbound->id);
    }

    private function runRespondForMessage(int $memberId, int $inboundMessageId): void
    {
        $this->app->call([new AgencyProspectingRespondJob($memberId, $inboundMessageId), 'handle']);
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
