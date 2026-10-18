<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Middleware\VerifyCsrfToken;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\BusinessMetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §8) — the authorization matrix of every
 * Meta route of lane U1 (and the module-only read pages of U2): guest, a
 * foreign Business, missing capabilities, Core vs Growth vs Agency, a
 * Business with no entitlement at all, View As and CSRF.
 *
 * Tenancy and entitlement failures are 404 (never 403); a missing capability
 * is the application-wide 401, reached only AFTER tenancy.
 */
class MetaAdsAccessMatrixTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    /** route name => [method, manage-class, module-only] */
    private const ROUTES = [
        'index' => ['GET', false, false],
        'series' => ['GET', false, false],
        'settings' => ['GET', false, false],
        'accounts' => ['GET', true, false],
        'settings.update' => ['POST', true, false],
        'connect' => ['POST', true, false],
        'accounts.select' => ['POST', true, false],
        'disconnect' => ['POST', true, false],
        'refresh' => ['POST', true, false],
        'campaigns.index' => ['GET', false, true],
        'ad-sets.index' => ['GET', false, true],
        'ads.index' => ['GET', false, true],
        'recommendations.index' => ['GET', false, true],
        'leads.index' => ['GET', false, true],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp(withGoogle: true);
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private function hit(string $method, string $url, array $data = [])
    {
        return $method === 'GET' ? $this->get($url) : $this->post($url, $data);
    }

    public function test_guests_are_sent_to_sign_in_for_every_route(): void
    {
        [, $business, $workspace] = $this->metaHttpTenant();
        auth()->logout();

        foreach (self::ROUTES as $name => [$method]) {
            $status = $this->hit($method, $this->metaPage($workspace, $business, $name))->getStatusCode();
            $this->assertContains($status, [302, 401], "[{$name}] must not be reachable by a guest.");
        }

        $this->assertContains($this->get($this->channelsUrl($workspace, $business))->getStatusCode(), [302, 401]);
        $this->assertContains($this->get(route('customer.ads.meta.oauth.callback'))->getStatusCode(), [302, 401, 404]);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_another_customers_business_is_a_404_on_every_route(): void
    {
        [, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        [$stranger] = $this->metaHttpTenant(name: 'Stranger Co');
        $this->asMetaUser($stranger);

        foreach (self::ROUTES as $name => [$method]) {
            $this->hit($method, $this->metaPage($workspace, $business, $name), ['account_id' => '1234567890123456'])
                ->assertNotFound("[{$name}] must be a 404 for a Business outside the caller's tenancy.");
        }

        $this->get($this->channelsUrl($workspace, $business))->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_a_wrong_workspace_uid_is_a_404(): void
    {
        [$customer, $business] = $this->metaHttpTenant();
        [, , $otherWorkspace] = $this->metaHttpTenant(name: 'Other Co');
        $this->asMetaUser($customer);

        $this->get(route('customer.workspaces.businesses.ads.meta.index', [$otherWorkspace->uid, $business->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.ads.overview', [$otherWorkspace->uid, $business->uid]))->assertNotFound();
    }

    public function test_without_the_view_permission_every_page_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        $this->asMetaUser($customer, []);

        foreach (['index', 'settings', 'campaigns.index', 'ad-sets.index', 'ads.index', 'recommendations.index', 'leads.index'] as $name) {
            $this->get($this->metaPage($workspace, $business, $name))->assertStatus(401);
        }

        $this->getJson($this->metaPage($workspace, $business, 'series'))->assertNotFound();
        // The cross-channel overview needs at least one provider capability.
        $this->get($this->channelsUrl($workspace, $business))->assertStatus(401);
        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_managing_without_viewing_is_still_refused_on_read_pages(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer, [self::META_MANAGE]);

        $this->get($this->metaPage($workspace, $business))->assertStatus(401);
    }

    public function test_without_the_manage_permission_every_manage_route_is_refused_and_changes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer, [self::META_VIEW]);

        foreach (self::ROUTES as $name => [$method, $manage]) {
            if (! $manage) {
                continue;
            }

            $this->hit($method, $this->metaPage($workspace, $business, $name), ['account_id' => '1234567890123456', 'monthly_budget_target' => '999'])
                ->assertStatus(401);
        }

        $this->assertNull($account->fresh()->monthly_budget_target_micros);
        $this->assertTrue($account->connection->fresh()->isActive(), 'disconnect was refused');
        $this->assertSame(0, $this->fakeMeta->callCount());

        // View-only users can still read.
        $this->get($this->metaPage($workspace, $business))->assertOk();
        $this->get($this->metaPage($workspace, $business, 'settings'))->assertOk();
    }

    public function test_growth_opens_every_page_and_core_opens_only_overview_series_and_settings(): void
    {
        [$growthCustomer, $growthBusiness, $growthWorkspace] = $this->metaHttpTenant(WorkspacePlanTier::Growth, 'Growth Co');
        $this->metaSelected($growthBusiness);
        $this->asMetaUser($growthCustomer);

        foreach (['index', 'settings', 'campaigns.index', 'ad-sets.index', 'ads.index', 'recommendations.index', 'leads.index'] as $name) {
            $this->get($this->metaPage($growthWorkspace, $growthBusiness, $name))->assertOk();
        }
        $this->getJson($this->metaPage($growthWorkspace, $growthBusiness, 'series'))->assertOk();
        $this->get($this->channelsUrl($growthWorkspace, $growthBusiness))->assertOk();

        [$coreCustomer, $coreBusiness, $coreWorkspace] = $this->metaHttpTenant(WorkspacePlanTier::Core, 'Core Co');
        $this->metaSelected($coreBusiness);
        $this->asMetaUser($coreCustomer);

        $this->get($this->metaPage($coreWorkspace, $coreBusiness))->assertOk();
        $this->get($this->metaPage($coreWorkspace, $coreBusiness, 'settings'))->assertOk();
        $this->getJson($this->metaPage($coreWorkspace, $coreBusiness, 'series'))->assertOk();
        $this->get($this->channelsUrl($coreWorkspace, $coreBusiness))->assertOk();

        foreach (['campaigns.index', 'ad-sets.index', 'ads.index', 'recommendations.index', 'leads.index'] as $name) {
            $this->get($this->metaPage($coreWorkspace, $coreBusiness, $name))->assertNotFound();
        }
    }

    public function test_the_core_sub_navigation_hides_the_module_pages_and_growth_shows_them(): void
    {
        [$coreCustomer, $coreBusiness, $coreWorkspace] = $this->metaHttpTenant(WorkspacePlanTier::Core, 'Core Co');
        $this->metaSelected($coreBusiness);
        $this->asMetaUser($coreCustomer);

        $core = $this->get($this->metaPage($coreWorkspace, $coreBusiness))->assertOk()->getContent();
        $this->assertStringContainsString('data-nav="overview"', $core);
        $this->assertStringContainsString('data-nav="settings"', $core);
        foreach (['campaigns', 'ad-sets', 'ads', 'recommendations', 'leads'] as $key) {
            $this->assertStringNotContainsString('data-nav="' . $key . '"', $core, "[{$key}] must be hidden for Core");
        }

        [$growthCustomer, $growthBusiness, $growthWorkspace] = $this->metaHttpTenant(WorkspacePlanTier::Growth, 'Growth Co');
        $this->metaSelected($growthBusiness);
        $this->asMetaUser($growthCustomer);

        $growth = $this->get($this->metaPage($growthWorkspace, $growthBusiness))->assertOk()->getContent();
        foreach (['overview', 'campaigns', 'ad-sets', 'ads', 'recommendations', 'leads', 'settings'] as $key) {
            $this->assertStringContainsString('data-nav="' . $key . '"', $growth, "[{$key}] must be offered to Growth");
        }
    }

    public function test_an_agency_business_of_its_own_opens_every_page(): void
    {
        $topology = $this->createAgencyManagedClient();
        $business = $topology['agencyBusiness'];
        $workspace = $topology['agencyWorkspace'];
        $this->metaSelected($business);
        $this->asMetaUser($topology['agencyOwner']);

        foreach (['index', 'settings', 'campaigns.index', 'ad-sets.index', 'ads.index', 'recommendations.index', 'leads.index'] as $name) {
            $this->get($this->metaPage($workspace, $business, $name))->assertOk();
        }

        $this->get($this->channelsUrl($workspace, $business))->assertOk();
    }

    public function test_a_core_business_can_connect_and_use_the_connection_actions(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant(WorkspacePlanTier::Core);
        $this->asMetaUser($customer);

        $this->post($this->metaPage($workspace, $business, 'connect'))->assertRedirect();
        $this->assertDatabaseHas('business_meta_connections', ['business_id' => $business->id, 'state' => 'pending']);
    }

    public function test_a_business_with_no_entitlement_gets_404_everywhere_except_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->unentitledMetaTenant();
        $this->activeMetaConnection($business);
        $this->asMetaUser($customer);

        foreach (self::ROUTES as $name => [$method]) {
            if ($name === 'disconnect') {
                continue;
            }

            $this->hit($method, $this->metaPage($workspace, $business, $name), ['account_id' => '1234567890123456'])
                ->assertNotFound("[{$name}] must be a 404 without any Ads entitlement.");
        }

        $this->get($this->channelsUrl($workspace, $business))->assertNotFound();
        $this->assertSame(0, $this->fakeMeta->callCount());

        // Credentials are never trapped by a plan change: disconnect works.
        $this->post($this->metaPage($workspace, $business, 'disconnect'))->assertRedirect();
        $this->assertDatabaseHas('business_meta_connections', ['business_id' => $business->id, 'state' => 'disconnected']);
        $this->assertNull(BusinessMetaConnection::query()->where('business_id', $business->id)->firstOrFail()->access_token_encrypted);
    }

    public function test_the_sidebar_has_exactly_one_ads_parent_and_no_provider_named_item(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        $this->asMetaUser($customer);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $shell = $this->shellText($html);

        foreach (['Meta Ads', 'Facebook Ads', 'Instagram Ads'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $shell, "the sidebar must not carry a [{$forbidden}] item");
        }

        $this->assertSame(1, preg_match_all('/\bAds\b/', $shell), 'one Ads parent only');
    }

    // ---------------------------------------------------------------
    // CSRF
    // ---------------------------------------------------------------

    public function test_every_post_route_rejects_a_missing_csrf_token_with_419(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->metaSelected($business);
        $this->asMetaUser($customer);

        foreach (self::ROUTES as $name => [$method]) {
            if ($method !== 'POST') {
                continue;
            }

            $this->post($this->metaPage($workspace, $business, $name), ['account_id' => '1234567890123456'])->assertStatus(419);
        }

        $this->assertSame(0, $this->fakeMeta->callCount());
    }

    public function test_there_is_no_get_route_for_any_state_changing_action(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $this->asMetaUser($customer);

        foreach (['connect', 'disconnect', 'refresh', 'accounts/select'] as $path) {
            $this->assertContains(
                $this->get($this->metaPage($workspace, $business, 'index') . '/' . $path)->getStatusCode(),
                [404, 405],
            );
        }

        $this->assertDatabaseCount('business_meta_connections', 0);
    }

    // ---------------------------------------------------------------
    // View As
    // ---------------------------------------------------------------

    public function test_view_as_may_read_every_get_page_and_never_a_meta_write(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);
        $classification = app(ViewAsRouteClassification::class);
        $prefix = 'customer.workspaces.businesses.ads.';

        foreach (['overview', 'meta.index', 'meta.series', 'meta.settings', 'meta.campaigns.index', 'meta.ad-sets.index', 'meta.ads.index', 'meta.recommendations.index', 'meta.leads.index'] as $read) {
            $route = Route::getRoutes()->getByName($prefix . $read);
            $this->assertNotNull($route, "[{$read}] must be registered.");
            $this->assertFalse($prohibited->isProhibitedRoute($route, 'GET'), "[{$read}] stays viewable.");
            $this->assertSame(ViewAsRouteClass::BusinessScoped, $classification->classify($route, 'GET'), "[{$read}] is business-addressed.");
        }

        // Every Meta non-GET route is prohibited while viewing, whatever its name.
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, $prefix . 'meta.')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                $checked++;
                $this->assertTrue($prohibited->isProhibitedRoute($route, $method), "[{$name}] ({$method}) is prohibited while viewing.");
                $this->assertSame(ViewAsRouteClass::Prohibited, $classification->classify($route, $method));
            }
        }
        $this->assertGreaterThanOrEqual(11, $checked, 'connect, select, disconnect, settings, refresh and six pause/resume routes');

        // Listing the client's accessible ad accounts is a provider call too.
        $this->assertTrue($prohibited->isProhibitedRoute(Route::getRoutes()->getByName($prefix . 'meta.accounts'), 'GET'));

        $callback = Route::getRoutes()->getByName('customer.ads.meta.oauth.callback');
        $this->assertNotNull($callback);
        $this->assertTrue($prohibited->isProhibitedRoute($callback, 'GET'));
        $this->assertSame(ViewAsRouteClass::Prohibited, $classification->classify($callback, 'GET'));

        $this->assertSame(ViewAsRouteClass::RedirectToViewed, $classification->classifyByName('customer.ads.index'));
        $this->assertSame('customer.workspaces.businesses.ads.overview', $classification->redirectTargetFor('customer.ads.index'));
    }

    public function test_an_agency_viewing_a_client_reads_but_cannot_connect_select_disconnect_save_or_refresh(): void
    {
        // The platform's View As fixtures view the Agency workspace's Business as "the client".
        [$agency, $client, $clientWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->metaSelected($client);

        $this->authenticateAs($agency);
        $this->startViewAs($clientWorkspace, $client)->assertRedirect(route('user.home'));

        $this->get($this->metaPage($clientWorkspace, $client))->assertOk();
        $this->get($this->channelsUrl($clientWorkspace, $client))->assertOk();

        foreach (['connect', 'accounts.select', 'disconnect', 'settings.update', 'refresh'] as $write) {
            $response = $this->post($this->metaPage($clientWorkspace, $client, $write), ['account_id' => '1234567890123456', 'monthly_budget_target' => '500']);
            $this->assertContains($response->getStatusCode(), [302, 403, 404], "[{$write}] must be refused while viewing");

            if ($response->getStatusCode() === 302) {
                $this->assertSame(route('user.home'), $response->headers->get('Location'));
            }
        }

        $account = \App\Models\MetaAdsAccount::query()->where('business_id', $client->id)->firstOrFail();
        $this->assertNull($account->monthly_budget_target_micros);
        $this->assertTrue(BusinessMetaConnection::query()->where('business_id', $client->id)->firstOrFail()->isActive());
        $this->assertSame(0, $this->fakeMeta->callCount());
    }
}
