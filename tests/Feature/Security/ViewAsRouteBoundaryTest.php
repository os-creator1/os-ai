<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\Campaigns;
use App\Models\ViewAsSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1B — Correction Round 1, Corrections 4 and 5.
 *
 * View-as narrows EVERY authenticated customer route, not only those whose
 * URL literally carries `businessUid`. The inventories in
 * ViewAsRouteClassification and ViewAsProhibitedActions are closed: this
 * test enumerates the whole authenticated route universe and fails the
 * moment a route is left unclassified, so a new Business-capable route
 * cannot slip past the boundary. Behavioural cases then prove each shape
 * the router currently supports.
 */
class ViewAsRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // -----------------------------------------------------------------
    // Closed inventories
    // -----------------------------------------------------------------

    public function test_every_authenticated_customer_route_is_classified(): void
    {
        $classification = app(ViewAsRouteClassification::class);
        $universe = ViewAsRouteClassification::universe();
        $this->assertGreaterThan(400, count($universe), 'The authenticated route universe must be enumerated.');

        $unclassified = [];
        $counts = [];

        foreach ($universe as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $class = $classification->classify($route, $method);
                $counts[$class->value] = ($counts[$class->value] ?? 0) + 1;

                if ($class === ViewAsRouteClass::Unclassified) {
                    $unclassified[] = $method . ' ' . ($route->getName() ?: '(unnamed)') . ' /' . $route->uri();
                }
            }
        }

        $this->assertSame([], $unclassified, "Unclassified authenticated routes — add them to ViewAsRouteClassification deliberately:\n" . implode("\n", $unclassified));

        foreach (['business_scoped', 'safe', 'redirect_to_viewed', 'prohibited', 'denied'] as $expectedClass) {
            $this->assertArrayHasKey($expectedClass, $counts, 'Every class must be exercised by the current route table.');
        }
    }

    public function test_every_business_addressed_route_is_scoped_or_prohibited_never_safe(): void
    {
        $classification = app(ViewAsRouteClassification::class);

        foreach (ViewAsRouteClassification::universe() as $route) {
            if (! in_array('businessUid', $route->parameterNames(), true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $this->assertContains(
                    $classification->classify($route, $method),
                    [ViewAsRouteClass::BusinessScoped, ViewAsRouteClass::Prohibited],
                    ($route->getName() ?: $route->uri()) . ' addresses a Business and must be narrowed or prohibited.'
                );
            }
        }
    }

    public function test_the_safe_and_redirect_inventories_name_only_registered_routes(): void
    {
        foreach (ViewAsRouteClassification::SAFE as $name) {
            $this->assertTrue(Route::has($name), "Safe inventory names an unregistered route: {$name}.");
        }

        // Logout is not under the customer prefixes but must stay reachable
        // while viewing: it is the way the view ends on sign-out.
        $this->assertContains('logout', ViewAsRouteClassification::SAFE);
        $this->assertContains('logout', array_map(fn ($route) => $route->getName(), ViewAsRouteClassification::universe()));

        foreach (ViewAsRouteClassification::REDIRECT_TO_VIEWED as $entry => $target) {
            $this->assertTrue(Route::has($entry), "Redirect inventory names an unregistered entry: {$entry}.");
            $this->assertTrue(Route::has($target), "Redirect inventory names an unregistered target: {$target}.");
            $this->assertContains('businessUid', Route::getRoutes()->getByName($target)->parameterNames(), 'A redirect target must be Business-scoped.');
        }

        foreach (ViewAsProhibitedActions::EXACT as $name) {
            $this->assertTrue(Route::has($name), "Prohibited inventory names an unregistered route: {$name}.");
        }
    }

    /**
     * Correction 5 — every existing route in a §5.5 capability family is
     * prohibited: payer/funding, Workspace staff, plan/slots (including the
     * Slice 1A location-allocation family), phone resources, provider
     * credentials, deletions, view-as/context switching.
     */
    public function test_every_route_in_a_prohibited_capability_family_is_prohibited(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);
        $family = '/(usage-billing\.(payer|billing-contact|spend-cap|feature-limits|payment-method|top-up|auto-recharge)|workspaces\.members\.|ownership\.transfer|additional-business-slots\.|locations\.allocations\.|businesses\.store$|businesses\.reassign$|workspaces\.(store|rename|deactivate|reactivate)$|\.channels\.|^customer\.channels\.|gbp\.(connect|disconnect|refresh|bind|unbind)$|gbp\.oauth\.|numbers\.|senderid\.|keywords\.|subscriptions\.|sub_accounts\.|top_up\.|\.payment\.|callback\.|registers\.|view-as\.start$|context\.business\.switch$|switch_view$|login_as$|developer\.(generate|server|webhook)$|\.destroy$|\.delete$|\.batch_action$|\.release$|delete-contact-field$|account\.delete$|account\.top_up$|account\.pay$)/';
        $missed = [];

        foreach (ViewAsRouteClassification::universe() as $route) {
            $name = $route->getName() ?? '';

            if ($name === '' || preg_match($family, $name) !== 1) {
                continue;
            }

            if (in_array($name, ViewAsProhibitedActions::ALLOWED_READS, true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                if (! $prohibited->isProhibitedRoute($route, $method)) {
                    $missed[] = $method . ' ' . $name;
                }
            }
        }

        $this->assertSame([], $missed, "Capability-family routes that are not prohibited while viewing:\n" . implode("\n", $missed));

        // The unnamed payment / purchase routes are caught by their action.
        $this->assertTrue($prohibited->isProhibitedRoute($this->routeByAction('SubscriptionController@checkoutPurchase'), 'POST'));
        $this->assertTrue($prohibited->isProhibitedRoute($this->routeByAction('NumberController@payment'), 'POST'));
        $this->assertTrue($prohibited->isProhibitedRoute($this->routeByAction('AccountController@checkoutTopUp'), 'POST'));

        // The future Slice 1A allocation family is prohibited ahead of arrival.
        $this->assertContains('customer.workspaces.businesses.locations.allocations.', ViewAsProhibitedActions::PREFIXES);
    }

    public function test_reads_that_stay_available_while_viewing_are_only_the_listed_ones(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);

        foreach (ViewAsProhibitedActions::ALLOWED_READS as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertFalse($prohibited->isProhibitedRoute($route, 'GET'));
            $this->assertTrue($prohibited->isProhibitedRoute($route, 'POST'), 'A listed read never opens a write.');
        }
    }

    // -----------------------------------------------------------------
    // Behaviour per route shape while viewing
    // -----------------------------------------------------------------

    public function test_business_scoped_routes_are_narrowed_to_the_viewed_pair(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $sibling = $this->addBusiness($agency, $workspace, 'Sibling Client');
        [, $foreignBusiness, $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Foreign Client', 'Foreign Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        // Every currently registered GET shape with businessUid: the viewed pair renders or redirects, every other pair is 404.
        $shapes = [
            'customer.workspaces.businesses.analytics.overview',
            'customer.workspaces.businesses.outreach.campaigns',
            'customer.workspaces.businesses.automations.index',
            'customer.workspaces.businesses.gbp.index',
            'customer.workspaces.businesses.website.show',
            'customer.workspaces.businesses.contacts.index',
            'customer.workspaces.businesses.usage-billing.show',
            'customer.workspaces.businesses.knowledge-profile.show',
        ];

        foreach ($shapes as $name) {
            $this->assertContains($this->get(route($name, [$workspace->uid, $viewed->uid]))->getStatusCode(), [200, 302], $name . ' must be reachable for the viewed Business.');
            $this->get(route($name, [$workspace->uid, $sibling->uid]))->assertNotFound();
            $this->get(route($name, [$foreignWorkspace->uid, $foreignBusiness->uid]))->assertNotFound();
            // A mismatched Workspace/Business pair is refused even when the Business is the viewed one.
            $this->get(route($name, [$foreignWorkspace->uid, $viewed->uid]))->assertNotFound();
        }

        // Nested identifiers inside a Business-scoped route follow the same
        // narrowing: a real sibling campaign is still 404 while viewing,
        // and the viewed Business's own campaign is not.
        $siblingCampaign = Campaigns::create([
            'user_id' => $sibling->customer_id, 'business_id' => $sibling->id, 'campaign_name' => 'Sibling campaign',
            'message' => 'Hello', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE,
        ])->fresh();
        $viewedCampaign = Campaigns::create([
            'user_id' => $viewed->customer_id, 'business_id' => $viewed->id, 'campaign_name' => 'Viewed campaign',
            'message' => 'Hello', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE,
        ])->fresh();

        $this->get(route('customer.workspaces.businesses.outreach.campaigns.show', [$workspace->uid, $sibling->uid, $siblingCampaign->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.outreach.campaigns.show', [$workspace->uid, $viewed->uid, $viewedCampaign->uid]))->assertOk();
    }

    public function test_bare_module_entries_redirect_into_the_viewed_business(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Sibling Client');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        foreach (ViewAsRouteClassification::REDIRECT_TO_VIEWED as $entry => $target) {
            $this->get(route($entry))->assertRedirect(route($target, [$workspace->uid, $viewed->uid]));
        }
    }

    public function test_global_and_workspace_frame_routes_are_denied_while_viewing(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Sibling Client');
        $this->authenticateAs($agency);

        // Reachable before viewing…
        $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $this->get(route('customer.contacts.index'))->assertOk();
        // Slice 2B: the flat inbox is now a compatibility redirector. With
        // two Businesses it never guesses one — it sends the actor to the
        // chooser. Reachable, just no longer a page of its own.
        $this->get(route('customer.chatbox.index'))->assertRedirect(route('customer.workspaces.index'));

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        // …and outside the viewed Business while viewing.
        foreach ([
            route('customer.workspaces.show', $workspace->uid),
            route('customer.workspaces.index'),
            route('customer.workspaces.prospecting.overview', $workspace->uid),
            route('customer.contacts.index'),
            route('customer.chatbox.index'),
            route('customer.business.edit'),
            route('customer.opportunities.index'),
            route('customer.sms.quick_send'),
            route('customer.templates.index'),
            route('customer.blacklists.index'),
            route('customer.prospecting.index'),
            route('customer.developer.settings'),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        // Safe, account-independent behaviour stays available.
        $this->get(route('user.home'))->assertOk();
        $this->get(route('user.avatar'))->assertOk();
        $this->assertSame(1, ViewAsSession::query()->whereNull('ended_at')->count(), 'Denials never end the session.');
    }

    public function test_the_menu_offers_only_routes_reachable_inside_the_viewed_business(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Sibling Client');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $home = $this->home()->assertOk();
        $keys = $this->menuKeys($home->getContent());

        foreach (['home', 'contacts', 'inbox', 'automations', 'website', 'gbp', 'analytics', 'usage-billing'] as $expected) {
            $this->assertContains($expected, $keys);
        }

        foreach (['conversations', 'blocked-numbers', 'business-details', 'team', 'plan', 'advanced', 'accounts', 'prospecting', 'advisor'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "{$forbidden} is outside the viewed Business and must not be offered while viewing.");
        }

        foreach ($this->menuLinks($home->getContent()) as $link) {
            $this->assertContains($this->get($link)->getStatusCode(), [200, 302], 'Every offered link resolves while viewing: ' . $link);
        }
    }

    private function routeByAction(string $actionSuffix): RoutingRoute
    {
        foreach (Route::getRoutes() as $route) {
            $uses = $route->getAction('uses');

            if (is_string($uses) && str_ends_with($uses, $actionSuffix)) {
                return $route;
            }
        }

        $this->fail('No route uses ' . $actionSuffix);
    }
}
