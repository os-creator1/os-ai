<?php

namespace Tests\Feature\Outreach;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\Templates;
use App\Models\TrackingLog;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Business-Scoped Outreach / CRM — Correction 1.
 *
 * Human mechanical review found six concrete defects in
 * EloquentCampaignRepository's Business-aware paths:
 *  1. campaignBuilder() unconditionally activated the legacy Agency
 *     AI-Prospecting hook (chat_boxes/ai_box_campaign_map) even for
 *     Business-aware Outreach campaigns.
 *  2. create-template used LegacyBusinessResolver instead of the explicit
 *     selected Business.
 *  3. sendApi() had an accidental, undefined-variable reference to
 *     $businessId (over-applied Business-scoping edit).
 *  4. restart()/resend() resolved tenant/billing identity from the acting
 *     Auth actor instead of the campaign's own owner (its Business, when
 *     it has one).
 *  5. Two sender/number/server validation branches still authorized a
 *     submitted resource against the owner's user_id even when an
 *     explicit Business was selected, allowing cross-Business tamper.
 *
 * This file proves each fix and that every legacy/API-only path
 * (sendApi, apiCampaignBuilder) is unaffected.
 */
class OutreachCorrection1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Business Outreach campaign does not create AI-prospecting rows
    // -----------------------------------------------------------------

    public function test_business_scoped_campaign_builder_never_creates_ai_prospecting_rows(): void
    {
        [$tenant, $business, $fixture] = $this->sendableTenant();
        $this->actingAs($tenant->user);

        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);
        Contacts::create(['customer_id' => $tenant->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '14155552671', 'status' => 'subscribe']);
        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $business->id, 'sender_id' => 'TESTSENDER', 'status' => 'active']);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Business Scoped Blast',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['TESTSENDER'],
            'plan_id' => $fixture['plan']->id,
            'business_id' => $business->id,
            'user_id' => $tenant->user_id,
        ]);

        $campaign = Campaigns::where('campaign_name', 'Business Scoped Blast')->first();
        $this->assertNotNull($campaign);
        $this->assertSame($business->id, $campaign->business_id);
        // ai_box_campaign_map has no migration in this codebase (a
        // pre-existing gap in the untouched legacy hook, out of scope to
        // fix here) — chat_boxes alone is enough to prove the whole
        // AI-prospecting block was skipped: it does exist, and the
        // legacy-path regression test below proves the hook does insert
        // into it when NOT gated.
        $this->assertSame(0, DB::table('chat_boxes')->count(), 'A Business-scoped campaign must never create AI-prospecting chat boxes.');
    }

    // -----------------------------------------------------------------
    // 11. legacy Campaign Builder callers retain previous AI-prospecting
    // semantics unchanged (no explicit Business context)
    // -----------------------------------------------------------------

    /**
     * Rewritten once Lane E supplied the schema this hook always needed.
     *
     * The previous version of this test treated a crash as success: with
     * `chat_boxes.ai_stage` and `ai_box_campaign_map` missing, campaignBuilder
     * threw, and the test asserted the throw. That encoded a schema gap as
     * intended behaviour. With Lane E's migration merged the legacy path
     * completes, so this asserts what it was always meant to assert — and
     * additionally pins the UUID writer correction that ships alongside it.
     */
    public function test_legacy_campaign_builder_creates_ai_prospecting_rows_with_valid_unique_uuids(): void
    {
        [$tenant, , $fixture] = $this->sendableTenant();
        $this->actingAs($tenant->user);

        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'name' => 'VIPs Legacy', 'status' => true]);

        // Two contacts, so "separate rows get different uuids" is a real
        // assertion rather than a vacuous one.
        foreach (['14155552672', '14155552673'] as $phone) {
            Contacts::create(['customer_id' => $tenant->user_id, 'group_id' => $group->id, 'phone' => $phone, 'status' => 'subscribe']);
        }

        Senderid::create(['user_id' => $tenant->user_id, 'sender_id' => 'LEGACYSENDER', 'status' => 'active']);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Legacy Blast',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['LEGACYSENDER'],
            'plan_id' => $fixture['plan']->id,
        ]);

        // 1. It completes, rather than throwing on missing schema.
        $this->assertSame('success', $result->getData()->status, (string) ($result->getData()->message ?? ''));

        $campaign = Campaigns::where('campaign_name', 'Legacy Blast')->first();
        $this->assertNotNull($campaign);

        // 2. The AI-prospecting rows the legacy hook exists to create.
        $boxes = DB::table('chat_boxes')->orderBy('id')->get();
        $this->assertCount(2, $boxes, 'The legacy hook creates one chat box per subscribed contact.');

        foreach ($boxes as $box) {
            $this->assertSame(1, (int) $box->ai_stage, 'Stage 1 is what makes these rows AI-prospecting rows.');

            // 3. A genuine UUID — present, non-empty, well-formed. Before the
            //    writer correction this column held '' on every row: a
            //    non-strict MySQL connection coerced the missing NOT NULL
            //    value instead of rejecting it.
            $this->assertNotNull($box->uid);
            $this->assertNotSame('', $box->uid);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                (string) $box->uid,
                'chat_boxes.uid must be a real UUID, not an empty string.',
            );
            $this->assertTrue(Str::isUuid((string) $box->uid));
        }

        // 4. Separately created rows receive DIFFERENT uuids.
        $uids = $boxes->pluck('uid')->all();
        $this->assertCount(2, array_unique($uids), 'Each chat box must receive its own uuid.');

        // 5. The campaign mapping is created, and points at exactly these boxes.
        $mapped = array_map('intval', DB::table('ai_box_campaign_map')->where('campaign_id', $campaign->id)->pluck('box_id')->all());
        $expected = array_map('intval', $boxes->pluck('id')->all());
        sort($mapped);
        sort($expected);
        $this->assertSame($expected, $mapped);

        // 6. Ownership stays with the acting tenant.
        foreach ($boxes as $box) {
            $this->assertSame((int) $tenant->user_id, (int) $box->user_id);
        }
        $this->assertSame((int) $tenant->user_id, (int) $campaign->user_id);
    }

    public function test_the_legacy_hook_creates_no_row_for_another_tenant(): void
    {
        [$tenant, , $fixture] = $this->sendableTenant();

        // A second, entirely separate tenant with its own group and contact.
        $other = $this->createCustomer();
        $otherGroup = ContactGroups::create(['customer_id' => $other->user_id, 'name' => 'Other Tenant', 'status' => true]);
        Contacts::create(['customer_id' => $other->user_id, 'group_id' => $otherGroup->id, 'phone' => '14155559001', 'status' => 'subscribe']);

        $this->actingAs($tenant->user);

        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'name' => 'Mine', 'status' => true]);
        Contacts::create(['customer_id' => $tenant->user_id, 'group_id' => $group->id, 'phone' => '14155559002', 'status' => 'subscribe']);
        Senderid::create(['user_id' => $tenant->user_id, 'sender_id' => 'LEGACYSENDER', 'status' => 'active']);

        app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Mine Only',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['LEGACYSENDER'],
            'plan_id' => $fixture['plan']->id,
        ]);

        $this->assertSame(
            0,
            DB::table('chat_boxes')->where('user_id', $other->user_id)->count(),
            'The legacy hook must never create a row belonging to another tenant.',
        );
        $this->assertSame(1, DB::table('chat_boxes')->count());
        $this->assertSame((int) $tenant->user_id, (int) DB::table('chat_boxes')->first()->user_id);
    }

    public function test_a_foreign_contact_group_is_refused_and_writes_nothing(): void
    {
        [$tenant, , $fixture] = $this->sendableTenant();

        $other = $this->createCustomer();
        $foreignGroup = ContactGroups::create(['customer_id' => $other->user_id, 'name' => 'Not Yours', 'status' => true]);
        Contacts::create(['customer_id' => $other->user_id, 'group_id' => $foreignGroup->id, 'phone' => '14155559003', 'status' => 'subscribe']);

        $this->actingAs($tenant->user);
        Senderid::create(['user_id' => $tenant->user_id, 'sender_id' => 'LEGACYSENDER', 'status' => 'active']);

        // Naming another tenant's contact group in the request body must be
        // refused, and must leave no campaign and no AI-prospecting row.
        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Cross Tenant Attempt',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$foreignGroup->id],
            'originator' => 'sender_id',
            'sender_id' => ['LEGACYSENDER'],
            'plan_id' => $fixture['plan']->id,
        ]);

        $this->assertSame('error', $result->getData()->status);
        $this->assertDatabaseMissing('campaigns', ['campaign_name' => 'Cross Tenant Attempt']);
        $this->assertSame(0, DB::table('chat_boxes')->count());
        $this->assertSame(0, DB::table('ai_box_campaign_map')->count());
    }
    // -----------------------------------------------------------------
    // 2 & 3. Business create-template stays in the explicit Business,
    // and keeps the Business owner's legacy user_id even for staff.
    // -----------------------------------------------------------------

    public function test_staff_create_template_lands_in_the_selected_business_with_the_owners_legacy_user_id(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes(['name' => 'Business B']));
        $fixture = $this->givePlanAndSubscription($ownerCustomer);
        $ownerCustomer->user->sms_unit = 1000;
        $ownerCustomer->user->save();

        $staffCustomer = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $businessB->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        $group = ContactGroups::create(['customer_id' => $ownerCustomer->user_id, 'business_id' => $businessB->id, 'name' => 'VIPs', 'status' => true]);
        Contacts::create(['customer_id' => $ownerCustomer->user_id, 'business_id' => $businessB->id, 'group_id' => $group->id, 'phone' => '14155552673', 'status' => 'subscribe']);
        Senderid::create(['user_id' => $ownerCustomer->user_id, 'business_id' => $businessB->id, 'sender_id' => 'STAFFSENDER', 'status' => 'active']);

        app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Staff Template Blast',
            'message' => 'Hello VIPs',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['STAFFSENDER'],
            'plan_id' => $fixture['plan']->id,
            'business_id' => $businessB->id,
            'user_id' => $ownerCustomer->user_id,
            'advanced' => 'true',
            'create_template' => 'true',
        ]);

        $campaign = Campaigns::where('campaign_name', 'Staff Template Blast')->first();
        $this->assertNotNull($campaign);
        $this->assertSame($businessB->id, $campaign->business_id);
        $this->assertSame($ownerCustomer->user_id, $campaign->user_id);

        $template = Templates::where('name', 'Staff Template Blast')->first();
        $this->assertNotNull($template);
        $this->assertSame($businessB->id, $template->business_id, 'The template must land in Business B, never Business A.');
        $this->assertSame($ownerCustomer->user_id, $template->user_id, 'The template must keep the Business owner as its legacy user_id.');
        $this->assertNotSame($businessA->id, $template->business_id);
        $this->assertNotSame($staffCustomer->user_id, $template->user_id);
    }

    // -----------------------------------------------------------------
    // 4. sendApi() legacy path has no undefined Business-context
    // regression (accidental $businessId over-application, restored).
    // -----------------------------------------------------------------

    public function test_send_api_legacy_path_has_no_undefined_business_context_regression(): void
    {
        [$tenant] = $this->sendableTenant();
        $tenant->user->api_token = 'test-api-token-' . uniqid();
        $tenant->user->sms_unit = 1000;
        $tenant->user->save();
        Senderid::create(['user_id' => $tenant->user_id, 'sender_id' => 'APISENDER', 'status' => 'active']);

        // Any PHP warning/notice (e.g. "Undefined variable $businessId")
        // is converted into a thrown exception by phpunit.xml's
        // convertWarningsToExceptions/convertNoticesToExceptions — so
        // simply reaching a JSON response here, rather than an exception,
        // proves sendApi() no longer references an undefined $businessId.
        $result = app(CampaignRepository::class)->sendApi(new Campaigns(), [
            'api_key' => $tenant->user->api_token,
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => 'APISENDER',
            'message' => 'Hello via API',
            'recipient' => '4155552671',
        ]);

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $result);
    }

    // -----------------------------------------------------------------
    // 5. Staff restart() uses the campaign/Business owner's legacy
    // balance, not the acting Workspace member's.
    // -----------------------------------------------------------------

    public function test_staff_restart_uses_the_business_owners_balance_not_the_actors(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes());
        $this->givePlanAndSubscription($ownerCustomer);
        $ownerCustomer->user->sms_unit = 1000;
        $ownerCustomer->user->save();

        $staffCustomer = $this->createCustomer();
        $staffCustomer->user->sms_unit = 0;
        $staffCustomer->user->save();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        $campaign = Campaigns::create([
            'user_id' => $ownerCustomer->user_id,
            'business_id' => $business->id,
            'campaign_name' => 'Restartable',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_PAUSED,
        ]);

        $this->actingAs($staffCustomer->user);

        $response = app(CampaignRepository::class)->restart($campaign);

        $this->assertSame('success', $response->getData()->status, 'restart() must evaluate the Business owner\'s balance (1000), not the acting staff\'s (0).');
    }

    // -----------------------------------------------------------------
    // 6. Staff resend() cleans up the campaign's own Business's failed
    // logs/reports, never rows scoped to the acting Workspace member.
    // -----------------------------------------------------------------

    public function test_staff_resend_cleans_the_campaigns_own_business_failed_rows(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes());

        $staffCustomer = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        $campaign = Campaigns::create([
            'user_id' => $ownerCustomer->user_id,
            'business_id' => $business->id,
            'campaign_name' => 'Resendable',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ]);

        $server = SendingServer::create(['name' => 'Test Gateway', 'settings' => 'twilio', 'status' => true, 'plain' => true]);
        $group = ContactGroups::create(['customer_id' => $ownerCustomer->user_id, 'business_id' => $business->id, 'name' => 'Resend Group', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $ownerCustomer->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '4155550000', 'status' => 'subscribe']);

        $failedTrackingLog = TrackingLog::create([
            'customer_id' => $ownerCustomer->user_id,
            'business_id' => $business->id,
            'campaign_id' => $campaign->id,
            'sending_server_id' => $server->id,
            'contact_id' => $contact->id,
            'contact_group_id' => $group->id,
            'status' => 'Failed',
        ]);

        $failedReport = Reports::create([
            'user_id' => $ownerCustomer->user_id,
            'business_id' => $business->id,
            'campaign_id' => $campaign->id,
            'from' => 'TEST',
            'to' => '4155550000',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => 'Failed',
        ]);

        $this->actingAs($staffCustomer->user);

        app(CampaignRepository::class)->resend($campaign);

        $this->assertDatabaseMissing('tracking_logs', ['id' => $failedTrackingLog->id]);
        $this->assertDatabaseMissing('reports', ['id' => $failedReport->id]);
    }

    // -----------------------------------------------------------------
    // 7. Cross-Business phone-number tamper rejected in Quick Send
    // (the newly-fixed view_numbers / sender_id_verification != 'yes'
    // branch of checkQuickSendValidation()).
    // -----------------------------------------------------------------

    public function test_cross_business_phone_number_tamper_rejected_in_quick_send(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'number' => '15550002222', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        $this->authenticateAsCustomer($tenant, ['sms_quick_send', 'view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$businessA->workspace->uid, $businessA->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Hello there',
            'sms_type' => 'plain',
            'country_code' => $fixture['country']->id,
            'originator' => 'phone_number',
            'phone_number' => '15550002222',
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessA->uid]));
        $response->assertSessionHas('status', 'error');
        $this->assertDatabaseMissing('campaigns', ['business_id' => $businessA->id]);
    }

    // -----------------------------------------------------------------
    // 8. Cross-Business SenderID tamper rejected in Quick Send.
    // -----------------------------------------------------------------

    public function test_cross_business_sender_id_tamper_rejected_in_quick_send(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant();
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sender_id' => 'ONLY_B', 'status' => 'active']);

        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$businessA->workspace->uid, $businessA->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Hello there',
            'sms_type' => 'plain',
            'country_code' => $fixture['country']->id,
            'originator' => 'sender_id',
            'sender_id' => 'ONLY_B',
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessA->uid]));
        $response->assertSessionHas('status', 'error');
        $this->assertDatabaseMissing('campaigns', ['business_id' => $businessA->id]);
    }

    // -----------------------------------------------------------------
    // 9. Cross-Business sending-server tamper rejected in Quick Send.
    // -----------------------------------------------------------------

    public function test_cross_business_sending_server_tamper_rejected_in_quick_send(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant();
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $server = SendingServer::create(['name' => 'Test Gateway', 'settings' => 'twilio', 'status' => true, 'plain' => true]);
        CustomerBasedSendingServer::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sending_server' => $server->id, 'status' => 1]);
        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'sender_id' => 'A_SENDER', 'status' => 'active']);

        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$businessA->workspace->uid, $businessA->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Hello there',
            'sms_type' => 'plain',
            'country_code' => $fixture['country']->id,
            'originator' => 'sender_id',
            'sender_id' => 'A_SENDER',
            'sending_server' => $server->id,
        ]);

        // quickSend()'s sending_server assignment check rejects per
        // recipient (not up front like checkQuickSendValidation()), so a
        // rejected tamper attempt surfaces as the existing partial-failure
        // "warning" path rather than the upfront "error" path — the
        // important, tested invariant is that the campaign never gets
        // created for the tampered Business.
        $response->assertRedirect(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessA->uid]));
        $response->assertSessionHas('status', 'warning');
        $this->assertDatabaseMissing('campaigns', ['business_id' => $businessA->id]);
    }

    // -----------------------------------------------------------------
    // 10. Cross-Business sending-server tamper rejected in Campaign
    // Builder.
    // -----------------------------------------------------------------

    public function test_cross_business_sending_server_tamper_rejected_in_campaign_builder(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant();
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $server = SendingServer::create(['name' => 'Test Gateway', 'settings' => 'twilio', 'status' => true, 'plain' => true]);
        CustomerBasedSendingServer::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sending_server' => $server->id, 'status' => 1]);
        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'sender_id' => 'A_SENDER', 'status' => 'active']);
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessA->id, 'name' => 'A Group', 'status' => true]);

        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [$businessA->workspace->uid, $businessA->uid]), [
            'name' => 'Tamper Attempt',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'message' => 'Hello',
            'originator' => 'sender_id',
            'sender_id' => ['A_SENDER'],
            'sending_server' => $server->id,
        ]);

        $response->assertSessionHas('status', 'error');
        $this->assertDatabaseMissing('campaigns', ['campaign_name' => 'Tamper Attempt']);
    }

    // -----------------------------------------------------------------
    // Audit finding — forged business_id / user_id tenant escape
    // -----------------------------------------------------------------

    /**
     * The defect: every method in CampaignController forwarded
     * `$request->except('_token', ...)` straight into the campaign
     * repository, which reads `business_id` and `user_id` out of that array
     * as TENANCY AUTHORITY — they choose the sending server, the sender id,
     * the balance debited, the blacklist scope, the contact groups, the
     * campaign's owner, and whether managed transport is used.
     *
     * A customer could therefore name another tenant's Business and user and
     * send on their account, at their cost.
     *
     * These tests authenticate as Tenant A and submit Tenant B's ids through
     * the real HTTP entry points, and then repeat the attack directly
     * against the repository so that bypassing the controller cannot restore
     * it.
     *
     * @return array{0: Customer, 1: Customer, 2: Business, 3: array}
     */
    private function twoTenants(): array
    {
        [$attacker, , $fixture] = $this->sendableTenant();
        [$victim, $victimBusiness] = $this->sendableTenant();

        // A real sender id and a real contact group belonging to the VICTIM,
        // so the attack would genuinely succeed if the guard were absent.
        Senderid::create([
            'user_id' => $victim->user_id,
            'business_id' => $victimBusiness->id,
            'sender_id' => 'VICTIMSENDER',
            'status' => 'active',
        ]);

        $victimGroup = ContactGroups::create([
            'customer_id' => $victim->user_id,
            'business_id' => $victimBusiness->id,
            'name' => 'Victim Group',
            'status' => true,
        ]);

        Contacts::create([
            'customer_id' => $victim->user_id,
            'business_id' => $victimBusiness->id,
            'group_id' => $victimGroup->id,
            'phone' => '14155558801',
            'status' => 'subscribe',
        ]);

        return [$attacker, $victim, $victimBusiness, ['fixture' => $fixture, 'group' => $victimGroup]];
    }

    private function assertVictimUntouched(Customer $victim, Business $victimBusiness): void
    {
        $this->assertSame(0, Campaigns::where('business_id', $victimBusiness->id)->count(), 'No campaign on the victim.');
        $this->assertSame(0, DB::table('reports')->where('user_id', $victim->user_id)->count(), 'No report on the victim.');
        $this->assertSame(0, DB::table('chat_boxes')->where('user_id', $victim->user_id)->count(), 'No chat box on the victim.');
        $this->assertSame(0, DB::table('business_messaging_operations')->count(), 'No managed operation at all.');
        $this->assertSame(0, DB::table('business_usage_measurements')->count(), 'No measurement at all.');
        $this->assertSame(0, DB::table('tracking_logs')->count(), 'No tracking log at all.');
    }

    public function test_the_campaign_builder_route_ignores_a_forged_business_id_and_user_id(): void
    {
        [$attacker, $victim, $victimBusiness, $extra] = $this->twoTenants();

        $balanceBefore = $victim->user->fresh()->sms_unit;

        $this->authenticateAsCustomer($attacker, ['sms_campaign_builder']);

        $this->post('/sms/campaign-builder', [
            'name' => 'Forged Campaign',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$extra['group']->id],
            'originator' => 'sender_id',
            'sender_id' => ['VICTIMSENDER'],
            'plan_id' => $extra['fixture']['plan']->id,
            // The attack.
            'business_id' => $victimBusiness->id,
            'user_id' => $victim->user_id,
        ]);

        // The campaign is not created against the victim, and nothing of the
        // victim's is used or charged.
        $this->assertVictimUntouched($victim, $victimBusiness);
        $this->assertSame($balanceBefore, $victim->user->fresh()->sms_unit, 'The victim was not billed.');
    }

    public function test_the_quick_send_route_ignores_a_forged_business_id_and_user_id(): void
    {
        [$attacker, $victim, $victimBusiness] = $this->twoTenants();

        $balanceBefore = $victim->user->fresh()->sms_unit;

        $this->authenticateAsCustomer($attacker, ['send_quick_sms']);

        $this->post('/sms/quick-send', [
            'recipient' => '14155558802',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => 'VICTIMSENDER',
            'business_id' => $victimBusiness->id,
            'user_id' => $victim->user_id,
        ]);

        $this->assertVictimUntouched($victim, $victimBusiness);
        $this->assertSame($balanceBefore, $victim->user->fresh()->sms_unit, 'The victim was not billed.');
    }

    /**
     * The controller strips the keys; this proves the repository refuses
     * them even when they arrive anyway, so a future refactor of the
     * controller cannot silently restore the vulnerability.
     */
    public function test_the_repository_itself_refuses_a_business_outside_the_actors_authority(): void
    {
        [$attacker, $victim, $victimBusiness, $extra] = $this->twoTenants();

        $this->actingAs($attacker->user);

        $refused = false;
        try {
            app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
                'name' => 'Direct Forgery',
                'message' => 'Hello',
                'sms_type' => 'plain',
                'contact_groups' => [$extra['group']->id],
                'originator' => 'sender_id',
                'sender_id' => ['VICTIMSENDER'],
                'plan_id' => $extra['fixture']['plan']->id,
                'business_id' => $victimBusiness->id,
                'user_id' => $victim->user_id,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The repository must fail closed on a Business outside the actor\'s authority.');
        $this->assertVictimUntouched($victim, $victimBusiness);
    }

    public function test_the_repository_refuses_a_user_id_that_is_not_the_supplied_businesss_owner(): void
    {
        [$attacker, , $attackerBusiness] = $this->twoTenants();
        $stranger = $this->createCustomer();

        $this->actingAs($attacker->user);

        // The Business IS the actor's own — only the user_id is forged, to
        // redirect balance and ownership onto a third party.
        $ownBusiness = $this->createBusinessWithWorkspace($attacker, $this->businessAttributes(['name' => 'Mine']));

        $refused = false;
        try {
            app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
                'name' => 'Owner Forgery',
                'message' => 'Hello',
                'sms_type' => 'plain',
                'contact_groups' => [],
                'business_id' => $ownBusiness->id,
                'user_id' => $stranger->user_id,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A supplied user_id may only ever be the supplied Business\'s own owner.');
    }

    public function test_a_legitimate_outreach_request_still_works_after_the_guard(): void
    {
        // The positive control. The Outreach controller legitimately sets
        // both keys AFTER resolving the Business through the RFC-003 §14.1
        // boundary, passing the Business OWNER's user_id while the actor may
        // be a staff member — so the guard must admit exactly that shape.
        [$tenant, $business, $fixture] = $this->sendableTenant();

        $group = ContactGroups::create([
            'customer_id' => $tenant->user_id,
            'business_id' => $business->id,
            'name' => 'Legit Group',
            'status' => true,
        ]);
        Contacts::create([
            'customer_id' => $tenant->user_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => '14155558803',
            'status' => 'subscribe',
        ]);
        Senderid::create([
            'user_id' => $tenant->user_id,
            'business_id' => $business->id,
            'sender_id' => 'LEGITSENDER',
            'status' => 'active',
        ]);

        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);

        $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [$business->workspace->uid, $business->uid]), [
            'name' => 'Legitimate Outreach',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['LEGITSENDER'],
            'plan_id' => $fixture['plan']->id,
        ]);

        $this->assertNotNull(
            Campaigns::where('campaign_name', 'Legitimate Outreach')->first(),
            'The guard must not break the legitimate Outreach path it was modelled on.',
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business, 2: array{country: Country, plan: Plan}}
     */
    private function sendableTenant(array $planOptionOverrides = []): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        $fixture = $this->givePlanAndSubscription($tenant, $planOptionOverrides);
        $tenant->user->sms_unit = 1000;
        $tenant->user->save();

        return [$tenant, $business, $fixture];
    }

    /**
     * @return array{country: Country, plan: Plan}
     */
    private function givePlanAndSubscription(Customer $customer, array $planOptionOverrides = []): array
    {
        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Correction 1 Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode($planOptionOverrides),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        return ['country' => $country, 'plan' => $plan];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
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
}
