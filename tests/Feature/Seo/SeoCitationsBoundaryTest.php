<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\SeoCitationController;
use App\Http\Middleware\CustomerAccountAccessGate;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18E — the structural guarantees: fail-closed while
 * Planned, naming, and a source-boundary test that the Citations code has no
 * network, AI, queue or Website/Business/Location/Google write path and
 * reaches Google only through the GBP read model (Contract 18 §12, §16
 * T-SEO-BOUND / T-SEO-NAMING / T-SEO-LOCK).
 */
class SeoCitationsBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    // -----------------------------------------------------------------
    // Planned => fail-closed. Sub-slice 18E flips NOTHING.
    // -----------------------------------------------------------------

    public function test_both_seo_features_remain_planned_and_no_platform_feature_was_added(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoModule->value));
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value));
        // No case was added: the total is pinned by EntitlementEnumsTest, and
        // §10.3 says Citations uses the existing SeoModule case.
        $this->assertNotContains('seo_citations', array_map(fn (PlatformFeature $feature) => $feature->value, PlatformFeature::cases()));
    }

    public function test_the_real_citations_routes_are_404_for_every_tier_while_seo_module_is_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business, $workspace] = $this->entitledTenant($tier);
            $location = $this->publicStorefront($business);
            $this->authenticateAsSeoCustomer($customer);

            $this->get($this->citationsUrl($workspace, $business))->assertNotFound();
            $this->from($this->citationsUrl($workspace, $business))
                ->put($this->citationUpdateUrl($workspace, $business, (string) $location->uid, 'bing_places'), $this->citationInput())
                ->assertNotFound();
        }

        $this->assertSame(0, \App\Models\SeoCitation::query()->count(), 'A fail-closed write must persist nothing.');
    }

    public function test_the_production_controller_gates_on_the_seo_module_feature_not_the_overview_feature(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Customer/Business/SeoCitationController.php');

        // §10.3 — two independent decisions, never merged.
        $this->assertStringContainsString('PlatformFeature::SeoModule->value', $source);
        $this->assertStringNotContainsString('SeoBasicVisibility', $source);
        $this->assertStringContainsString("authorize('view_seo')", $source);
        $this->assertStringContainsString("authorize('manage_seo')", $source);
    }

    // -----------------------------------------------------------------
    // Naming, methods, locked accounts, View As
    // -----------------------------------------------------------------

    public function test_citation_routes_are_named_inside_the_seo_namespace_and_never_as_legacy_keywords(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoCitationController::class));

        $this->assertSame(
            ['customer.workspaces.businesses.seo.citations.index', 'customer.workspaces.businesses.seo.citations.update'],
            $routes->map(fn ($route) => $route->getName())->sort()->values()->all()
        );

        foreach ($routes as $route) {
            $this->assertFalse(str_starts_with((string) $route->getName(), 'customer.keywords.'));
            $this->assertStringContainsString('/seo/citations', $route->uri());
        }
    }

    public function test_the_only_write_route_is_a_single_put_and_reads_are_get(): void
    {
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), SeoCitationController::class))
            ->mapWithKeys(fn ($route) => [$route->getName() => array_values(array_diff($route->methods(), ['HEAD']))]);

        $this->assertSame(['GET'], $methods['customer.workspaces.businesses.seo.citations.index']);
        $this->assertSame(['PUT'], $methods['customer.workspaces.businesses.seo.citations.update']);
    }

    public function test_no_citation_route_is_on_the_locked_account_allowlist(): void
    {
        $allowed = (new ReflectionClass(CustomerAccountAccessGate::class))->getReflectionConstant('ALLOWED_ROUTE_NAMES')->getValue();

        foreach ($allowed as $name) {
            $this->assertStringNotContainsString('seo', (string) $name, 'Contract 18 §10.6: nothing SEO is added to the Locked allowlist.');
        }
    }

    public function test_the_routes_carry_no_implicit_model_binding(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_contains((string) $route->getActionName(), SeoCitationController::class)) {
                continue;
            }

            $this->assertSame([], $route->signatureParameters(['subClass' => \Illuminate\Database\Eloquent\Model::class]));
        }
    }

    // -----------------------------------------------------------------
    // Source boundary
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function citationCodeFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [
            $root . '/app/Library/Seo/SeoCitationManager.php',
            $root . '/app/Library/Seo/SeoNapComparator.php',
            $root . '/app/Library/Seo/SeoLinkSafety.php',
            $root . '/app/Library/Seo/SeoCitationRow.php',
            $root . '/app/Library/Seo/SeoCitationLocationSection.php',
            $root . '/app/Http/Controllers/Customer/Business/SeoCitationController.php',
            $root . '/app/Models/SeoCitation.php',
            $root . '/app/Models/SeoCitationDirectory.php',
        ];

        $cases = [];

        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('citationCodeFiles')]
    public function test_citation_code_has_no_network_no_ai_no_queue_no_website_seam_and_no_google_client(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        $this->assertNotSame('', $code);

        $forbidden = [
            // Any network call — listing_url is never fetched, by anything.
            '/\bHttp::/', '/GuzzleHttp/', '/\bcurl_/', '/file_get_contents\s*\(/', '/fopen\s*\(/', '/stream_context/',
            '/get_headers\s*\(/', '/fsockopen\s*\(/', '/Socialite/', '/HttpClient/', '/SoapClient/',
            // Any Google client or direct GBP data access — only the read model.
            '/GoogleBusinessProfileReadClient/', '/HttpGoogleBusinessProfileReadClient/', '/FakeGoogleBusinessProfileReadClient/',
            '/BusinessGoogleConnection/', '/BusinessGoogleLocation/', '/BusinessGoogleOperation/',
            '/GoogleBusinessProfileComparator/', '/GoogleBusinessProfileMirrorService/', '/GoogleBusinessProfileBindingManager/',
            // Any AI provider or gateway.
            '/OpenAI/i', '/App\\\\Library\\\\Ai\\\\/', '/AiGateway/', '/Anthropic/i',
            // Any queued / dispatched / cached / scheduled side effect.
            '/\bdispatch\s*\(/', '/::dispatch\b/', '/\bBus::/', '/\bQueue::/', '/\bevent\s*\(/', '/\bCache::/', '/\bSchedule::/',
            // Any Website write seam.
            '/WebsiteDraftPageService/', '/WebsitePublisher/', '/\bWebsite\w*::/',
            // Any raw SQL write, and any DB facade at all in citation code.
            '/\bDB::/',
            // Write calls against the protected records.
            '/\$(business|location|workspace|website|connection|binding)\w*->(save|update|delete|forceDelete|touch|increment|decrement|fill)\s*\(/',
            '/\b(Business|BusinessLocation|Workspace)::(create|insert|upsert|updateOrCreate|firstOrCreate|destroy|forceCreate|query\(\)->(update|delete|insert))/',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), basename($file) . ' must not match ' . $pattern . ' (Contract 18 §12, §8.5).');
        }
    }

    public function test_the_manager_reaches_google_only_through_the_status_reader(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/Seo/SeoCitationManager.php');

        $this->assertStringContainsString('GoogleBusinessProfileStatusReader', $code);
        $this->assertStringContainsString('->forBusiness(', $code);

        // Of everything in the GBP namespace, only the reader and the pure
        // address predicate may be named.
        preg_match_all('/App\\\\Library\\\\GoogleBusinessProfile\\\\(\w+)/', $code, $matches);
        $this->assertEqualsCanonicalizing(
            ['GoogleBusinessProfileReadMask', 'GoogleBusinessProfileStatusReader'],
            array_values(array_unique($matches[1])),
        );
    }

    public function test_the_manager_writes_only_the_seo_citations_model(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/Seo/SeoCitationManager.php');

        preg_match_all('/\b([A-Z]\w+)::(?:query|create|firstOrNew|firstOrCreate|updateOrCreate|insert|upsert)\b/', $code, $matches);

        // Reads of the directory reference table and writes of citations are
        // the only Eloquent entry points; no other model is touched.
        $this->assertEqualsCanonicalizing(['SeoCitation', 'SeoCitationDirectory'], array_values(array_unique($matches[1])));
        $this->assertSame(0, preg_match('/SeoCitationDirectory::(create|firstOrNew|firstOrCreate|updateOrCreate|insert|upsert)/', $code), 'Customers never write the reference table.');
    }

    public function test_the_citation_view_has_no_unescaped_output_and_no_script(): void
    {
        $view = file_get_contents(dirname(__DIR__, 3) . '/resources/views/customer/business/seo/citations.blade.php');
        // The header comment names the forbidden constructs; check the markup only.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $view);

        $this->assertStringNotContainsString('{!!', $markup);
        $this->assertStringNotContainsString('<script', strtolower($markup));
        $this->assertStringNotContainsString('@php echo', $markup);
        $this->assertDoesNotMatchRegularExpression('/\bhref="\{\{\s*\$row->citation/', $markup, 'A listing link must come from the checked safeListingUrl only.');
        $this->assertSame(
            substr_count($markup, 'target="_blank"'),
            substr_count($markup, 'rel="{{ $rel }}"'),
            'Every external link must carry the contracted rel.'
        );
    }

    public function test_the_directory_reference_table_has_no_customer_write_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('citation-director', $route->uri());
            $this->assertStringNotContainsString('citation_director', (string) $route->getName());
        }
    }

    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
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
