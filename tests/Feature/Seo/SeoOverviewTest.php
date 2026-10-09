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
use App\Models\Customer;
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
 * Contract 18 Sub-slice 18A §5.2 / §6 / §9.3 / §11.4 — the SEO Overview READER.
 *
 * The standalone Overview page no longer exists: `/seo` and the Business-scoped
 * `…/seo` URL redirect to Search keywords, and cross-product recommendations
 * live on Business Home. The reader (and its Location-filtering, zero-provider
 * and query-budget guarantees) stays, so these tests drive it directly instead
 * of through a page. The redirect itself is pinned in SeoFoundationBoundaryTest.
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

    /** What the reader returns for the actor, with that actor signed in (as the controller had it). */
    private function overviewFor(Customer $customer, Workspace $workspace, Business $business): SeoOverview
    {
        $this->authenticateAsSeoCustomer($customer);

        return app(SeoOverviewReader::class)->read($workspace, $business, $customer->user);
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

    public function test_a_core_owner_reads_the_platform_derived_overview_with_no_google_section(): void
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
        $overview = $this->overviewFor($customer, $workspace, $business);

        $states = collect($overview->readiness)->mapWithKeys(fn ($i) => [$i->key => $i->state->value]);
        $this->assertSame('met', $states['website_url_set']);
        $this->assertSame('met', $states['business_phone_set']);
        $this->assertSame('met', $states['locations_have_address_or_service_area']);
        $this->assertSame('met', $states['website_published']);
        $this->assertSame('not_met', $states['gbp_url_present']);

        $this->assertSame(['pages' => 2, 'with_meta_description' => 1, 'with_seo_title' => 1, 'marked_noindex' => 0], $overview->content);
        $this->assertNull($overview->google);
    }

    public function test_with_no_published_website_there_is_no_content_summary(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->createLocation($business);

        $this->assertNull($this->overviewFor($customer, $workspace, $business)->content);
    }

    // -----------------------------------------------------------------
    // The Google section is GBP's own entitlement AND capability.
    // -----------------------------------------------------------------

    public function test_a_growth_owner_with_the_gbp_capability_reads_the_google_section(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $connection = $this->activeConnection($business);
        $this->bindGoogleLocation($business, $location, $connection, true, ['title' => 'Different'], GoogleLocationHealth::Verified);

        $this->assertCount(1, $this->overviewFor($customer, $workspace, $business)->google);
    }

    public function test_the_google_section_needs_the_gbp_capability_even_for_a_growth_business(): void
    {
        [$customer, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'manage_seo']);

        $this->assertNull(app(SeoOverviewReader::class)->read($workspace, $business, $customer->user)->google);
    }

    // -----------------------------------------------------------------
    // Zero external calls, zero writes, zero side effects.
    // -----------------------------------------------------------------

    public function test_the_overview_reader_makes_no_external_call_no_dispatch_and_no_write(): void
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

        app(SeoOverviewReader::class)->read($workspace, $business, $customer->user);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'The Overview must not write Website, Business, Location or Google data.');
    }

    public function test_repeated_reads_are_idempotent(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->createLocation($business);

        $first = $this->overviewFor($customer, $workspace, $business);
        $second = $this->overviewFor($customer, $workspace, $business);

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
        $ownerItem = collect($this->overviewFor($owner, $workspace, $business)->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('1 of 3 locations are ready. Add an address or a service area to the rest.', $ownerItem->detail);

        // A member granted only the READY Location sees a clean 1 of 1 —
        // nothing that betrays two further, unready Locations.
        $onlyReady = $this->selectedScopeMember($workspace, [$first]);
        $overview = $this->overviewFor($onlyReady, $workspace, $business);
        $item = collect($overview->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('met', $item->state->value);
        $this->assertSame('1 of 1 location', $item->detail);
        $this->assertCount(1, $overview->google);
        $this->assertStringNotContainsString('Secret', json_encode($overview));

        // A member granted only an UNREADY Location sees 0 of 1 — and never the ready one.
        $onlySecond = $this->selectedScopeMember($workspace, [$second]);
        $overview = $this->overviewFor($onlySecond, $workspace, $business);
        $item = collect($overview->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('not_met', $item->state->value);
        $this->assertSame('0 of 1 locations are ready. Add an address or a service area to the rest.', $item->detail);
        $this->assertStringNotContainsString($first->name, json_encode($overview));
        $this->assertStringNotContainsString('Secret Third Site', json_encode($overview));
    }

    public function test_an_actor_with_no_location_grant_learns_nothing_about_locations(): void
    {
        [, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->storefront($business, 'Hidden Site Alpha');
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);

        $none = $this->selectedScopeMember($workspace, []);
        $overview = $this->overviewFor($none, $workspace, $business);

        $item = collect($overview->readiness)->firstWhere('key', 'locations_have_address_or_service_area');
        $this->assertSame('not_applicable', $item->state->value);
        $this->assertNull($item->detail);
        $this->assertSame([], $overview->google);
        $this->assertStringNotContainsString('Hidden Site Alpha', json_encode($overview));
        $this->assertStringNotContainsString($location->name, json_encode($overview));
    }

    public function test_archived_locations_do_not_count_toward_readiness(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $archived = $this->storefront($business, 'Old Site', false);
        DB::table('business_locations')->where('id', $archived->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $item = collect($this->overviewFor($customer, $workspace, $business)->readiness)->firstWhere('key', 'locations_have_address_or_service_area');

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
}
