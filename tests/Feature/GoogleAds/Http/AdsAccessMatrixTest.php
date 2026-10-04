<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — the authorization matrix for every route of the
 * module shell: guest, a Business that is not the caller's, no view
 * permission, no manage permission, Core vs Growth, a Business with no
 * entitlement at all, View As, and CSRF.
 *
 * Tenancy and entitlement failures are 404 (never 403). A missing capability
 * is the application-wide authorization failure (401), exactly as in GBP/SEO,
 * and is only ever reached AFTER tenancy, so a foreign Business never learns
 * whether the caller would have been permitted.
 */
class AdsAccessMatrixTest extends TestCase
{
    use CreatesAdsHttpFixtures;
    use RefreshDatabase;

    /** route name => [method, manage-class, module-only] */
    private const ROUTES = [
        'index' => ['GET', false, false],
        'series' => ['GET', false, false],
        'settings' => ['GET', false, false],
        'budget' => ['GET', false, true],
        'accounts' => ['GET', true, false],
        'settings.update' => ['POST', true, false],
        'connect' => ['POST', true, false],
        'accounts.select' => ['POST', true, false],
        'disconnect' => ['POST', true, false],
        'refresh' => ['POST', true, false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function hit(string $method, string $url, array $data = [])
    {
        return $method === 'GET' ? $this->get($url) : $this->post($url, $data);
    }

    public function test_guests_are_sent_to_sign_in_for_every_route(): void
    {
        [, $business, $workspace] = $this->adsHttpTenant();
        auth()->logout();

        foreach (self::ROUTES as $name => [$method]) {
            $status = $this->hit($method, $this->adsUrl($workspace, $business, $name))->getStatusCode();
            $this->assertContains($status, [302, 401], "[{$name}] must not be reachable by a guest.");
        }

        $this->assertContains($this->get(route('customer.ads.index'))->getStatusCode(), [302, 401]);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_another_customers_business_is_a_404_on_every_route(): void
    {
        [, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        [$stranger] = $this->adsHttpTenant();
        $this->asAdsUser($stranger);

        foreach (self::ROUTES as $name => [$method]) {
            $this->hit($method, $this->adsUrl($workspace, $business, $name), ['customer_id' => '1234567890'])
                ->assertNotFound("[{$name}] must be a 404 for a Business outside the caller's tenancy.");
        }

        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_a_wrong_workspace_uid_is_a_404(): void
    {
        [$customer, $business] = $this->adsHttpTenant();
        [, , $otherWorkspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->get(route('customer.workspaces.businesses.ads.index', [$otherWorkspace->uid, $business->uid]))->assertNotFound();
    }

    public function test_without_the_view_permission_every_page_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        $this->asAdsUser($customer, []);

        foreach (['index', 'settings', 'budget'] as $name) {
            $this->get($this->adsUrl($workspace, $business, $name))->assertStatus(401);
        }

        $this->getJson($this->adsUrl($workspace, $business, 'series'))->assertNotFound();
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_managing_without_viewing_is_still_refused_on_read_pages(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer, [self::MANAGE]);

        $this->get($this->adsUrl($workspace, $business))->assertStatus(401);
    }

    public function test_without_the_manage_permission_every_manage_route_is_refused_and_changes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business);
        $this->asAdsUser($customer, [self::VIEW]);

        foreach (self::ROUTES as $name => [$method, $manage]) {
            if (! $manage) {
                continue;
            }

            $this->hit($method, $this->adsUrl($workspace, $business, $name), ['customer_id' => '1234567890', 'monthly_budget_target' => '999'])
                ->assertStatus(401);
        }

        $this->assertNull($account->fresh()->monthly_budget_target_micros);
        $this->assertTrue($account->connection->fresh()->isActive(), 'disconnect was refused');
        $this->assertSame(0, $this->fakeAds->callCount());

        // View-only users can still read.
        $this->get($this->adsUrl($workspace, $business))->assertOk();
        $this->get($this->adsUrl($workspace, $business, 'settings'))->assertOk();
    }

    public function test_growth_opens_every_page_and_core_opens_overview_and_settings_but_not_budget(): void
    {
        [$growthCustomer, $growthBusiness, $growthWorkspace] = $this->adsHttpTenant(WorkspacePlanTier::Growth);
        $this->selectedAdsAccount($growthBusiness);
        $this->asAdsUser($growthCustomer);

        foreach (['index', 'settings', 'budget'] as $name) {
            $this->get($this->adsUrl($growthWorkspace, $growthBusiness, $name))->assertOk();
        }
        $this->getJson($this->adsUrl($growthWorkspace, $growthBusiness, 'series'))->assertOk();

        [$coreCustomer, $coreBusiness, $coreWorkspace] = $this->adsHttpTenant(WorkspacePlanTier::Core);
        $this->selectedAdsAccount($coreBusiness);
        $this->asAdsUser($coreCustomer);

        $this->get($this->adsUrl($coreWorkspace, $coreBusiness))->assertOk();
        $this->get($this->adsUrl($coreWorkspace, $coreBusiness, 'settings'))->assertOk();
        $this->getJson($this->adsUrl($coreWorkspace, $coreBusiness, 'series'))->assertOk();
        $this->get($this->adsUrl($coreWorkspace, $coreBusiness, 'budget'))->assertNotFound();
    }

    public function test_a_core_business_can_connect_and_use_the_connection_actions(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant(WorkspacePlanTier::Core);
        $this->asAdsUser($customer);

        $this->post($this->adsUrl($workspace, $business, 'connect'))->assertRedirect();
        $this->assertDatabaseHas('business_google_connections', ['business_id' => $business->id, 'product' => 'google_ads', 'state' => 'pending']);
    }

    public function test_a_business_with_no_entitlement_gets_404_everywhere_except_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->unentitledAdsTenant();
        $this->activeAdsConnection($business);
        $this->asAdsUser($customer);

        foreach (self::ROUTES as $name => [$method]) {
            if ($name === 'disconnect') {
                continue;
            }

            $this->hit($method, $this->adsUrl($workspace, $business, $name), ['customer_id' => '1234567890'])
                ->assertNotFound("[{$name}] must be a 404 without any Ads entitlement.");
        }

        $this->assertSame(0, $this->fakeAds->callCount());

        // Credentials are never trapped by a plan change: disconnect works.
        $this->post($this->adsUrl($workspace, $business, 'disconnect'))->assertRedirect();
        $this->assertDatabaseHas('business_google_connections', ['business_id' => $business->id, 'product' => 'google_ads', 'state' => 'disconnected']);
    }

    public function test_the_bare_entry_redirects_a_single_entitled_business_through(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $this->get(route('customer.ads.index'))->assertRedirect($this->adsUrl($workspace, $business));
    }

    public function test_the_bare_entry_shows_an_empty_state_when_no_business_is_entitled(): void
    {
        [$customer] = $this->unentitledAdsTenant();
        $this->asAdsUser($customer);

        $this->get(route('customer.ads.index'))->assertOk()->assertSee('No Business available yet');
    }

    public function test_the_bare_entry_needs_the_view_permission(): void
    {
        [$customer] = $this->adsHttpTenant();
        $this->asAdsUser($customer, []);

        $this->get(route('customer.ads.index'))->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------

    public function test_every_post_route_rejects_a_missing_csrf_token_with_419(): void
    {
        // The framework skips CSRF while unit-testing; switch that skip off
        // so the REAL middleware answers.
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->selectedAdsAccount($business);
        $this->asAdsUser($customer);

        foreach (self::ROUTES as $name => [$method]) {
            if ($method !== 'POST') {
                continue;
            }

            $this->post($this->adsUrl($workspace, $business, $name), ['customer_id' => '1234567890'])
                ->assertStatus(419);
        }

        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_there_is_no_get_route_for_any_state_changing_action(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        foreach (['connect', 'disconnect', 'refresh', 'accounts/select'] as $path) {
            $this->assertContains(
                $this->get($this->adsUrl($workspace, $business, 'index') . '/' . $path)->getStatusCode(),
                [404, 405],
            );
        }

        $this->assertDatabaseCount('business_google_connections', 0);
    }

    // ---------------------------------------------------------------
    // View As
    // ---------------------------------------------------------------

    public function test_view_as_may_read_the_pages_but_never_connect_select_disconnect_save_or_refresh(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);
        $classification = app(ViewAsRouteClassification::class);
        $prefix = 'customer.workspaces.businesses.ads.';

        foreach (['index', 'series', 'budget', 'settings'] as $read) {
            $route = Route::getRoutes()->getByName($prefix . $read);
            $this->assertFalse($prohibited->isProhibitedRoute($route, 'GET'), "[{$read}] stays viewable.");
            $this->assertSame(ViewAsRouteClass::BusinessScoped, $classification->classify($route, 'GET'));
        }

        foreach (['connect', 'accounts.select', 'disconnect', 'settings.update', 'refresh'] as $write) {
            $route = Route::getRoutes()->getByName($prefix . $write);
            $this->assertTrue($prohibited->isProhibitedRoute($route, 'POST'), "[{$write}] is prohibited while viewing.");
            $this->assertSame(ViewAsRouteClass::Prohibited, $classification->classify($route, 'POST'));
        }

        // Listing the client's accessible Google Ads accounts is a provider call too.
        $this->assertTrue($prohibited->isProhibitedRoute(Route::getRoutes()->getByName($prefix . 'accounts'), 'GET'));

        $this->assertTrue($prohibited->isProhibitedRoute(Route::getRoutes()->getByName('customer.ads.oauth.callback'), 'GET'));
        $this->assertSame(ViewAsRouteClass::RedirectToViewed, $classification->classifyByName('customer.ads.index'));
        $this->assertSame('customer.workspaces.businesses.ads.index', $classification->redirectTargetFor('customer.ads.index'));
    }

    public function test_view_as_prohibits_later_mutation_routes_by_pattern(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);

        foreach ([
            'customer.workspaces.businesses.ads.campaigns.status',
            'customer.workspaces.businesses.ads.keywords.status',
            'customer.workspaces.businesses.ads.search-terms.negative',
            'customer.workspaces.businesses.ads.search-terms.ignore',
            'customer.workspaces.businesses.ads.some.future.write',
        ] as $name) {
            $route = (new RoutingRoute('POST', 'x/{workspaceUid}/{businessUid}', fn () => null))->name($name);

            $this->assertTrue($prohibited->isProhibitedRoute($route, 'POST'), "[{$name}] must be covered the moment it is registered.");
            $this->assertFalse($prohibited->isProhibitedRoute($route, 'GET'), "[{$name}] as a read stays viewable.");
        }
    }
}
