<?php

namespace Tests\Feature\Acquisition;

use App\Library\ViewAs\ViewAsProhibitedActions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * View As and route hygiene for the new surfaces: reading Goals & economics is
 * ordinary support work, changing them (economics, links, assignment, pause) is
 * not, and the external-website screens expose only the routes the contract names.
 */
class GoalsViewAsAndRoutesTest extends TestCase
{
    private function prohibited(string $name, string $method): bool
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);

        return app(ViewAsProhibitedActions::class)->isProhibitedRoute($route, $method);
    }

    public function test_viewing_goals_is_allowed_but_every_change_is_prohibited_while_viewing_as_a_client(): void
    {
        $p = 'customer.workspaces.businesses.ads.goals';

        $this->assertFalse($this->prohibited($p, 'GET'));

        foreach (['', '.campaigns', '.economics', '.links', '.toggle'] as $suffix) {
            $this->assertTrue($this->prohibited($p.$suffix, 'POST'), $p.$suffix);
        }
    }

    public function test_the_external_website_module_has_exactly_the_contracted_routes(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()
            ->filter(fn (string $n) => str_starts_with($n, 'customer.workspaces.businesses.website.external.') || $n === 'customer.workspaces.businesses.website.mode.choose')
            ->values()->all();

        $this->assertEqualsCanonicalizing([
            'customer.workspaces.businesses.website.mode.choose',
            'customer.workspaces.businesses.website.external.overview',
            'customer.workspaces.businesses.website.external.audit',
            'customer.workspaces.businesses.website.external.pages',
            'customer.workspaces.businesses.website.external.page',
            'customer.workspaces.businesses.website.external.settings',
            'customer.workspaces.businesses.website.external.settings.update',
            'customer.workspaces.businesses.website.external.crawl',
        ], $names, 'No publish, template, domain, rebuild or "apply fix" route exists for an external website.');
    }
}
