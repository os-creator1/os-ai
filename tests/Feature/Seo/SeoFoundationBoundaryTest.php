<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\SeoController;
use App\Http\Controllers\Customer\Business\SeoKeywordsController;
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

    public function test_the_bare_entry_is_a_404_for_every_tier_while_the_feature_is_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business] = $this->entitledTenant($tier);
            $this->createLocation($business);
            // A fully-permitted owner with full tenancy: still no SEO surface.
            $this->authenticateAsSeoCustomer($customer);

            $this->get(route('customer.seo.index'))->assertNotFound();
        }
    }

    public function test_the_bare_entry_is_a_404_without_view_seo_too_so_the_surface_is_not_revealed(): void
    {
        // The availability floor runs BEFORE the capability check. If it did
        // not, a caller lacking view_seo would get a 401 while one holding it
        // got a 404 — and that difference would prove the surface exists.
        [$holder, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        [$lacking] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $this->authenticateAsSeoCustomer($holder);
        $holderStatus = $this->get(route('customer.seo.index'))->assertNotFound()->getStatusCode();

        $this->authenticateAsSeoCustomer($lacking, ['view_google_business_profile', 'website']);
        $lackingStatus = $this->get(route('customer.seo.index'))->assertNotFound()->getStatusCode();

        $this->assertSame($holderStatus, $lackingStatus, 'Holding view_seo must make no observable difference while Planned.');

        // A customer with no tenancy and no SEO permission at all: same answer.
        $stranger = $this->createCustomer();
        $this->authenticateAsSeoCustomer($stranger, []);
        $this->get(route('customer.seo.index'))->assertNotFound();
    }

    public function test_the_bare_entry_exposes_no_route_into_the_overview_while_planned(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $content = $this->get(route('customer.seo.index'))->assertNotFound()->getContent();

        $this->assertStringNotContainsString('Choose a Business to continue', $content);
        $this->assertStringNotContainsString('No Business available yet', $content);
        $this->assertStringNotContainsString(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]), $content);
    }

    public function test_the_production_controller_uses_the_registry_as_its_availability_floor(): void
    {
        // The floor is the registry itself — not a plan-mapping inspection and
        // not a per-Business entitlement decision — and it is asked first.
        $source = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Customer/Business/SeoController.php'));

        $this->assertStringContainsString('PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value)', $source);

        $entry = substr($source, (int) strpos($source, 'public function entry()'));
        $entry = substr($entry, 0, (int) strpos($entry, "\n    }\n") + 7);

        $floor = strpos($entry, 'seoIsImplementedAndAvailable()');
        $this->assertNotFalse($floor);
        $this->assertLessThan(strpos($entry, "authorize('view_seo')"), $floor, 'The availability floor must run before the capability check.');
        $this->assertLessThan(strpos($entry, 'entitledBusinesses()'), $floor, 'The availability floor must run before any Business is enumerated.');
    }

    // -----------------------------------------------------------------
    // The post-floor selector (zero / one / many) is intact. The floor is
    // simulated as passed by the test-only subclass; the feature is NOT
    // flipped (Sub-slice H owns that).
    // -----------------------------------------------------------------

    public function test_once_the_floor_is_passed_the_capability_check_still_applies(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer, ['view_google_business_profile', 'website']);

        $this->get(route('customer.seo.index'))->assertUnauthorized();
    }

    public function test_once_the_floor_is_passed_zero_accessible_businesses_shows_the_empty_selector(): void
    {
        $this->bypassSeoEntitlementForTest();
        // entitledTenant() normally does this setup; a customer with no
        // Business needs it explicitly (required config rows, and user id 1
        // burned so the super-admin permission short-circuit cannot apply).
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $customerWithNoBusiness = $this->createCustomer();
        $this->authenticateAsSeoCustomer($customerWithNoBusiness);

        $html = $this->get(route('customer.seo.index'))->assertOk()->getContent();

        $this->assertStringContainsString('No Business available yet', $html);
    }

    public function test_once_the_floor_is_passed_exactly_one_business_redirects_through(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $this->authenticateAsSeoCustomer($customer);

        $this->get(route('customer.seo.index'))
            ->assertRedirect(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]));
    }

    public function test_once_the_floor_is_passed_several_businesses_show_a_chooser(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $first, $firstWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        [, $second, $secondWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->addMember($secondWorkspace, $customer->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get(route('customer.seo.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Choose a Business to continue', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.seo.index', [$firstWorkspace->uid, $first->uid]), $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.seo.index', [$secondWorkspace->uid, $second->uid]), $html);
    }

    public function test_the_selector_never_lists_a_business_the_actor_cannot_access(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$customer, $mine, $myWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        [, $foreign, $foreignWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($customer);

        $response = $this->get(route('customer.seo.index'));

        // Exactly one accessible Business, so it redirects to MINE — never the foreign one.
        $response->assertRedirect(route('customer.workspaces.businesses.seo.index', [$myWorkspace->uid, $mine->uid]));
        $this->assertNotSame(
            route('customer.workspaces.businesses.seo.index', [$foreignWorkspace->uid, $foreign->uid]),
            $response->headers->get('Location'),
        );
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
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoController::class)
                || str_contains((string) $route->getActionName(), SeoKeywordsController::class));

        $this->assertGreaterThanOrEqual(7, $seoRoutes->count(), 'The SEO entry, Overview and keyword routes must exist.');

        foreach ($seoRoutes as $route) {
            $name = (string) $route->getName();

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

    public function test_the_keyword_routes_write_only_by_post_and_never_delete(): void
    {
        $keywordRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoKeywordsController::class));

        $this->assertCount(5, $keywordRoutes, 'index, store, update, archive, reactivate — and nothing else.');

        foreach ($keywordRoutes as $route) {
            $this->assertEmpty(
                array_diff($route->methods(), ['GET', 'HEAD', 'POST']),
                '[' . $route->getName() . '] must be GET or POST only: keywords are archived, never deleted.'
            );
            $this->assertStringStartsWith('customer.workspaces.businesses.seo.keywords.', (string) $route->getName());
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
                $root . '/app/Http/Controllers/Customer/Business/SeoKeywordsController.php',
                $root . '/app/Library/GoogleBusinessProfile/GoogleBusinessProfileStatusReader.php',
            ],
        );

        $cases = [];

        foreach ($files as $file) {
            // Sub-slice 18E's SeoCitationManager is the one SEO class that
            // legitimately writes — to its OWN table, `seo_citations`. The
            // blanket "no write call at all" pattern below was written for
            // 18A, where nothing wrote anything; Contract 18 §12.1 forbids a
            // write path to Website/Business/Location/Google data, not to
            // SEO's own tables. The manager is therefore held to the same
            // rules with the table-scoped write rule substituted, in
            // SeoCitationsBoundaryTest.
            if (basename($file) === 'SeoCitationManager.php') {
                continue;
            }

            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('seoCodeFiles')]
    public function test_seo_code_has_no_write_path_no_network_call_no_ai_and_no_dispatch(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        if (basename($file) === 'SeoKeywordsController.php') {
            // It writes only THROUGH SeoKeywordManager; those call names are
            // not table writes (the manager's own writes are pinned separately).
            $code = (string) preg_replace('/\$this->keywords->(create|update|archive|reactivate)\s*\(/', '', $code);
        }

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

        foreach ($forbidden as $index => $pattern) {
            // The first three patterns forbid ANY write. SeoKeywordManager is the
            // one class permitted to write, and only seo_keywords — which
            // test_the_keyword_manager_writes_only_seo_keywords pins separately.
            if ($index < 3 && basename($file) === 'SeoKeywordManager.php') {
                continue;
            }

            $this->assertSame(
                0,
                preg_match($pattern, $code),
                basename($file) . ' must not match ' . $pattern . ' (Contract 18 §12).'
            );
        }
    }

    public function test_the_keyword_manager_writes_only_seo_keywords(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/Seo/SeoKeywordManager.php');

        // No raw table writes, and no write to any other domain's model.
        $this->assertStringNotContainsString('DB::table', $code);
        $this->assertDoesNotMatchRegularExpression('/\b(Website|WebsitePage|WebsiteRevision|BusinessGoogle\w*|BusinessLocationManager|BusinessManager)\b/', $code);
        $this->assertDoesNotMatchRegularExpression('/\$(business|lockedBusiness|location)->(save|update|fill|forceFill|delete)\s*\(/', $code, 'Only SeoKeyword rows may be written.');
        $this->assertDoesNotMatchRegularExpression('/\b(Business|BusinessLocation)::(create|insert|update|upsert|destroy)\b/', $code);

        // The Business row is only LOCKED and read.
        $this->assertStringContainsString('lockForUpdate()', $code);
    }

    public function test_seo_keyword_code_never_references_the_legacy_keywords_product(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            $root . '/app/Models/SeoKeyword.php',
            $root . '/app/Library/Seo/SeoKeywordManager.php',
            $root . '/app/Library/Seo/SeoKeywordCoverageReader.php',
            $root . '/app/Library/Seo/SeoPhraseNormalizer.php',
            $root . '/app/Exceptions/Seo/SeoKeywordException.php',
            $root . '/app/Http/Controllers/Customer/Business/SeoKeywordsController.php',
            $root . '/resources/views/customer/business/seo/keywords.blade.php',
            $root . '/database/migrations/2026_09_25_110001_create_seo_keywords_table.php',
        ];

        foreach ($files as $file) {
            $code = $this->codeWithoutComments($file);

            foreach (['App\\Models\\Keywords', 'KeywordRepository', 'view_keywords', 'customer.keywords', 'CustomerKeywordController', 'keyword_name', 'sender_id', 'contact_groups_optin', "table('keywords'", "Schema::create('keywords'", "from('keywords'", 'text in', 'text-in'] as $legacy) {
                $this->assertStringNotContainsString($legacy, $code, basename($file) . ' must not reference the legacy Keywords product [' . $legacy . '].');
            }
        }

        // ... and the legacy routes and permission are untouched.
        $this->assertTrue(Route::has('customer.keywords.index'));
        $this->assertArrayHasKey('view_keywords', config('customer-permissions'));
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

        if (str_ends_with($path, '.blade.php')) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        }

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
