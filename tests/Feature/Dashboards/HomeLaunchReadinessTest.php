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
        DB::table('forms')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => 'Enquiry', 'lifecycle_state' => $state,
            'activated_at' => $state === 'active' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function websiteForm(Business $business): void
    {
        $websiteId = (int) DB::table('websites')->where('business_id', $business->id)->value('id');
        DB::table('website_forms')->insert([
            'uid' => (string) Str::uuid(), 'website_id' => $websiteId, 'business_id' => $business->id, 'type' => 'quote_request',
            'name' => 'Quote', 'fields' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audit(Business $business, string $status = ExternalSiteCrawl::COMPLETED): void
    {
        DB::table('external_site_crawls')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'start_url' => 'https://orangeglam.example', 'host' => 'orangeglam.example',
            'status' => $status, 'trigger' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
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
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Bare Booths', 'Bare Account');
        $this->authenticateAs($customer);

        $steps = $this->steps($customer);
        $this->assertTrue($steps['business']['done']);
        $this->assertFalse($steps['location']['done']);
        $this->assertFalse($steps['service']['done']);
        $this->assertSame(route('customer.onboarding.show', ['step' => 'services']), $steps['service']['action']['url']);

        // Step flags are irrelevant: a row claiming every step is done changes nothing.
        DB::table('customer_onboardings')->insert([
            'customer_id' => $customer->user_id, 'business_id' => $business->id, 'status' => 'completed', 'current_step' => 'finish',
            'completed_steps' => json_encode(['business', 'location', 'services', 'website']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($this->steps($customer)['service']['done']);
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

        foreach (['business_services', 'external_site_crawls', 'website_forms', 'from `forms`'] as $table) {
            $readers = array_values(array_filter($sql, fn (string $s) => str_contains($s, $table)));
            $this->assertSame([], array_values(array_diff($readers, $statusStatements)), "{$table} is read only inside the status statement.");
        }
        $this->assertNotContains('customer_onboardings', array_map(fn (string $s) => preg_match('/customer_onboardings/', $s) ? 'customer_onboardings' : '', $sql), 'Home never reads onboarding rows.');
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
