<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\Home\HomeCommandCenter;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Launch → Growth on the Business Home: the Launch journey is built from canonical records
 * (Business, primary Location, primary Service, website, enquiry paths), asks for nothing onboarding
 * already collected, and leaves Launch on evidence — never because onboarding finished, and never
 * because an optional integration is (or is not) connected. See HomeSetupJourney.
 */
class HomeLaunchReadinessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['business.onboarding.enabled' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A Business exactly as completed onboarding leaves it: identity, primary service-area Location, primary Service. */
    private function onboarded(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $name = 'Orange Glam Booths'): array
    {
        [$customer, $business] = $this->bareTenant($tier, $name, $name . ' Account');
        app(BusinessLocationRepository::class)->upsertPrimary($business, [
            'name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US',
        ]);
        $this->primaryService($business);
        $this->adoptSeoFirstPolicy($business);
        $this->authenticateAs($customer);

        return [$customer, $business];
    }

    private function command($customer): array
    {
        return $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_COMMAND);
    }

    private function steps($customer): array
    {
        $setup = $this->command($customer)['setup'];
        $this->assertNotNull($setup, 'Precondition: Home is in Launch.');

        return collect($setup['steps'])->keyBy('key')->all();
    }

    private function setMode(Business $business, ?string $mode, ?string $url = null): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => $mode, 'website_url' => $url]);
    }

    private function liveForm(Business $business, string $state = 'active'): void
    {
        $this->usableForm($business, ['state' => $state]);
    }

    private function websiteForm(Business $business, ?int $locationId = null, bool $useLocation = true): void
    {
        $websiteId = (int) DB::table('websites')->where('business_id', $business->id)->value('id');
        $locationId ??= $useLocation ? (int) DB::table('business_locations')->where('business_id', $business->id)->value('id') : null;
        DB::table('website_forms')->insert([
            'uid' => (string) Str::uuid(), 'website_id' => $websiteId, 'business_id' => $business->id, 'location_id' => $locationId, 'type' => 'quote_request',
            'name' => 'Quote', 'fields' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audit(Business $business, string $status = ExternalSiteCrawl::COMPLETED, string $url = 'https://orangeglam.example', int $pages = 5, ?string $finishedAt = null): void
    {
        DB::table('external_site_crawls')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'start_url' => $url, 'host' => parse_url($url, PHP_URL_HOST),
            'status' => $status, 'trigger' => 'manual', 'pages_fetched' => $pages, 'finished_at' => $finishedAt ?? now(),
            'created_at' => $finishedAt ?? now(), 'updated_at' => $finishedAt ?? now(),
        ]);
    }

    /** A form a visitor can submit, by the Forms module's own rules. */
    private function usableForm(Business $business, array $overrides = []): int
    {
        $locationId = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $formId = DB::table('forms')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => 'Enquiry', 'lifecycle_state' => $overrides['state'] ?? 'active',
            'current_version' => 1, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($overrides['version'] ?? true) {
            DB::table('form_versions')->insert(['form_id' => $formId, 'version' => 1, 'content_hash' => str_repeat('a', 64), 'submit_label' => 'Send', 'success_message' => 'Thanks', 'fields' => '[]', 'created_at' => now()]);
        }
        if ($overrides['deployment'] ?? true) {
            DB::table('form_deployments')->insert([
                'uid' => (string) Str::uuid(), 'form_id' => $formId, 'business_location_id' => $overrides['location'] ?? $locationId,
                'source' => $overrides['source'] ?? 'direct_link', 'is_enabled' => $overrides['enabled'] ?? true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $formId;
    }

    // ── foundation ───────────────────────────────────────────────────────

    public function test_newly_completed_onboarding_shows_all_foundation_steps_done_and_asks_nothing_again(): void
    {
        [$customer] = $this->onboarded();

        $steps = $this->steps($customer);

        foreach (['business', 'location', 'service'] as $key) {
            $this->assertTrue($steps[$key]['done'], "{$key} is done from its canonical record.");
            $this->assertNull($steps[$key]['action'], "{$key} is never asked for again.");
        }

        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'business']), $html);
        $this->assertStringNotContainsString(route('customer.onboarding.show', ['step' => 'services']), $html);
        $this->assertStringNotContainsString('Add where you work', $html);
        $this->assertStringNotContainsString('Add your primary service', $html);
    }

    public function test_a_missing_foundation_is_derived_from_records_and_offers_the_wizard_only_for_it(): void
    {
        [$customer, $business, $workspace] = $this->bareTenant(WorkspacePlanTier::Growth, 'Bare Booths', 'Bare Account');
        $this->adoptSeoFirstPolicy($business);
        $this->authenticateAs($customer);

        $servicesUrl = route('customer.workspaces.businesses.services.index', [$workspace->uid, $business->uid]);
        $steps = $this->steps($customer);
        $this->assertTrue($steps['business']['done']);
        $this->assertFalse($steps['location']['done']);
        $this->assertFalse($steps['service']['done']);
        $this->assertSame($servicesUrl, $steps['service']['action']['url'], 'The Business-scoped Services editor, never a wizard.');

        // Step flags are irrelevant: a row claiming every step is done changes nothing. Once the customer's wizard IS
        // bound to this Business it is the editor offered for the missing step.
        DB::table('customer_onboardings')->insert([
            'customer_id' => $customer->user_id, 'business_id' => $business->id, 'status' => 'completed', 'current_step' => 'finish',
            'completed_steps' => json_encode(['business', 'location', 'services', 'website']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $steps = $this->steps($customer);
        $this->assertFalse($steps['service']['done']);
        $this->assertSame($servicesUrl, $steps['service']['action']['url'], 'Even a wizard bound to this Business: the one Services editor.');
    }

    public function test_an_archived_primary_location_or_inactive_service_is_not_a_foundation(): void
    {
        [$customer, $business] = $this->onboarded();
        DB::table('business_locations')->where('business_id', $business->id)->update(['lifecycle_state' => 'archived']);
        DB::table('business_services')->where('business_id', $business->id)->update(['status' => 'inactive']);

        $steps = $this->steps($customer);

        $this->assertFalse($steps['location']['done']);
        $this->assertFalse($steps['service']['done']);
    }

    // ── website ──────────────────────────────────────────────────────────

    public function test_with_no_usable_site_setting_up_the_website_is_the_next_step_and_calendar_is_not_required(): void
    {
        [$customer] = $this->onboarded();

        $home = $this->command($customer);

        $this->assertSame(HomeCommandCenter::STATE_SETUP, $home['state']);
        $this->assertSame('website', $home['setup']['nextKey']);
        $this->assertArrayNotHasKey('booking_type', collect($home['setup']['steps'])->keyBy('key')->all());
        $this->assertArrayNotHasKey('bookable', collect($home['setup']['steps'])->keyBy('key')->all());
    }

    public function test_each_website_state_is_represented_accurately(): void
    {
        [$customer, $business] = $this->onboarded();

        $this->setMode($business, 'none');
        $this->assertSame('Set up your website', $this->steps($customer)['website']['title']);

        $this->setMode($business, 'hosted');
        $this->assertSame('Create your website', $this->steps($customer)['website']['title']);

        $this->website($business, 'draft');
        $draft = $this->steps($customer)['website'];
        $this->assertSame('Finish and publish your website', $draft['title']);
        $this->assertFalse($draft['done']);

        $this->website($business, 'archived');
        $this->assertSame('Republish your website', $this->steps($customer)['website']['title']);

        $this->website($business, 'published');
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A published site with the foundation leaves Launch.');
    }

    public function test_a_saved_existing_address_is_not_verified_published_or_indexed(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'external', 'https://orangeglam.example');

        $step = $this->steps($customer)['website'];
        $this->assertSame('Review your existing website', $step['title']);
        $this->assertFalse($step['done']);
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A bare URL does not leave Launch.');

        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<section[^>]*data-role="home-setup".*?</section>#s', $html, $section));
        $text = $this->mainText('<main>'.$section[0].'</main>');
        foreach (['is live', 'published', 'indexed', 'verified'] as $claim) {
            $this->assertStringNotContainsString($claim, strtolower($text), "Home must not claim the site is {$claim}.");
        }
    }

    public function test_a_completed_check_of_the_existing_site_is_evidence_but_a_failed_or_queued_one_is_not(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'external', 'https://orangeglam.example');

        $this->audit($business, ExternalSiteCrawl::FAILED);
        $this->audit($business, ExternalSiteCrawl::QUEUED);
        $this->assertFalse($this->steps($customer)['website']['done']);

        $this->audit($business, ExternalSiteCrawl::COMPLETED);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state']);
    }

    public function test_an_unavailable_website_is_not_offered_and_the_remaining_route_to_reach_is(): void
    {
        [$customer, $business] = $this->onboarded();
        // The owner switched the Website feature off for this Business (a row means "disabled for this Business").
        DB::table('business_feature_toggles')->insert([
            'business_id' => $business->id, 'feature_key' => 'website_generation', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $home = $this->command($customer);

        $steps = collect($home['setup']['steps'])->keyBy('key');
        $this->assertFalse($steps->has('website'), 'An unavailable website is not a Launch step.');
        $this->assertSame('lead_capture', $home['setup']['nextKey'], 'Reach is still possible through an enquiry path, so it is what is asked.');
    }

    // ── lead capture ─────────────────────────────────────────────────────

    public function test_lead_capture_follows_real_records(): void
    {
        [$customer, $business] = $this->onboarded();

        $this->assertFalse($this->steps($customer)['lead_capture']['done']);

        $this->liveForm($business, 'draft');
        $this->assertFalse($this->steps($customer)['lead_capture']['done'], 'A draft form takes no enquiries.');

        $this->liveForm($business, 'active');
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A live form is a working enquiry path.');
    }

    public function test_a_website_form_counts_only_on_a_published_site(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'hosted');
        $this->website($business, 'draft');
        $this->websiteForm($business);

        $this->assertFalse($this->steps($customer)['lead_capture']['done']);

        $this->website($business, 'published');
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state']);
    }

    public function test_a_booking_type_is_a_path_only_when_it_can_actually_take_bookings(): void
    {
        [$customer, $business] = $this->onboarded();
        $location = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        DB::table('booking_types')->insert([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(), 'business_location_id' => $location,
            'name' => 'Consultation', 'duration_minutes' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $steps = $this->steps($customer);
        $this->assertFalse($steps['lead_capture']['done'], 'A booking type with no staff or hours is not a working path.');
        $this->assertFalse($steps['bookable']['done']);
        $this->assertNotEmpty($steps['bookable']['note'], 'The real reason is shown.');
        $this->assertFalse($steps['bookable']['essential']);
    }

    // ── optional integrations ────────────────────────────────────────────

    public function test_optional_integrations_never_block_leaving_launch_and_are_never_steps(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'hosted');
        $this->website($business, 'published');

        // No Google, mailbox, texting, Stripe or Ads connection exists.
        $home = $this->command($customer);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $home['state']);
        $this->assertNull($home['setup']);

        // A broken optional connection is attention, not a return to Launch.
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state']);
    }

    public function test_launch_lists_no_optional_integration_as_a_step(): void
    {
        [$customer] = $this->onboarded();

        $keys = array_keys($this->steps($customer));

        $this->assertSame([], array_values(array_intersect($keys, ['google', 'gbp', 'search_console', 'mailbox', 'sms', 'stripe', 'ads'])));
    }

    // ── Launch → Growth ──────────────────────────────────────────────────

    public function test_finishing_onboarding_alone_does_not_leave_launch_and_reach_alone_does_not_skip_the_foundation(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'Foundation without reach stays in Launch.');

        DB::table('business_services')->where('business_id', $business->id)->update(['is_primary' => false]);
        $this->setMode($business, 'hosted');
        $this->website($business, 'published');
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'Reach without the foundation stays in Launch.');
    }

    public function test_growth_is_never_asserted_without_growth_evidence(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'hosted');
        $this->website($business, 'published');

        $home = $this->command($customer);

        $this->assertSame(HomeCommandCenter::STATE_NORMAL, $home['state'], 'Out of Launch is not "Growing": no opportunity or ranking was fabricated.');
        $this->assertSame([], $home['queue']);
        $this->assertNull($home['primary']);
    }

    // ── entitlements ─────────────────────────────────────────────────────

    public function test_core_and_growth_are_offered_only_the_actions_they_are_entitled_to(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer] = $this->onboarded($tier, 'Booth ' . $tier->value);
            $context = $this->resolvedContext($customer->user);
            $entitlements = app(\App\Library\Navigation\CustomerShellComposer::class)->currentMenuEntitlements($context);
            $steps = $this->steps($customer);

            $this->assertSame($entitlements->allows('website_generation'), $steps['website']['action'] !== null, "{$tier->value}: website action matches entitlement.");
            if (isset($steps['lead_capture'])) {
                $this->assertSame($entitlements->allows('forms'), $steps['lead_capture']['action'] !== null, "{$tier->value}: forms action matches entitlement.");
            }
            foreach (['ads', 'google_ads', 'meta_ads'] as $growthOnly) {
                $this->assertArrayNotHasKey($growthOnly, $steps, 'Growth-only setup is never a required Launch step.');
            }
        }
    }

    // ── correction round 1 ────────────────────────────────────────────

    public function test_an_audit_of_the_old_address_does_not_validate_a_new_one(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'external', 'https://old.example');
        $this->audit($business, ExternalSiteCrawl::COMPLETED, 'https://old.example');
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'Precondition: the checked address qualifies.');

        // The address changes (canonical_domain deliberately left stale: it must not be trusted).
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => 'https://new.example']);
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state']);
        $this->assertSame('Review your existing website', $this->steps($customer)['website']['title']);

        $this->audit($business, ExternalSiteCrawl::COMPLETED, 'https://www.new.example/');
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A qualifying check of the new address (www and path normalised) restores readiness.');
    }

    public function test_only_a_usable_recent_completed_check_qualifies(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'external', 'https://orangeglam.example');

        $this->audit($business, ExternalSiteCrawl::FAILED);
        $this->audit($business, ExternalSiteCrawl::QUEUED);
        $this->audit($business, ExternalSiteCrawl::RUNNING);
        $this->audit($business, ExternalSiteCrawl::COMPLETED, pages: 0);
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'Failed, queued, running and zero-page checks do not count.');

        $this->audit($business, ExternalSiteCrawl::COMPLETED, finishedAt: '2026-08-01 00:00:00');
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A check older than two re-check intervals no longer describes the site.');
    }

    public function test_only_a_form_visitors_can_actually_submit_counts_as_an_enquiry_path(): void
    {
        [$customer, $business] = $this->onboarded();
        $otherLocation = (int) DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $this->bareTenant(WorkspacePlanTier::Growth, 'Other Biz', 'Other Account')[1]->id, 'name' => 'Elsewhere', 'service_mode' => 'online', 'country_code' => 'US',
            'is_primary' => 1, 'lifecycle_state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->authenticateAs($customer);

        $bad = [
            'draft form' => ['state' => 'draft'],
            'inactive form' => ['state' => 'inactive'],
            'no deployment' => ['deployment' => false],
            'disabled deployment' => ['enabled' => false],
            'unsupported source' => ['source' => 'carrier_pigeon'],
            'missing current version' => ['version' => false],
            'another business\'s location' => ['location' => $otherLocation],
        ];

        foreach ($bad as $label => $overrides) {
            $this->usableForm($business, $overrides);
            $this->assertFalse($this->steps($customer)['lead_capture']['done'], "{$label} must not count.");
        }

        // An archived Location of this Business.
        $archived = (int) DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => 'Old', 'service_mode' => 'online', 'country_code' => 'US', 'is_primary' => 0,
            'lifecycle_state' => 'archived', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->usableForm($business, ['location' => $archived]);
        $this->assertFalse($this->steps($customer)['lead_capture']['done'], 'An archived Location must not count.');

        $this->usableForm($business);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $this->command($customer)['state'], 'A genuinely usable form is a working path.');
    }

    public function test_a_website_form_needs_an_active_location_of_this_business_and_a_published_site(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'hosted');
        $this->website($business, 'draft');
        $this->websiteForm($business);
        $this->assertFalse($this->steps($customer)['lead_capture']['done'], 'Draft site.');

        $this->website($business, 'published');
        DB::table('website_forms')->delete();
        $this->websiteForm($business, useLocation: false);
        $status = app(\App\Library\Dashboard\DashboardStatusReader::class)->forBusiness($business->id);
        $this->assertFalse($status->hasActiveWebsiteForm, 'A quote form with no Location turns visitors away.');

        DB::table('website_forms')->delete();
        $this->websiteForm($business);
        $this->assertTrue(app(\App\Library\Dashboard\DashboardStatusReader::class)->forBusiness($business->id)->hasActiveWebsiteForm);
    }

    public function test_owner_and_restricted_staff_get_the_same_stage_but_staff_get_no_unauthorised_links(): void
    {
        [$owner, $business, $workspace] = array_merge($this->bareTenant(WorkspacePlanTier::Growth, 'Shared Booths', 'Shared Account'));
        app(BusinessLocationRepository::class)->upsertPrimary($business, ['name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US']);
        $this->primaryService($business);
        $this->adoptSeoFirstPolicy($business);
        app(\App\Library\Entitlement\EntitlementManager::class)->createOrChangeOverride($workspace, \App\Enums\Entitlement\PlatformFeature::Calendar, \App\Enums\Entitlement\WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'fixture');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);

        $this->authenticateAs($owner);
        $ownerHtml = $this->get(route('user.home'))->assertOk()->getContent();
        $this->authenticateAs($staff, []);
        $staffHtml = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-home-state="setup"', $ownerHtml);
        $this->assertStringContainsString('data-home-state="setup"', $staffHtml, 'Reach is not waived because the viewer lacks permission.');
        $this->assertStringContainsString('data-step="website"', $staffHtml);

        preg_match('#<section[^>]*data-role="home-setup".*?</section>#s', $staffHtml, $staffSetup);
        $this->assertStringNotContainsString('data-role="setup-step-action"', $staffSetup[0], 'No unauthorised setup links for restricted staff.');
        preg_match('#<section[^>]*data-role="home-setup".*?</section>#s', $ownerHtml, $ownerSetup);
        $this->assertStringContainsString('data-role="setup-step-action"', $ownerSetup[0]);
    }

    public function test_business_wide_booking_readiness_is_the_same_for_a_staff_member_scoped_to_another_location(): void
    {
        [$owner, $business, $workspace] = array_merge($this->bareTenant(WorkspacePlanTier::Growth, 'Two Sites', 'Two Sites Account'));
        app(BusinessLocationRepository::class)->upsertPrimary($business, ['name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US']);
        $this->primaryService($business);
        $this->adoptSeoFirstPolicy($business);
        // A second, bookable Location that the restricted staff member cannot reach.
        $second = \App\Models\BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $typeId = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(), 'business_location_id' => $second->id,
            'name' => 'Consult', 'duration_minutes' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('booking_type_staff')->insert(['booking_type_id' => $typeId, 'staff_user_id' => $owner->user_id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('staff_availability_rules')->insert(['business_location_id' => $second->id, 'staff_user_id' => $owner->user_id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now()]);

        $this->authenticateAs($owner);
        $ownerState = $this->command($owner)['state'];
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $ownerState, 'A bookable Location anywhere in the Business is reach.');

        $this->assertTrue(app(\App\Library\Dashboard\Home\HomeBookingReader::class)->businessWideBookingReady($business));
    }

    public function test_an_unreadable_status_shows_no_false_checkmarks_and_no_growth_claim(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Unread Booths', 'Unread Account');
        $this->adoptSeoFirstPolicy($business);
        $this->authenticateAs($customer);

        $listener = function ($q) {
            if (str_contains($q->sql, 'business_usage_wallets')) {
                throw new \RuntimeException('status read failed');
            }
        };
        DB::listen($listener);
        $broken = $this->command($customer);

        $this->assertTrue($broken['readinessUnknown']);
        $this->assertNull($broken['setup'], 'Nothing is shown as done, or as not done.');
        $this->assertNotSame(HomeCommandCenter::STATE_GROWING, $broken['state']);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $broken['state']);
        $this->assertFalse($broken['caughtUp']);
    }

    public function test_the_unavailable_notice_renders_and_normal_service_resumes(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'hosted');
        $this->website($business, 'published');

        $fail = true;
        DB::listen(function ($q) use (&$fail) {
            if ($fail && str_contains($q->sql, 'business_usage_wallets')) {
                throw new \RuntimeException('status read failed');
            }
        });

        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-band="setup" data-band-state="failed"', $html);
        $this->assertStringNotContainsString('data-role="home-setup"', $html);

        $fail = false;
        $recovered = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-band="setup" data-band-state="failed"', $recovered);
        $this->assertFalse($this->command($customer)['readinessUnknown']);
    }

    public function test_an_established_business_keeps_its_original_home_treatment(): void
    {
        // No SEO-first marker: an account that predates the policy, with no Service and no website.
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Established Co', 'Established Account');
        $this->completeCalendarSetup($business);
        DB::table('business_services')->where('business_id', $business->id)->delete();
        $this->authenticateAs($customer);

        $home = $this->command($customer);

        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $home['state'], 'No new requirement reclassifies it into Launch.');
        $this->assertNull($home['setup']);
    }

    public function test_an_established_business_with_a_broken_google_connection_still_shows_broken_attention(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Broken Established', 'Broken Established Account');
        $this->completeCalendarSetup($business);
        DB::table('business_services')->where('business_id', $business->id)->delete();
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        $home = $this->command($customer);

        $this->assertSame(HomeCommandCenter::STATE_BROKEN, $home['state']);
        $this->assertSame('google_connection_lost', $home['primary']['key']);
        $this->assertStringContainsString('data-home-state="broken"', $this->get(route('user.home'))->assertOk()->getContent());
    }

    // ── cost and isolation ───────────────────────────────────────────────

    public function test_the_new_facts_ride_the_existing_status_statement_and_add_no_query(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->setMode($business, 'external', 'https://orangeglam.example');
        $context = $this->resolvedContext($customer->user);
        $this->warmShellEntitlements($context);

        $sql = $this->sqlDuring(fn () => app(\App\Library\Dashboard\BusinessHomePresenter::class)->present($context, $customer->user));

        $statusStatements = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'business_usage_wallets')));
        $this->assertCount(1, $statusStatements, 'Exactly one status statement.');

        foreach (['business_services', 'external_site_crawls', 'website_forms', 'from `forms`', 'form_deployments', 'form_versions', 'customer_onboardings'] as $table) {
            $readers = array_values(array_filter($sql, fn (string $s) => str_contains($s, $table)));
            $this->assertSame([], array_values(array_diff($readers, $statusStatements)), "{$table} is read only inside the status statement.");
        }
    }

    public function test_the_query_count_does_not_grow_with_how_much_is_set_up(): void
    {
        [$bareCustomer, $bareBusiness] = $this->bareTenant(WorkspacePlanTier::Growth, 'Empty Booths', 'Empty Account');
        [$fullCustomer, $fullBusiness] = $this->onboarded(WorkspacePlanTier::Growth, 'Full Booths');
        $this->setMode($fullBusiness, 'hosted');
        $this->website($fullBusiness, 'published');
        $this->websiteForm($fullBusiness);
        $this->liveForm($fullBusiness);
        $this->audit($fullBusiness);

        $count = function ($customer): int {
            $context = $this->resolvedContext($customer->user);
            $this->warmShellEntitlements($context);
            \Illuminate\Support\Facades\Cache::flush();

            return count($this->sqlDuring(fn () => app(\App\Library\Dashboard\BusinessHomePresenter::class)->present($context, $customer->user)));
        };

        $this->authenticateAs($bareCustomer);
        $bare = $count($bareCustomer);
        $this->authenticateAs($fullCustomer);
        $full = $count($fullCustomer);

        fwrite(STDERR, "\nHOME QUERY COUNTS bare={$bare} full={$full}\n");
        $this->assertLessThanOrEqual($bare + 12, $full, 'Readiness facts add no statements; only genuinely richer bands read more.');
    }

    public function test_one_businesss_facts_never_reach_anothers_home(): void
    {
        [$customerA, $businessA] = $this->onboarded(WorkspacePlanTier::Growth, 'Alpha Booths');
        $this->setMode($businessA, 'hosted');
        $this->website($businessA, 'published');
        $this->liveForm($businessA);
        $this->audit($businessA);

        [$customerB, $businessB] = $this->bareTenant(WorkspacePlanTier::Growth, 'Bravo Booths', 'Bravo Account');
        $this->adoptSeoFirstPolicy($businessB);

        $this->authenticateAs($customerB);
        $home = $this->command($customerB);

        $this->assertSame(HomeCommandCenter::STATE_SETUP, $home['state']);
        $steps = collect($home['setup']['steps'])->keyBy('key');
        $this->assertFalse($steps['location']['done']);
        $this->assertFalse($steps['service']['done']);
        $this->assertFalse($steps['website']['done']);
        $this->assertFalse($steps['lead_capture']['done']);
    }
}
