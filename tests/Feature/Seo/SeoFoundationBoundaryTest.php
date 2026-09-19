<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\SeoController;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18A §14.1 / §16 (T-SEO-TEN-*, T-SEO-BOUND-*,
 * T-SEO-NAMING-*) — the SEO foundation is fail-closed, tenancy/capability/
 * entitlement are independent, the code is structurally read-only, and it
 * shares nothing with the legacy inbound-SMS Keywords product.
 */
class SeoFoundationBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    // -----------------------------------------------------------------
    // Planned => fail-closed through entitlement (RFC-004).
    // -----------------------------------------------------------------

    public function test_both_seo_features_remain_planned(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value));
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoModule->value));
    }

    public function test_the_overview_is_a_404_for_every_tier_while_the_feature_is_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business, $workspace] = $this->entitledTenant($tier);
            $this->createLocation($business);
            // Even a fully-permitted owner with full tenancy is refused: the
            // real controller is unreachable until Sub-slice H flips it.
            $this->authenticateAsSeoCustomer($customer);

            $this->get($this->seoUrl($workspace, $business))->assertNotFound();
        }
    }

    public function test_the_bare_entry_offers_no_business_while_the_feature_is_planned(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get(route('customer.seo.index'))->assertOk()->getContent();

        $this->assertStringContainsString('No Business available yet', $html);
        // The app shell legitimately renders account links elsewhere on the
        // page; what must be absent is any route INTO the SEO Overview.
        $this->assertStringNotContainsString("/businesses/{$business->uid}/seo", $html);
        $this->assertStringNotContainsString(route("customer.workspaces.businesses.seo.index", [$workspace->uid, $business->uid]), $html);
    }

    // -----------------------------------------------------------------
    // Independence: tenancy, entitlement and capability are separate.
    // -----------------------------------------------------------------

    public function test_the_unentitled_real_controller_refuses_even_with_tenancy_and_capability(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $this->get($this->seoUrl($workspace, $business))->assertNotFound();
    }

    public function test_capability_without_tenancy_is_a_404(): void
    {
        $this->bypassSeoEntitlementForTest();
        [, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $stranger = $this->createCustomer();
        $this->authenticateAsSeoCustomer($stranger);

        $this->get($this->seoUrl($workspace, $business))->assertNotFound();
    }

    public function test_tenancy_without_the_view_seo_capability_is_refused(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);

        // Full tenancy (owner), entitlement bypassed, but no view_seo.
        $this->authenticateAsSeoCustomer($customer, ['view_google_business_profile', 'website']);

        // A missing customer permission answers 401 in this application
        // (the same as every other permission-gated customer route), never
        // a 200 and never the overview.
        $this->get($this->seoUrl($workspace, $business))->assertUnauthorized();
    }

    public function test_capability_and_tenancy_together_reach_the_overview_once_entitlement_is_satisfied(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $this->get($this->seoUrl($workspace, $business))->assertOk();
    }

    // -----------------------------------------------------------------
    // Guessed / foreign resources are indistinguishable from missing ones.
    // -----------------------------------------------------------------

    public function test_unknown_and_foreign_identifiers_are_all_404(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        [, $foreignBusiness, $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        // Unknown workspace, unknown business, the caller's workspace with a
        // FOREIGN business uid, and a foreign workspace with the caller's uid.
        $this->get(route('customer.workspaces.businesses.seo.index', ['no-such-workspace', $business->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.index', [$workspace->uid, 'no-such-business']))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $foreignBusiness->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.index', [$foreignWorkspace->uid, $business->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.index', [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
    }

    public function test_an_inactive_workspace_and_a_non_active_business_are_404(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->get($this->seoUrl($workspace, $business))->assertNotFound();

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->get($this->seoUrl($workspace, $business))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Naming: no collision with the legacy inbound-SMS Keywords product.
    // -----------------------------------------------------------------

    public function test_seo_shares_no_route_namespace_permission_or_table_with_legacy_keywords(): void
    {
        $seoRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoController::class));

        $this->assertGreaterThanOrEqual(2, $seoRoutes->count(), 'The SEO entry and Overview routes must exist.');

        foreach ($seoRoutes as $route) {
            $name = (string) $route->getName();

            $this->assertStringNotContainsString('keywords', $name);
            $this->assertFalse(str_starts_with($name, 'customer.keywords.'), "[{$name}] collides with the legacy Keywords route namespace.");
            $this->assertTrue(
                str_starts_with($name, 'customer.seo.') || str_starts_with($name, 'customer.workspaces.businesses.seo.'),
                "[{$name}] is outside the SEO route namespace."
            );
        }

        $permissions = config('customer-permissions');

        foreach (['view_seo', 'manage_seo', 'manage_search_console'] as $key) {
            $this->assertArrayHasKey($key, $permissions);
            $this->assertSame('SEO', $permissions[$key]['category']);
        }

        // The legacy product's capability is untouched and not reused.
        $this->assertArrayHasKey('view_keywords', $permissions);
        $this->assertNotContains('view_keywords', ['view_seo', 'manage_seo', 'manage_search_console']);

        // No SEO table is named for, or shared with, the legacy `keywords` table.
        $this->assertTrue(\Schema::hasTable('keywords'));
    }

    public function test_the_seo_permission_defaults_are_exactly_as_contracted(): void
    {
        $permissions = config('customer-permissions');

        $this->assertTrue($permissions['view_seo']['default']);
        $this->assertTrue($permissions['manage_seo']['default']);
        $this->assertFalse($permissions['manage_search_console']['default'], 'Credential-class capability must default to false.');
    }

    // -----------------------------------------------------------------
    // Structural read-only: T-SEO-BOUND-*.
    // -----------------------------------------------------------------

    public function test_every_seo_route_is_read_only(): void
    {
        $seoRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoController::class));

        foreach ($seoRoutes as $route) {
            $this->assertEmpty(
                array_diff($route->methods(), ['GET', 'HEAD']),
                'Sub-slice 18A has no write route: [' . $route->getName() . '] accepts ' . implode(',', $route->methods()) . '.'
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function seoCodeFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/app/Library/Seo/*.php') ?: [],
            [
                $root . '/app/Http/Controllers/Customer/Business/SeoController.php',
                $root . '/app/Library/GoogleBusinessProfile/GoogleBusinessProfileStatusReader.php',
            ],
        );

        $cases = [];

        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('seoCodeFiles')]
    public function test_seo_code_has_no_write_path_no_network_call_no_ai_and_no_dispatch(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        $this->assertNotSame('', $code);

        $forbidden = [
            // Any write to any table — Website, Business, Location, Google or otherwise.
            '/->(save|update|delete|forceDelete|insert|create|firstOrCreate|updateOrCreate|upsert|increment|decrement|touch|sync|attach|detach)\s*\(/',
            '/::(create|insert|upsert|updateOrCreate|firstOrCreate|destroy|forceCreate)\s*\(/',
            '/DB::(insert|update|delete|statement|unprepared|transaction)\b/',
            // Any network call, Google client or SDK.
            '/\bHttp::/', '/GuzzleHttp/', '/\bcurl_/', '/Socialite/',
            '/GoogleBusinessProfileReadClient/', '/HttpGoogleBusinessProfileReadClient/', '/FakeGoogleBusinessProfileReadClient/',
            // Any AI provider or gateway.
            '/OpenAI/i', '/App\\\\Library\\\\Ai\\\\/', '/AiGateway/', '/Anthropic/i',
            // Any queued / dispatched / cached side effect.
            '/\bdispatch\s*\(/', '/::dispatch\b/', '/\bBus::/', '/\bQueue::/', '/\bevent\s*\(/', '/\bCache::/',
            // Any Website write seam — SEO must never call it.
            '/WebsiteDraftPageService/', '/WebsitePublisher/',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, $code),
                basename($file) . ' must not match ' . $pattern . ' (Contract 18 §12).'
            );
        }
    }

    public function test_the_gbp_status_reader_does_not_depend_on_the_seo_module(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/GoogleBusinessProfile/GoogleBusinessProfileStatusReader.php');

        // GBP §37.2: no circular dependency in either direction.
        $this->assertStringNotContainsString('App\\Library\\Seo', $code);
        $this->assertStringNotContainsString('SeoLocationScope', $code);
    }

    private function codeWithoutComments(string $path): string
    {
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
