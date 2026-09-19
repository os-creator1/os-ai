<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Library\Seo\SeoOverview;
use App\Library\Seo\SeoOverviewReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\Support\Seo\EntitlementBypassSeoController;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18A §5.2 / §6 / §9.3 / §11.4 — the SEO Overview,
 * exercised over HTTP through the entitlement-bypass controller (the real
 * controller is fail-closed while the feature is Planned; that is proven in
 * SeoFoundationBoundaryTest). Everything behind the entitlement step runs
 * as production code.
 */
class SeoOverviewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassSeoEntitlementForTest();
    }

    private function overviewOf($response): SeoOverview
    {
        return $response->viewData('overview');
    }

    /** A Business whose website URL, phone and GBP URL are set or cleared. */
    private function setBusinessFacts(Business $business, array $facts): void
    {
        DB::table('businesses')->where('id', $business->id)->update($facts);
    }

    private function storefront(Business $business, string $name, bool $ready = true): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => BusinessServiceMode::Storefront,
            'country_code' => 'US',
            'is_primary' => false,
        ], $ready ? ['address_line_1' => '1 ' . $name, 'city' => 'Tampa'] : []));
    }

    // -----------------------------------------------------------------
    // What Core sees.
    // -----------------------------------------------------------------

    public function test_a_core_owner_sees_the_platform_derived_overview_with_no_google_section(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->setBusinessFacts($business, ['google_business_profile_url' => null]);
        $this->createLocation($business);
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', ['seo_title' => 'Best Bakery', 'meta_description' => 'Fresh bread.'], [], true),
            $this->snapshotPage('b', 'About'),
        ]);
        // Holds the GBP capability too: Core is still not entitled to GBP,
        // so capability alone must never produce a Google section.
        $this->authenticateAsSeoCustomer($customer);

        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $html = $response->getContent();
        $overview = $this->overviewOf($response);

        $states = collect($overview->readiness)->mapWithKeys(fn ($i) => [$i->key => $i->state->value]);
        $this->assertSame('met', $states['website_url_set']);
        $this->assertSame('met', $states['business_phone_set']);
        $this->assertSame('met', $states['locations_have_address_or_service_area']);
        $this->assertSame('met', $states['website_published']);
        $this->assertSame('not_met', $states['gbp_url_present']);

        $this->assertSame(['pages' => 2, 'with_meta_description' => 1, 'with_seo_title' => 1, 'marked_noindex' => 0], $overview->content);
        $this->assertNull($overview->google);

        $this->assertStringContainsString('2 pages published', $html);
        $this->assertStringContainsString('1 of 2 with a meta description', $html);
        $this->assertStringContainsString('Your website is not indexed by search engines yet', $html);
        $this->assertStringNotContainsString('data-section="google-business-profile"', $html);
    }

    public function test_the_readiness_items_render_with_a_word_for_each_state(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->setBusinessFacts($business, ['phone' => null, 'website_url' => null]);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-key="website_url_set" data-state="not_met"/', $html);
        $this->assertMatchesRegularExpression('/data-key="business_phone_set" data-state="not_met"/', $html);
        $this->assertStringContainsString('To do', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString('Open Business settings', $html);
    }

    public function test_with_no_published_website_the_page_says_so_honestly(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $html = $response->getContent();

        $this->assertNull($this->overviewOf($response)->content);
        $this->assertStringContainsString('You have no published website yet.', $html);
        $this->assertStringContainsString('No published website yet', $html);
        $this->assertStringNotContainsString('data-role="content-pages"', $html);
    }

    public function test_indexability_is_a_status_never_a_finding_and_never_claims_indexing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [], true)]);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('served from the platform address', $html);
        $this->assertStringContainsString('once it is connected to your own domain', $html);
        $this->assertStringNotContainsString('ranked', $html);
        $this->assertStringNotContainsString('traffic', $html);
    }

    public function test_the_page_has_no_score_grade_chart_or_placeholder_sections(): void
    {
        $blade = file_get_contents(resource_path('views/customer/business/seo/overview.blade.php'));

        // The header comment states the rule in prose; strip Blade comments
        // and look at what is actually rendered.
        $rendered = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

        foreach (['score', 'grade', 'chart', 'percent', '%', 'Keywords', 'Search Console', 'Citations', 'Reviews', 'disabled', 'Coming soon'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $rendered, "The Overview must not render [{$forbidden}].");
        }

        $this->assertStringNotContainsString('{!!', $rendered, 'Raw, unescaped output is forbidden in this view.');
        $this->assertStringNotContainsString('<form', $rendered);
    }

    // -----------------------------------------------------------------
    // The Google section is GBP's own entitlement AND capability.
    // -----------------------------------------------------------------

    public function test_a_growth_owner_with_the_gbp_capability_sees_the_google_section(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true, ['title' => 'Different'], GoogleLocationHealth::Verified);
        $this->authenticateAsSeoCustomer($customer);

        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $html = $response->getContent();

        $this->assertCount(1, $this->overviewOf($response)->google);
        $this->assertStringContainsString('data-section="google-business-profile"', $html);
        $this->assertStringContainsString($location->name, $html);
        $this->assertStringContainsString('Linked', $html);
        $this->assertStringContainsString('1 detail differs from your Google listing', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]), $html);
    }

    public function test_the_google_section_needs_the_gbp_capability_even_for_a_growth_business(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo']);

        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();

        $this->assertNull($this->overviewOf($response)->google);
        $this->assertStringNotContainsString('data-section="google-business-profile"', $response->getContent());
    }

    public function test_an_unlinked_location_and_a_lost_connection_are_stated_plainly(): void
    {
        [$customer, $business, $workspace, $bound] = $this->growthTenantWithLocation();
        $unbound = $this->extraLocation($business, 'Unlinked Site');
        $connection = $this->activeConnection($business, ['state' => \App\Enums\GoogleBusinessProfile\GoogleConnectionState::Revoked, 'revoked_at' => now()]);
        $this->bindGoogleLocation($business, $bound, $connection, false);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Not linked to a Google listing yet', $html);
        $this->assertStringContainsString('Google connection lost', $html);
    }

    // -----------------------------------------------------------------
    // Output safety.
    // -----------------------------------------------------------------

    public function test_customer_supplied_strings_are_escaped(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        DB::table('business_locations')->where('id', $location->id)->update(['name' => '<script>alert("loc")</script>']);
        $this->bindGoogleLocation($business, $location->fresh(), $this->activeConnection($business), true);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->seoUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("loc")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;loc&quot;)&lt;/script&gt;', $html);
    }

    // -----------------------------------------------------------------
    // Zero external calls, zero writes, zero side effects.
    // -----------------------------------------------------------------

    public function test_the_overview_makes_no_external_call_no_dispatch_and_no_write(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['meta_description' => 'x'], [], true)]);
        $this->authenticateAsSeoCustomer($customer);

        $this->app->bind(GoogleBusinessProfileReadClient::class, function () {
            throw new RuntimeException('The Overview must never resolve a Google provider client.');
        });
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Event::fake();

        $before = $this->dbFingerprint($this->seoProtectedTables());

        $this->get($this->seoUrl($workspace, $business))->assertOk();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'The Overview must not write Website, Business, Location or Google data.');
    }

    public function test_repeated_views_are_idempotent(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $first = $this->overviewOf($this->get($this->seoUrl($workspace, $business))->assertOk());
        $second = $this->overviewOf($this->get($this->seoUrl($workspace, $business))->assertOk());

        $this->assertEquals($first, $second);
    }

    // -----------------------------------------------------------------
    // Location filtering happens BEFORE aggregation (Contract 18 §6).
    // -----------------------------------------------------------------

    public function test_a_selected_scope_actor_cannot_infer_an_inaccessible_locations_state_from_a_count(): void
    {
        [$owner, $business, $workspace, $first] = $this->growthTenantWithLocation();   // ready (storefront w/ address)
        $second = $this->storefront($business, 'Secret Second Site', false);            // NOT ready
        $third = $this->storefront($business, 'Secret Third Site', false);              // NOT ready
        $connection = $this->activeConnection($business);
        foreach ([$first, $second, $third] as $l) {
            $this->bindGoogleLocation($business, $l, $connection, true);
        }

        // The owner sees all three: 1 of 3 ready.
        $this->authenticateAsSeoCustomer($owner);
        $ownerOverview = $this->overviewOf($this->get($this->seoUrl($workspace, $business))->assertOk());
        $ownerItem = collect($ownerOverview->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('1 of 3 locations are ready. Add an address or a service area to the rest.', $ownerItem->detail);

        // A member granted only the READY Location sees a clean 1 of 1 —
        // nothing that betrays two further, unready Locations.
        $onlyReady = $this->selectedScopeMember($workspace, [$first]);
        $this->authenticateAsSeoCustomer($onlyReady);
        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $item = collect($this->overviewOf($response)->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('met', $item->state->value);
        $this->assertSame('1 of 1 location', $item->detail);
        $html = $response->getContent();
        $this->assertStringNotContainsString('Secret Second Site', $html);
        $this->assertStringNotContainsString('Secret Third Site', $html);
        $this->assertCount(1, $this->overviewOf($response)->google);

        // A member granted only an UNREADY Location sees 0 of 1 — and never the ready one.
        $onlySecond = $this->selectedScopeMember($workspace, [$second]);
        $this->authenticateAsSeoCustomer($onlySecond);
        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $item = collect($this->overviewOf($response)->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('not_met', $item->state->value);
        $this->assertSame('0 of 1 locations are ready. Add an address or a service area to the rest.', $item->detail);
        $this->assertStringNotContainsString($first->name, $response->getContent());
        $this->assertStringNotContainsString('Secret Third Site', $response->getContent());
    }

    public function test_an_actor_with_no_location_grant_learns_nothing_about_locations(): void
    {
        [, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->storefront($business, 'Hidden Site Alpha');
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);

        $none = $this->selectedScopeMember($workspace, []);
        $this->authenticateAsSeoCustomer($none);

        $response = $this->get($this->seoUrl($workspace, $business))->assertOk();
        $overview = $this->overviewOf($response);

        $item = collect($overview->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('not_applicable', $item->state->value);
        $this->assertNull($item->detail);
        $this->assertSame([], $overview->google);
        $this->assertStringNotContainsString('Hidden Site Alpha', $response->getContent());
        $this->assertStringNotContainsString($location->name, $response->getContent());
    }

    public function test_archived_locations_do_not_count_toward_readiness(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $archived = $this->storefront($business, 'Old Site', false);
        DB::table('business_locations')->where('id', $archived->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $this->authenticateAsSeoCustomer($customer);

        $item = collect($this->overviewOf($this->get($this->seoUrl($workspace, $business))->assertOk())->readiness)
            ->firstWhere('key', 'locations_have_address_or_service_area');

        $this->assertSame('1 of 1 location', $item->detail);
    }

    // -----------------------------------------------------------------
    // Query budget: independent of the number of Locations (§11.4).
    // -----------------------------------------------------------------

    /**
     * @return array{0: \App\Models\Customer, 1: Business, 2: Workspace}
     */
    private function growthBusinessWithLocations(int $count, bool $withGoogle): array
    {
        [$customer, $business, $workspace, $primary] = $this->growthTenantWithLocation();
        $connection = $withGoogle ? $this->activeConnection($business) : null;

        if ($withGoogle) {
            $this->bindGoogleLocation($business, $primary, $connection, true, ['title' => 'X']);
        }

        for ($i = 2; $i <= $count; $i++) {
            $location = $this->storefront($business, "Site {$i}", $i % 2 === 0);

            if ($withGoogle) {
                $this->bindGoogleLocation($business, $location, $connection, $i % 3 !== 0, ['title' => 'X']);
            }
        }

        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['meta_description' => 'x'], [], true)]);

        return [$customer, $business, $workspace];
    }

    public function test_the_overview_reader_costs_the_same_for_1_and_25_locations(): void
    {
        [$warmCustomer, $warmBusiness, $warmWorkspace] = $this->growthBusinessWithLocations(2, true);
        $this->authenticateAsSeoCustomer($warmCustomer);
        app(SeoOverviewReader::class)->read($warmWorkspace, $warmBusiness, $warmCustomer->user);

        [$smallCustomer, $smallBusiness, $smallWorkspace] = $this->growthBusinessWithLocations(1, true);
        [$largeCustomer, $largeBusiness, $largeWorkspace] = $this->growthBusinessWithLocations(25, true);

        $this->authenticateAsSeoCustomer($smallCustomer);
        $small = $this->capturedQueries(fn () => app(SeoOverviewReader::class)->read($smallWorkspace, $smallBusiness, $smallCustomer->user));

        $this->authenticateAsSeoCustomer($largeCustomer);
        $large = $this->capturedQueries(fn () => app(SeoOverviewReader::class)->read($largeWorkspace, $largeBusiness, $largeCustomer->user));

        $this->assertSame(count($small), count($large), 'Query count must not grow with the number of Locations.');
        $this->assertLessThanOrEqual(24, count($large), 'Contract 18 §11.4: the Overview ceiling is 24 queries.');
    }

    public function test_the_tenancy_chain_plus_the_overview_stays_inside_the_contract_ceiling(): void
    {
        // Contract 18 §11.4: "Overview <= 24 (tenancy chain included)". The
        // chain is the controller's own resolveSeoTenancy(); the Overview is
        // the reader. Measured together, on a cold tenant, at 1 and 25 Locations.
        [$warmCustomer, $warmBusiness, $warmWorkspace] = $this->growthBusinessWithLocations(2, true);
        $this->authenticateAsSeoCustomer($warmCustomer);
        app(SeoOverviewReader::class)->read($warmWorkspace, $warmBusiness, $warmCustomer->user);

        $measure = function (int $count) {
            [$customer, $business, $workspace] = $this->growthBusinessWithLocations($count, true);
            $this->authenticateAsSeoCustomer($customer);

            $controller = app(EntitlementBypassSeoController::class);
            $chain = new \ReflectionMethod($controller, 'resolveSeoTenancy');
            $chain->setAccessible(true);

            return count($this->capturedQueries(function () use ($chain, $controller, $workspace, $business, $customer) {
                $chain->invoke($controller, $workspace->uid, $business->uid);
                app(SeoOverviewReader::class)->read($workspace, $business, $customer->user);
            }));
        };

        $small = $measure(1);
        $large = $measure(25);

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(24, $large);
    }

    public function test_a_selected_scope_actor_costs_the_same_at_any_location_count(): void
    {
        $measure = function (int $count) {
            [$owner, $business, $workspace] = $this->growthBusinessWithLocations($count, true);
            $granted = BusinessLocation::query()->where('business_id', $business->id)->orderBy('id')->limit(3)->get()->all();
            $member = $this->selectedScopeMember($workspace, $granted);
            $this->authenticateAsSeoCustomer($member);

            return count($this->capturedQueries(fn () => app(SeoOverviewReader::class)->read($workspace, $business, $member->user)));
        };

        $measure(3); // warm-up

        $this->assertSame($measure(3), $measure(25), 'A Selected-scope actor must cost the same at 3 and 25 Locations.');
    }

    public function test_the_http_request_costs_the_same_for_1_and_25_locations(): void
    {
        [$warmCustomer, $warmBusiness, $warmWorkspace] = $this->growthBusinessWithLocations(2, true);
        $this->authenticateAsSeoCustomer($warmCustomer);
        $this->get($this->seoUrl($warmWorkspace, $warmBusiness))->assertOk();

        [$smallCustomer, $smallBusiness, $smallWorkspace] = $this->growthBusinessWithLocations(1, true);
        [$largeCustomer, $largeBusiness, $largeWorkspace] = $this->growthBusinessWithLocations(25, true);

        $this->authenticateAsSeoCustomer($smallCustomer);
        $small = $this->capturedQueries(fn () => $this->get($this->seoUrl($smallWorkspace, $smallBusiness))->assertOk());

        $this->authenticateAsSeoCustomer($largeCustomer);
        $large = $this->capturedQueries(fn () => $this->get($this->seoUrl($largeWorkspace, $largeBusiness))->assertOk());

        $this->assertSame(count($small), count($large), 'The whole request (tenancy chain, shell and Overview) must not grow with Locations.');
    }
}
