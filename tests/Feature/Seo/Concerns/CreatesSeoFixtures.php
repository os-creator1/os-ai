<?php

namespace Tests\Feature\Seo\Concerns;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Enums\Website\WebsiteStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Http\Controllers\Customer\Business\SeoController;
use App\Http\Controllers\Customer\Business\SeoKeywordsController;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteRevision;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\Support\Seo\EntitlementBypassSeoController;
use Tests\Support\Seo\EntitlementBypassSeoKeywordsController;

/**
 * Contract 18 Sub-slice 18A — shared SEO fixtures.
 *
 * Builds on the GBP fixtures (tenant + tier, Locations, connections, members)
 * so the SEO and GBP suites stay directly comparable, and adds the SEO
 * specifics: a published Website snapshot, a GBP binding with a controllable
 * mirror, a Selected-Location-scope member, query counting, and the
 * entitlement-bypass controller (see EntitlementBypassSeoController).
 */
trait CreatesSeoFixtures
{
    use CreatesGoogleBusinessProfileFixtures;

    /** Permissions a fully-permitted SEO customer holds in the test session. */
    protected function seoPermissions(): array
    {
        return ['view_seo', 'manage_seo', 'view_google_business_profile', 'website'];
    }

    protected function authenticateAsSeoCustomer(Customer $customer, ?array $permissions = null): void
    {
        $this->authenticateAsCustomer($customer, $permissions ?? $this->seoPermissions());
    }

    /**
     * Makes the SEO controller reachable by replacing ONLY its entitlement
     * step. Everything else in the request runs the production code.
     */
    protected function bypassSeoEntitlementForTest(): void
    {
        $this->app->bind(SeoController::class, EntitlementBypassSeoController::class);
        $this->app->bind(SeoKeywordsController::class, EntitlementBypassSeoKeywordsController::class);
    }

    protected function seoUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]);
    }

    /**
     * A Selected-Location-scope member with reach to every Business, granted
     * exactly the given Locations.
     *
     * @param  array<int, BusinessLocation>  $grantedLocations
     */
    protected function selectedScopeMember(Workspace $workspace, array $grantedLocations): Customer
    {
        $member = $this->createCustomer();

        $membership = WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->user_id,
            'role' => 'staff',
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'location_access_scope' => LocationAccessScope::Selected->value,
            'is_active' => true,
        ]);

        foreach ($grantedLocations as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $member;
    }

    /**
     * Publishes a Website whose immutable revision carries the given pages.
     *
     * @param  array<int, array<string, mixed>>  $pages  snapshot pages
     * @param  array<int, array<string, mixed>>  $assets
     */
    protected function publishWebsite(Business $business, array $pages, array $assets = []): Website
    {
        $website = Website::create([
            'business_id' => $business->id,
            'name' => 'Test Website',
            'status' => WebsiteStatus::Draft,
        ]);

        $revision = WebsiteRevision::create([
            'website_id' => $website->id,
            'version_number' => 1,
            'snapshot' => [
                'schema_version' => 1,
                'website' => ['name' => 'Test Website', 'theme' => []],
                'pages' => $pages,
                'assets' => $assets,
            ],
            'schema_version' => 1,
            'created_by' => $business->customer_id,
        ]);

        $website->update(['status' => WebsiteStatus::Published, 'published_revision_id' => $revision->id]);

        return $website->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    protected function snapshotPage(string $uid, string $title, array $seo = [], array $sections = [], bool $isHome = false, ?string $slug = null): array
    {
        return [
            'uid' => $uid,
            'slug' => $slug ?? ($isHome ? null : $uid),
            'is_home' => $isHome,
            'title' => $title,
            'seo' => array_merge(['seo_title' => null, 'meta_description' => null, 'noindex' => false], $seo),
            'sections' => $sections,
        ];
    }

    /**
     * A GBP binding for one Location with a controllable mirror.
     *
     * @param  array<string, mixed>  $mirror  the bounded profile_mirror payload
     */
    protected function bindGoogleLocation(
        Business $business,
        BusinessLocation $location,
        BusinessGoogleConnection $connection,
        bool $freshMirror = true,
        array $mirror = [],
        GoogleLocationHealth $health = GoogleLocationHealth::Verified,
    ): BusinessGoogleLocation {
        $mirror = array_merge([
            'title' => 'Mirror Title',
            'phone_primary' => '+15550101234',
            'website_uri' => 'https://example.test',
            'new_review_uri' => 'https://search.google.test/review',
        ], $mirror);

        return BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/1',
            'provider_location_resource_name' => 'locations/' . uniqid('', true),
            'verification_state' => $health,
            'profile_mirror' => $mirror,
            'mirror_fetched_at' => $freshMirror ? now()->subHour() : now()->subDays(40),
            'mirror_expires_at' => $freshMirror ? now()->addDays(10) : now()->subDays(10),
        ]);
    }

    /**
     * A second, service-area Location so a Business can have several.
     */
    protected function extraLocation(Business $business, string $name, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => BusinessServiceMode::ServiceArea,
            'country_code' => 'US',
            'service_radius_km' => 25,
            'public_address' => false,
            'is_primary' => false,
        ], $overrides));
    }

    /**
     * SQL statements executed while $callback runs.
     *
     * @return array<int, string>
     */
    protected function capturedQueries(callable $callback): array
    {
        // The query log, not DB::listen: a listener outlives the call that
        // registered it and would keep recording into later measurements.
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return array_map(fn (array $entry) => (string) $entry['query'], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * A content hash of every row (every column, timestamps included) of the
     * given tables. Equal before and after proves nothing was written.
     *
     * @param  array<int, string>  $tables
     */
    protected function dbFingerprint(array $tables): string
    {
        $parts = [];

        foreach ($tables as $table) {
            $parts[] = $table . ':' . md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        return implode('|', $parts);
    }

    /** Every table SEO must never write in Sub-slice 18A. */
    protected function seoProtectedTables(): array
    {
        return [
            'businesses', 'business_locations', 'websites', 'website_pages', 'website_revisions', 'website_assets',
            'business_google_connections', 'business_google_locations', 'business_google_operations',
        ];
    }

    protected function growthTenantWithLocation(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->createLocation($business);

        return [$customer, $business, $workspace, $location];
    }
}
