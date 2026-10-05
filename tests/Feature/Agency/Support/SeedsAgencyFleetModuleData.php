<?php

namespace Tests\Feature\Agency\Support;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Calendar\AppointmentStatus;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\Website\WebsiteStatus;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Library\Documents\DocumentManager;
use App\Library\Forms\FormManager;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessLocation;
use App\Models\BusinessMetaConnection;
use App\Models\BookingType;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsCampaign;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agency fleet tenant-isolation fixtures — one visible record per Business
 * module, each carrying a unique text MARKER, plus the GET "probe" pages whose
 * rendered HTML lists or shows that record.
 *
 * The integrated isolation test seeds a distinct marker into each Business of
 * the fleet (a client, a sibling client, the Agency's own Business, another
 * Agency's client) and then, while an Agency owner is "viewing as" one client,
 * crawls every probe and asserts that only THAT client's marker is ever
 * rendered.
 *
 * Every row is created through the same seam the owning module's own tests use
 * (services/managers where the repo's fixtures do, plain Eloquent otherwise).
 * Nothing here makes an HTTP call or touches a provider: Google Ads / Meta Ads
 * rows are the normalised mirror rows a sync would have stored.
 *
 * The consuming test class must also use
 * Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures (tenant(),
 * createAgencyManagedClient(), authenticateAs()). Helper names are prefixed
 * `fleet` so they cannot collide with the many module fixture traits.
 *
 * ENTITLEMENT. All thirteen module pages are Available platform features and
 * are packaged in the Growth and Agency tiers (Core lacks SEO, Google Ads and
 * Meta Ads). A Business whose Workspace has no assigned plan 404s on the gated
 * modules — an Agency-managed client's Workspace needs a plan like any other.
 */
trait SeedsAgencyFleetModuleData
{
    private int $agencyFleetSequence = 1000;

    /**
     * @return array<string, array<string, mixed>> module key => ['model' => Model, ...extra uids]
     *
     * Keys: locations, contacts, crm, conversations, calendar, forms, website,
     * seo, google_ads, meta_ads, catalog, documents, automations.
     */
    protected function seedBusinessModuleData(Business $business, Workspace $workspace, string $marker): array
    {
        $actor = User::query()->findOrFail((int) $business->customer_id);
        $actorId = (int) $actor->id;
        $seeded = [];

        // ---- Location (calendar, documents and contacts hang off it) ----------
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $marker . '-Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
        $seeded['locations'] = ['model' => $location, 'uid' => $location->uid, 'marker' => $marker . '-Location'];

        // ---- Contacts (people) -------------------------------------------------
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $marker . '-Group',
            'status' => true,
        ]);

        $phone = '1415555' . str_pad((string) (++$this->agencyFleetSequence), 4, '0', STR_PAD_LEFT);
        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        // location_id is not mass-assignable; documents and appointments
        // require the contact to sit at the Location they are created for.
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);

        foreach (['FIRST_NAME' => $marker . '-Contact', 'LAST_NAME' => 'Fixture', 'EMAIL' => strtolower($marker) . '-contact@example.test'] as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        $contact = $contact->fresh();
        $seeded['contacts'] = [
            'model' => $contact,
            'uid' => $contact->uid,
            'group' => $group,
            'group_uid' => $group->uid,
            'phone' => $phone,
            'marker' => $marker . '-Contact',
        ];

        // ---- CRM opportunities: pipeline + stages + opportunity ----------------
        $pipelines = app(CrmPipelineService::class);
        $pipeline = $pipelines->setUpStandardPipeline($business, $actorId);
        $pipelines->renamePipeline($pipeline, $marker . '-Pipeline');
        $pipeline = $pipeline->fresh();

        $opportunity = app(CrmOpportunityService::class)->create(
            $business,
            $pipeline,
            $contact,
            $marker . '-Opportunity',
            250000,
            null,
            $actorId,
        );
        $seeded['crm'] = [
            'model' => $opportunity,
            'uid' => $opportunity->uid,
            'pipeline' => $pipeline,
            'pipeline_uid' => $pipeline->uid,
            'marker' => $marker . '-Opportunity',
        ];

        // ---- Conversations: a pinned thread, so the inbox renders it server-side
        $box = new ChatBox([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => '18005550100',
            'to' => $phone,
            'reply_by_customer' => true,
            'pinned' => true,
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();
        ChatBoxMessage::create([
            'box_id' => $box->id,
            'message' => $marker . '-Conversation hello from the fleet fixture',
            'direction' => 'incoming',
        ]);
        $seeded['conversations'] = ['model' => $box->fresh(), 'uid' => $box->uid, 'marker' => $marker . '-Conversation'];

        // ---- Calendar: booking type + appointment ------------------------------
        $bookingType = BookingType::create([
            'business_location_id' => $location->id,
            'name' => $marker . '-BookingType',
            'duration_minutes' => 30,
            'is_active' => true,
            'created_by_user_id' => $actorId,
        ]);
        $appointment = Appointment::create([
            'business_location_id' => $location->id,
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $actorId,
            'contact_id' => $contact->id,
            'created_by_user_id' => $actorId,
            'status' => AppointmentStatus::Scheduled,
            'start_at' => now()->addDay()->setTime(15, 0),
            'end_at' => now()->addDay()->setTime(15, 30),
            'reschedule_count' => 0,
        ]);
        $seeded['calendar'] = [
            'model' => $bookingType,
            'uid' => $bookingType->uid,
            'booking_type' => $bookingType,
            'booking_type_uid' => $bookingType->uid,
            'appointment' => $appointment,
            'appointment_uid' => $appointment->uid,
            'location_uid' => $location->uid,
            'marker' => $marker . '-BookingType',
        ];

        // ---- Forms ---------------------------------------------------------------
        $form = app(FormManager::class)->create($business, [
            'name' => $marker . '-Form',
            'intro' => 'Tell us about your event.',
            'submit_label' => 'Send',
            'success_message' => 'Thanks.',
            'create_opportunity' => false,
            'fields' => [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true],
            ],
        ], $actorId);
        $seeded['forms'] = ['model' => $form, 'uid' => $form->uid, 'marker' => $marker . '-Form'];

        // ---- Website + a page ----------------------------------------------------
        $website = Website::create([
            'business_id' => $business->id,
            'name' => $marker . '-Website',
            'status' => WebsiteStatus::Draft,
        ]);
        $page = WebsitePage::create([
            'website_id' => $website->id,
            'title' => $marker . '-Page',
            'slug' => null,
            'is_home' => true,
            'sections' => [['type' => 'text', 'data' => ['heading' => $marker . '-Page', 'body' => 'Fixture page body.']]],
            'seo_title' => null,
            'meta_description' => null,
            'noindex' => false,
            'sort_order' => 0,
        ]);
        $seeded['website'] = [
            'model' => $website,
            'uid' => $website->uid,
            'page' => $page,
            'page_uid' => $page->uid,
            'marker' => $marker . '-Page',
        ];

        // ---- SEO keyword ---------------------------------------------------------
        $keyword = app(SeoKeywordManager::class)->create($actorId, $business, $marker . '-Keyword');
        $seeded['seo'] = ['model' => $keyword, 'uid' => $keyword->uid, 'marker' => $marker . '-Keyword'];

        // ---- Google Ads: connection + selected account + campaign -----------------
        $googleConnection = BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::GoogleAds,
            'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'plain-ads-refresh-token',
            'granted_scopes' => GoogleConnectionProduct::GoogleAds->scope(),
            'google_account_email' => 'owner@example.test',
            'connected_at' => now(),
            'connected_by_user_id' => $actorId,
        ]);
        $googleAccount = GoogleAdsAccount::create([
            'business_id' => $business->id,
            'business_google_connection_id' => $googleConnection->id,
            'customer_id' => '77' . str_pad((string) $business->id, 8, '0', STR_PAD_LEFT),
            'descriptive_name' => $marker . '-GoogleAccount',
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
            'last_successful_sync_at' => now()->subHour(),
            'data_through_date' => now()->subDay()->format('Y-m-d'),
        ]);
        $googleCampaign = GoogleAdsCampaign::create([
            'business_id' => $business->id,
            'google_ads_account_id' => $googleAccount->id,
            'external_campaign_id' => '88' . str_pad((string) $business->id, 8, '0', STR_PAD_LEFT),
            'name' => $marker . '-GoogleCampaign',
            'status' => GoogleAdsEntityStatus::Enabled,
            'channel_type' => 'SEARCH',
            'budget_amount_micros' => 30_000_000,
            'budget_shared' => false,
            'last_synced_at' => now(),
        ]);
        $seeded['google_ads'] = [
            'model' => $googleCampaign,
            'uid' => $googleCampaign->uid,
            'account' => $googleAccount,
            'marker' => $marker . '-GoogleCampaign',
        ];

        // ---- Meta Ads: connection + selected account + campaign -------------------
        $metaConnection = BusinessMetaConnection::create([
            'business_id' => $business->id,
            'state' => MetaConnectionState::Active,
            'access_token_encrypted' => 'plain-meta-access-token',
            'token_expires_at' => now()->addDays(50),
            'granted_scopes' => 'ads_read,ads_management',
            'meta_user_id' => 'meta-user-' . $business->id,
            'meta_user_name' => 'Owner',
            'connected_at' => now(),
            'last_verified_at' => now(),
            'connected_by_user_id' => $actorId,
        ]);
        $metaAccount = MetaAdsAccount::create([
            'business_id' => $business->id,
            'business_meta_connection_id' => $metaConnection->id,
            'ad_account_id' => (string) (1000000000 + $business->id),
            'name' => $marker . '-MetaAccount',
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'account_status' => 1,
            'result_action_type' => 'onsite_conversion.lead_grouped',
            'selected_at' => now(),
            'selected_by_user_id' => $actorId,
            'selected_meta_user_id' => $metaConnection->meta_user_id,
        ]);
        $metaCampaign = MetaAdsCampaign::create([
            'business_id' => $business->id,
            'meta_ads_account_id' => $metaAccount->id,
            'external_campaign_id' => 'camp-' . $metaAccount->id . '-1',
            'name' => $marker . '-MetaCampaign',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
            'daily_budget_minor' => 4000,
            'lifetime_budget_minor' => null,
        ]);
        $seeded['meta_ads'] = [
            'model' => $metaCampaign,
            'uid' => $metaCampaign->uid,
            'account' => $metaAccount,
            'marker' => $marker . '-MetaCampaign',
        ];

        // ---- Packages / Catalog --------------------------------------------------
        $item = app(CatalogItemManager::class)->create($business, [
            'type' => 'package',
            'name' => $marker . '-Package',
            'description' => null,
            'price_minor' => 100000,
            'currency_code' => 'USD',
        ], $actorId);
        $seeded['catalog'] = ['model' => $item, 'uid' => $item->uid, 'marker' => $marker . '-Package'];

        // ---- Payments / Contracts: a proposal -------------------------------------
        $document = app(DocumentManager::class)->create(
            $business,
            $location,
            $contact,
            null,
            'proposal',
            $marker . '-Proposal',
            $actor,
        );
        $seeded['documents'] = ['model' => $document, 'uid' => $document->uid, 'marker' => $marker . '-Proposal'];

        // ---- Automations: a workflow with its starter draft -----------------------
        $workflow = app(WorkflowDraftService::class)->createWorkflowWithDraft(
            $business,
            $marker . '-Workflow',
            WorkflowTriggerType::ManualEnrollment,
            $actorId,
        );
        $seeded['automations'] = ['model' => $workflow, 'uid' => $workflow->uid, 'marker' => $marker . '-Workflow'];

        return $seeded;
    }

    /**
     * The GET pages of $business whose rendered HTML lists or shows the record
     * seedBusinessModuleData() created for it. `params` is ready for
     * route($probe['route'], $probe['params']) and always carries workspaceUid
     * and businessUid; 'detail' probes add the seeded record's uid under the
     * route's own parameter name.
     *
     * Each probe also carries `marker`: the exact text its page is expected to
     * render when `expectsMarker` is true (a module's own record, so a page that
     * merely lists some OTHER module's record does not count as proof). A probe
     * with expectsMarker=false is a page that renders no seeded text (its data
     * loads by script, or it lists nothing seeded) but must still never render
     * another tenant's marker.
     *
     * @param  array<string, array<string, mixed>>  $seeded  seedBusinessModuleData()'s return value
     * @return array<int, array{module: string, route: string, params: array<string, string>, kind: string, expectsMarker: bool, marker: string}>
     */
    protected function moduleProbeRoutes(Workspace $workspace, Business $business, array $seeded): array
    {
        $scope = ['workspaceUid' => $workspace->uid, 'businessUid' => $business->uid];
        $probes = [];

        $add = function (string $module, string $name, string $kind, array $extra = [], bool $expectsMarker = true, ?string $marker = null) use (&$probes, $scope, $seeded): void {
            $probes[] = [
                'module' => $module,
                'route' => 'customer.workspaces.businesses.' . $name,
                'params' => $scope + $extra,
                'kind' => $kind,
                'expectsMarker' => $expectsMarker,
                'marker' => $marker ?? (string) ($seeded[$module]['marker'] ?? ''),
            ];
        };

        $base = Str::beforeLast((string) $seeded['locations']['marker'], '-Location');
        $location = $seeded['locations']['uid'];
        $add('locations', 'locations.index', 'index');
        $add('locations', 'locations.edit', 'detail', ['locationUid' => $location]);

        $add('contacts', 'people.index', 'index');
        $add('contacts', 'people.show', 'detail', ['contactUid' => $seeded['contacts']['uid']]);
        // The contact-group list (legacy screen) fills its table by script, so
        // the group name is not in the server-rendered HTML.
        $add('contacts', 'contacts.index', 'index', [], false, $base . '-Group');

        $add('crm', 'crm.board', 'index');
        $add('crm', 'crm.opportunities.show', 'detail', ['opportunityUid' => $seeded['crm']['uid']]);
        $add('crm', 'crm.pipelines.settings', 'detail', ['pipelineUid' => $seeded['crm']['pipeline_uid']], true, $base . '-Pipeline');

        $add('conversations', 'conversations.index', 'index');

        $calendar = $seeded['calendar'];
        $add('calendar', 'calendar.booking-types.index', 'index', ['locationUid' => $calendar['location_uid']]);
        $add('calendar', 'calendar.booking-types.edit', 'detail', ['locationUid' => $calendar['location_uid'], 'bookingTypeUid' => $calendar['booking_type_uid']]);
        $add('calendar', 'calendar.appointments.show', 'detail', ['locationUid' => $calendar['location_uid'], 'appointmentUid' => $calendar['appointment_uid']]);

        $add('forms', 'forms.index', 'index');
        $add('forms', 'forms.edit', 'detail', ['formUid' => $seeded['forms']['uid']]);

        $add('website', 'website.pages.index', 'index');
        $add('website', 'website.pages.edit', 'detail', ['pageUid' => $seeded['website']['page_uid']]);

        $add('seo', 'seo.keywords.index', 'index');

        $add('google_ads', 'ads.campaigns.index', 'index');
        $add('google_ads', 'ads.campaigns.show', 'detail', ['campaignUid' => $seeded['google_ads']['uid']]);

        $add('meta_ads', 'ads.meta.campaigns.index', 'index');
        $add('meta_ads', 'ads.meta.campaigns.show', 'detail', ['campaignUid' => $seeded['meta_ads']['uid']]);

        $add('catalog', 'catalog.index', 'index');
        $add('catalog', 'catalog.edit', 'detail', ['catalogItemUid' => $seeded['catalog']['uid']]);

        $add('documents', 'documents.index', 'index');
        $add('documents', 'documents.show', 'detail', ['documentUid' => $seeded['documents']['uid']]);

        $add('automations', 'automations.workflows.index', 'index');
        $add('automations', 'automations.workflows.show', 'detail', ['workflowUid' => $seeded['automations']['uid']]);

        // ---- Overview / hub pages and the top-bar search (extra crawl surface) ---
        $add('search', 'search', 'index', ['q' => $base], true, $base);
        // (calendar.index is deliberately absent: with one Location it 302s to that schedule.)
        $add('calendar', 'calendar.schedule', 'index', ['locationUid' => $calendar['location_uid']]);
        $add('website', 'website.show', 'index', [], true, $base . '-Website');
        $add('seo', 'seo.index', 'index', [], false);
        $add('google_ads', 'ads.index', 'index', [], false, $base . '-GoogleAccount');
        $add('google_ads', 'ads.settings', 'index', [], true, $base . '-GoogleAccount');
        $add('meta_ads', 'ads.meta.index', 'index', [], true, $base . '-MetaAccount');
        $add('meta_ads', 'ads.meta.settings', 'index', [], true, $base . '-MetaAccount');
        $add('forms', 'forms.submissions.index', 'index', [], false);
        $add('catalog', 'catalog.locations.index', 'index', [], false);

        return $probes;
    }
}
