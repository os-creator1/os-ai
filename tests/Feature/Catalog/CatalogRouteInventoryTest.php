<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Customer\Business\CatalogItemsController;
use App\Http\Controllers\Customer\Business\CatalogLocationOffersController;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesCatalogRequests;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.E — the catalog's route inventory.
 *
 * The authorization matrix is only as complete as the route list it iterates.
 * This test closes the loop: every registered catalog route must be in that
 * list (so none can be added without the matrix covering it), must run the §6
 * chain, must sit behind authentication, and must be classified for View As.
 */
class CatalogRouteInventoryTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private const PREFIX = 'customer.workspaces.businesses.catalog.';

    /** @return list<\Illuminate\Routing\Route> */
    private function catalogRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), self::PREFIX)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function test_the_matrix_covers_exactly_the_registered_catalog_routes(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);

        $registered = array_map(
            fn ($route) => substr((string) $route->getName(), strlen(self::PREFIX)),
            $this->catalogRoutes()
        );
        $covered = array_keys($this->catalogEveryRoute($workspace, $business, $location, $item));

        sort($registered);
        sort($covered);

        $this->assertCount(12, $registered);
        $this->assertSame(
            $registered,
            $covered,
            'A catalog route exists that the authorization matrix does not cover (or vice versa).'
        );
    }

    public function test_every_catalog_route_runs_the_gate_chain_through_one_of_the_two_controllers(): void
    {
        $this->assertContains(AuthorizesCatalogRequests::class, class_uses(CatalogItemsController::class));
        $this->assertContains(AuthorizesCatalogRequests::class, class_uses(CatalogLocationOffersController::class));

        foreach ($this->catalogRoutes() as $route) {
            $controller = strstr((string) $route->getActionName(), '@', true);

            $this->assertContains(
                ltrim((string) $controller, '\\'),
                [CatalogItemsController::class, CatalogLocationOffersController::class],
                (string) $route->getName() . ' must be served by a catalog controller.'
            );
        }
    }

    public function test_every_catalog_route_is_behind_authentication(): void
    {
        foreach ($this->catalogRoutes() as $route) {
            $this->assertContains('auth', $route->middleware(), (string) $route->getName() . ' must require authentication.');
        }
    }

    public function test_an_unauthenticated_request_is_redirected_to_login_on_every_route(): void
    {
        [, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);

        foreach ($this->catalogEveryRoute($workspace, $business, $location, $item) as $label => [$method, $url, $payload]) {
            $response = $method === 'GET' ? $this->get($url) : $this->post($url, $payload);

            $this->assertContains($response->getStatusCode(), [302, 401, 419], "[{$label}] must not be reachable unauthenticated.");
            $this->assertStringNotContainsString($item->name, (string) $response->getContent());
        }

        $this->assertSame(1, \App\Models\CatalogItem::query()->where('business_id', $business->id)->count());
    }

    /**
     * `{businessUid}` in every path is what classifies these as
     * Business-scoped for View As; an unclassified route would fail
     * ViewAsRouteBoundaryTest, so this pins the classification explicitly.
     */
    public function test_every_catalog_route_is_classified_business_scoped_for_view_as(): void
    {
        $classification = app(ViewAsRouteClassification::class);

        foreach ($this->catalogRoutes() as $route) {
            $this->assertContains('businessUid', $route->parameterNames(), (string) $route->getName());

            foreach (['GET', 'POST'] as $method) {
                if (! in_array($method, $route->methods(), true)) {
                    continue;
                }

                $this->assertSame(
                    ViewAsRouteClass::BusinessScoped,
                    $classification->classify($route, $method),
                    (string) $route->getName() . " ({$method}) must be Business-scoped."
                );
            }
        }
    }

    public function test_the_literal_locations_segment_is_never_captured_as_an_item_uid(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        // Declared before the {catalogItemUid} routes: `/locations` must reach
        // the Location list, not an "edit item named locations" 404.
        $this->get($this->catalogRoute('locations.index', $workspace, $business))->assertOk();
        $this->get($this->catalogRoute('create', $workspace, $business))->assertOk();
    }
}
