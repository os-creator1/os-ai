<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ViewAsSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agency\Support\SeedsAgencyFleetModuleData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Agency V1 final acceptance — tenant isolation across a whole fleet.
 *
 * One Agency (Northwind) with its OWN Business and two managed clients, a
 * second, unrelated Agency with a client of its own, and a plain Business. Every
 * Business module holds a record carrying its Business's unique marker. The
 * tests then walk the product as each kind of actor and prove that a marker only
 * ever appears in the Business it belongs to:
 *
 *   - the Agency owner "viewing as" Client A sees Client A and nothing else —
 *     not Client B, not the Agency's own Business, not another Agency's client;
 *   - Client A's own owner sees only Client A, and none of the Agency;
 *   - another Agency, a plain Business owner and forged ids fail closed;
 *   - View As is never Platform Owner impersonation.
 *
 * The per-module tenancy tests already exist next to each module; this is the
 * integrated proof across all of them at once, under the cross-Workspace Agency
 * View As path that none of them exercise together.
 */
class AgencyV1FinalIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use SeedsAgencyFleetModuleData;

    private const MARK_AGENCY = 'Mk-AGY';

    private const MARK_A = 'Mk-CA';

    private const MARK_B = 'Mk-CB';

    private const MARK_OTHER_AGENCY = 'Mk-OAG';

    private const MARK_OTHER_CLIENT = 'Mk-OCL';

    /** @var array<string, mixed> */
    private array $fleet = [];

    /**
     * Northwind Agency (own Business + Client A on Growth + Client B on Core), Other Agency (own Business + one
     * Growth client), every Business seeded with its own marker.
     *
     * @return array<string, mixed>
     */
    private function fleet(): array
    {
        if ($this->fleet !== []) {
            return $this->fleet;
        }

        $a = $this->createAgencyManagedClient(null, 'Alder Events Co', 'Alder Events Workspace', 'Northwind Photo Booths', 'Northwind Agency');
        $b = $this->createAgencyManagedClient($a['agencyWorkspace'], 'Birch Weddings Co', 'Birch Weddings Workspace');
        $o = $this->createAgencyManagedClient(null, 'Orchard Parties Co', 'Orchard Parties Workspace', 'Other Agency Studio', 'Other Agency');

        $this->assignTier($a['clientWorkspace'], WorkspacePlanTier::Growth);
        $this->assignTier($b['clientWorkspace'], WorkspacePlanTier::Core);
        $this->assignTier($o['clientWorkspace'], WorkspacePlanTier::Growth);

        $plain = $this->tenant(WorkspacePlanTier::Growth, 'Plain Business Co', 'Plain Workspace');

        $seed = fn (Business $business, Workspace $workspace, string $marker): array => $this->seedBusinessModuleData($business, $workspace, $marker);

        return $this->fleet = [
            'agency' => $a,
            'clientA' => $a,
            'clientB' => $b,
            'other' => $o,
            'plain' => ['owner' => $plain[0], 'business' => $plain[1], 'workspace' => $plain[2]],
            'seed' => [
                'agency' => $seed($a['agencyBusiness'], $a['agencyWorkspace'], self::MARK_AGENCY),
                'a' => $seed($a['clientBusiness'], $a['clientWorkspace'], self::MARK_A),
                'b' => $seed($b['clientBusiness'], $b['clientWorkspace'], self::MARK_B),
                'otherAgency' => $seed($o['agencyBusiness'], $o['agencyWorkspace'], self::MARK_OTHER_AGENCY),
                'otherClient' => $seed($o['clientBusiness'], $o['clientWorkspace'], self::MARK_OTHER_CLIENT),
            ],
        ];
    }

    /** @return array<int, string> every marker EXCEPT the given one */
    private function foreignMarkers(string $own): array
    {
        // A trailing dash keeps one marker from matching inside another (every seeded name is "<marker>-Something").
        return array_map(fn (string $marker): string => $marker . '-', array_values(array_diff([self::MARK_AGENCY, self::MARK_A, self::MARK_B, self::MARK_OTHER_AGENCY, self::MARK_OTHER_CLIENT], [$own])));
    }

    private function viewAsClientA(): void
    {
        $f = $this->fleet();
        $this->authenticateAs($f['agency']['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$f['agency']['agencyWorkspace']->uid, $f['agency']['clientWorkspace']->uid]))->assertRedirect();
    }

    private function homeLabel(string $html): string
    {
        $this->assertSame(1, preg_match('/data-nav-key="home".*?<span class="menu-title">([^<]*)<\/span>/s', $this->sidebarHtml($html), $m));

        return trim($m[1]);
    }

    private function asActor(Customer $customer): void
    {
        $this->flushSession();
        $this->authenticateAs($customer);
    }

    /** @param array<string, mixed> $probe */
    private function url(array $probe, ?Workspace $workspace = null, ?Business $business = null): string
    {
        $params = $probe['params'];

        if ($workspace !== null) {
            $params['workspaceUid'] = $workspace->uid;
        }

        if ($business !== null) {
            $params['businessUid'] = $business->uid;
        }

        return route($probe['route'], $params);
    }

    // =================================================================
    // View As Client A: only Client A
    // =================================================================

    public function test_viewing_as_client_a_shows_client_a_in_every_module_and_nothing_from_any_other_business(): void
    {
        $f = $this->fleet();
        $this->viewAsClientA();

        $probes = $this->moduleProbeRoutes($f['clientA']['clientWorkspace'], $f['clientA']['clientBusiness'], $f['seed']['a']);
        $this->assertGreaterThanOrEqual(25, count($probes), 'The crawl covers every module.');
        $modules = array_unique(array_column($probes, 'module'));
        foreach (['contacts', 'crm', 'conversations', 'calendar', 'forms', 'website', 'seo', 'google_ads', 'meta_ads', 'catalog', 'documents', 'automations'] as $module) {
            $this->assertContains($module, $modules, "{$module} is part of the crawl.");
        }

        $statuses = [];

        foreach ($probes as $probe) {
            $response = $this->get($this->url($probe));
            $statuses[$probe['route']] = $response->getStatusCode();
            $html = $response->assertOk()->getContent();

            if ($probe['expectsMarker']) {
                $this->assertStringContainsString($probe['marker'], $html, "{$probe['route']} shows Client A's own record.");
            }

            foreach ($this->foreignMarkers(self::MARK_A) as $foreign) {
                $this->assertStringNotContainsString($foreign, $html, "{$probe['route']} leaked [{$foreign}] into Client A's view.");
            }

            // The top-bar search answers JSON, not a page.
            if ($probe['module'] === 'search') {
                continue;
            }

            // The banner and its Exit are on every one of these pages (not only Home).
            $this->assertSame(1, substr_count($html, 'data-role="view-as-banner"'), "{$probe['route']} carries the View As banner.");
            $this->assertSame(1, substr_count($html, 'data-role="view-as-exit"'), "{$probe['route']} carries Exit.");

            // And the chrome never speaks for the Agency while a client is being viewed.
            $this->assertStringNotContainsString('Northwind Agency', $this->shellText($html));
            $this->assertStringNotContainsString($f['agency']['agencyBusiness']->uid, $this->sidebarHtml($html), "{$probe['route']} links the Agency's own Business.");
        }

        $this->assertSame([200], array_values(array_unique($statuses)));
    }

    public function test_viewing_as_client_a_makes_every_other_business_unreachable(): void
    {
        $f = $this->fleet();
        $this->viewAsClientA();
        $a = $f['clientA'];

        $targets = [
            'client B' => [$f['clientB']['clientWorkspace'], $f['clientB']['clientBusiness'], $f['seed']['b']],
            "the Agency's own Business" => [$a['agencyWorkspace'], $a['agencyBusiness'], $f['seed']['agency']],
            "another Agency's client" => [$f['other']['clientWorkspace'], $f['other']['clientBusiness'], $f['seed']['otherClient']],
            "another Agency's own Business" => [$f['other']['agencyWorkspace'], $f['other']['agencyBusiness'], $f['seed']['otherAgency']],
        ];

        foreach ($targets as $label => [$workspace, $business, $seeded]) {
            foreach ($this->moduleProbeRoutes($workspace, $business, $seeded) as $probe) {
                $this->assertSame(404, $this->get($this->url($probe))->getStatusCode(), "{$label}: {$probe['route']} must be unreachable while viewing Client A.");
            }

            // A real workspace paired with a different real Business is no better than a forged one.
            $mixed = $this->moduleProbeRoutes($workspace, $business, $seeded)[0];
            $this->assertSame(404, $this->get($this->url($mixed, $a['clientWorkspace']))->getStatusCode(), "{$label}: Client A's workspace with another Business.");
            $this->assertSame(404, $this->get($this->url($mixed, null, $a['clientBusiness']))->getStatusCode(), "{$label}: another workspace with Client A's Business.");
        }
    }

    public function test_a_record_of_another_business_is_never_served_through_client_a_s_own_address(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];

        // The address is Client A's (a pair every tenancy check admits); the RECORD is another Business's. This is the
        // object-level probe: an id from one tenant must not resolve inside another's route.
        $foreignSets = [
            'client B' => [$f['clientB']['clientWorkspace'], $f['clientB']['clientBusiness'], $f['seed']['b'], self::MARK_B],
            "the Agency's own Business" => [$a['agencyWorkspace'], $a['agencyBusiness'], $f['seed']['agency'], self::MARK_AGENCY],
            "another Agency's client" => [$f['other']['clientWorkspace'], $f['other']['clientBusiness'], $f['seed']['otherClient'], self::MARK_OTHER_CLIENT],
        ];

        $actors = [
            'the Agency owner viewing Client A' => function () use ($a): void {
                $this->asActor($a['agencyOwner']);
                $this->post(route('customer.workspaces.clients.view-as', [$a['agencyWorkspace']->uid, $a['clientWorkspace']->uid]))->assertRedirect();
            },
            "Client A's own owner" => fn () => $this->asActor($a['clientOwner']),
        ];

        foreach ($actors as $actorLabel => $becomeActor) {
            $becomeActor();

            foreach ($foreignSets as $label => [$foreignWorkspace, $foreignBusiness, $foreignSeed, $foreignMarker]) {
                foreach ($this->moduleProbeRoutes($foreignWorkspace, $foreignBusiness, $foreignSeed) as $probe) {
                    if ($probe['kind'] !== 'detail') {
                        continue;
                    }

                    $response = $this->get($this->url($probe, $a['clientWorkspace'], $a['clientBusiness']));

                    $this->assertNotSame(200, $response->getStatusCode(), "{$actorLabel}: {$label}'s {$probe['route']} record resolved inside Client A's address.");
                    $this->assertStringNotContainsString($foreignMarker, (string) $response->getContent());
                }
            }
        }
    }

    public function test_client_a_owner_sees_only_client_a_and_nothing_of_the_agency(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->asActor($a['clientOwner']);

        foreach ($this->moduleProbeRoutes($a['clientWorkspace'], $a['clientBusiness'], $f['seed']['a']) as $probe) {
            $html = $this->get($this->url($probe))->assertOk()->getContent();

            if ($probe['expectsMarker']) {
                $this->assertStringContainsString($probe['marker'], $html);
            }

            foreach ($this->foreignMarkers(self::MARK_A) as $foreign) {
                $this->assertStringNotContainsString($foreign, $html, "{$probe['route']} leaked [{$foreign}] to Client A's own owner.");
            }

            $this->assertStringNotContainsString('data-role="view-as-banner"', $html, 'A client owner is never in a View As session.');
            $this->assertStringNotContainsString('Northwind', $html, 'S-6: the Agency is never disclosed to its client.');
        }

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        foreach (['accounts', 'prospecting', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'agency-home', 'business-home', 'own-business', 'agency', 'team'] as $agencyOnly) {
            $this->assertNotContains($agencyOnly, $keys, "[{$agencyOnly}] is an Agency entry; a client owner never gets it.");
        }
    }

    public function test_the_top_bar_search_never_finds_another_business_for_the_viewing_agency_or_the_client(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];

        $search = fn (string $q): string => (string) $this->get(route('customer.workspaces.businesses.search', ['workspaceUid' => $a['clientWorkspace']->uid, 'businessUid' => $a['clientBusiness']->uid, 'q' => $q]))->getContent();

        foreach (['the Agency owner viewing Client A' => fn () => $this->viewAsClientA(), "Client A's own owner" => fn () => $this->asActor($a['clientOwner'])] as $label => $become) {
            $become();

            $own = $search(self::MARK_A);
            $this->assertStringContainsString(self::MARK_A . '-', $own, "{$label}: Client A's own records are found.");

            // Typing another Business's marker finds nothing of it in Client A's search.
            foreach ([self::MARK_B, self::MARK_AGENCY, self::MARK_OTHER_CLIENT, self::MARK_OTHER_AGENCY] as $foreign) {
                $this->assertStringNotContainsString($foreign . '-', $search($foreign), "{$label}: searching [{$foreign}] surfaced another Business's record.");
            }
        }
    }

    // =================================================================
    // Security: who may reach the Agency at all
    // =================================================================

    /**
     * @return array<int, array{0: string, 1: string, 2: array<string, string>}> [method, route name, params]
     */
    private function agencyRoutes(Workspace $agency, Workspace $client): array
    {
        $ws = ['workspaceUid' => $agency->uid];

        return [
            ['get', 'customer.workspaces.clients.index', $ws],
            ['get', 'customer.workspaces.clients.show', $ws + ['clientWorkspaceUid' => $client->uid]],
            ['post', 'customer.workspaces.clients.view-as', $ws + ['clientWorkspaceUid' => $client->uid]],
            ['post', 'customer.workspaces.clients.agency-rebill.assign', $ws + ['clientWorkspaceUid' => $client->uid]],
            ['get', 'customer.workspaces.agency.saas.plans', $ws],
            ['get', 'customer.workspaces.agency.saas.revenue', $ws],
            ['get', 'customer.workspaces.agency.saas.stripe', $ws],
            ['get', 'customer.workspaces.agency.white-label.show', $ws],
            ['get', 'customer.workspaces.prospecting.overview', $ws],
            ['get', 'customer.workspaces.prospecting.script.show', $ws],
            ['get', 'customer.workspaces.prospecting.prospects.index', $ws],
            ['get', 'customer.workspaces.prospecting.conversations.index', $ws],
            ['get', 'customer.workspaces.team.show', $ws],
            ['get', 'customer.workspaces.settings.show', $ws],
            ['get', 'customer.workspaces.plan.show', $ws],
        ];
    }

    private function assertAgencyRoutesClosed(array $routes, string $who): void
    {
        foreach ($routes as [$method, $name, $params]) {
            $response = $this->{$method}(route($name, $params));

            $this->assertContains($response->getStatusCode(), [403, 404], "{$who} must be refused on {$method} {$name} (got {$response->getStatusCode()}).");
            $this->assertStringNotContainsString('Alder Events', (string) $response->getContent(), "{$who} learned a client name from {$name}.");
        }
    }

    public function test_a_normal_business_user_cannot_reach_any_agency_route(): void
    {
        $f = $this->fleet();
        $routes = $this->agencyRoutes($f['agency']['agencyWorkspace'], $f['clientA']['clientWorkspace']);

        $this->asActor($f['plain']['owner']);
        $this->assertAgencyRoutesClosed($routes, 'A plain Business owner');

        $this->asActor($f['clientA']['clientOwner']);
        $this->assertAgencyRoutesClosed($routes, 'Client A\'s owner');

        $this->asActor($f['clientB']['clientOwner']);
        $this->assertAgencyRoutesClosed($routes, 'Client B\'s owner');
    }

    public function test_an_agency_cannot_open_or_view_as_another_agencys_clients(): void
    {
        $f = $this->fleet();
        $this->asActor($f['other']['agencyOwner']);

        $other = $f['other']['agencyWorkspace'];
        $northwind = $f['agency']['agencyWorkspace'];
        $clientA = $f['clientA']['clientWorkspace'];

        // Northwind's address, Northwind's client.
        $this->assertAgencyRoutesClosed($this->agencyRoutes($northwind, $clientA), "Another Agency's owner (Northwind's address)");

        // Its OWN address, but Northwind's client uid.
        foreach ([
            ['get', route('customer.workspaces.clients.show', [$other->uid, $clientA->uid])],
            ['post', route('customer.workspaces.clients.view-as', [$other->uid, $clientA->uid])],
            ['post', route('customer.workspaces.clients.agency-rebill.assign', [$other->uid, $clientA->uid])],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertNotFound();
        }

        // The legacy same-workspace entry, pointed at Northwind's client.
        $this->post(route('customer.view-as.start'), ['workspace' => $clientA->uid, 'business' => $f['clientA']['clientBusiness']->uid])->assertNotFound();

        $this->assertSame(0, ViewAsSession::query()->where('actor_user_id', $f['other']['agencyOwner']->user_id)->count(), 'No session was ever opened.');

        // Its own list is its own client, only.
        $list = $this->get(route('customer.workspaces.clients.index', $other->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Orchard Parties', $list);
        foreach (['Alder Events', 'Birch Weddings', 'Northwind'] as $secret) {
            $this->assertStringNotContainsString($secret, $list);
        }

        // And its Agency Home portfolio.
        $this->switchToAccount($other);
        $home = $this->home()->assertOk()->getContent();
        foreach (['Alder Events', 'Birch Weddings', 'Northwind'] as $secret) {
            $this->assertStringNotContainsString($secret, $home);
        }
    }

    public function test_forged_workspace_and_business_ids_fail_closed_for_every_kind_of_actor(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];

        $forged = [
            [(string) Str::uuid(), 'f0000000a1b2c'],
            [$a['clientWorkspace']->uid, 'f0000000a1b2c'],
            [(string) Str::uuid(), $a['clientBusiness']->uid],
            [$a['clientWorkspace']->uid, $f['clientB']['clientBusiness']->uid],
            ["' OR 1=1 --", $a['clientBusiness']->uid],
            ['0', '0'],
            [$a['agencyWorkspace']->uid, $f['clientB']['clientBusiness']->uid],
        ];

        $actors = [
            'Agency owner (no session)' => fn () => $this->asActor($a['agencyOwner']),
            'Agency owner viewing Client A' => fn () => $this->viewAsClientA(),
            'Client A owner' => fn () => $this->asActor($a['clientOwner']),
            'plain Business owner' => fn () => $this->asActor($f['plain']['owner']),
        ];

        foreach ($actors as $label => $become) {
            $become();

            foreach ($forged as [$workspaceUid, $businessUid]) {
                foreach (['people.index', 'conversations.index', 'documents.index', 'settings.show', 'usage-billing.show'] as $page) {
                    $response = $this->get(route('customer.workspaces.businesses.' . $page, ['workspaceUid' => $workspaceUid, 'businessUid' => $businessUid]));

                    $this->assertContains($response->getStatusCode(), [403, 404], "{$label}: forged pair [{$workspaceUid}/{$businessUid}] on {$page} must fail closed (got {$response->getStatusCode()}).");
                }
            }
        }
    }

    public function test_a_client_user_cannot_escape_into_the_agency_through_the_context_switchers(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->asActor($a['clientOwner']);

        // Account / Business switch and the legacy same-workspace View As, all pointed at the Agency.
        $this->post(route('customer.context.account.switch'), ['workspace' => $a['agencyWorkspace']->uid]);
        $this->post(route('customer.context.business.switch'), ['workspace' => $a['agencyWorkspace']->uid, 'business' => $a['agencyBusiness']->uid]);
        $this->post(route('customer.view-as.start'), ['workspace' => $a['agencyWorkspace']->uid, 'business' => $a['agencyBusiness']->uid])->assertNotFound();

        $home = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('Alder Events Co', $this->shellText($home), 'Still standing in their own Business.');
        foreach (['Northwind', self::MARK_AGENCY] as $secret) {
            $this->assertStringNotContainsString($secret, $home);
        }

        $this->get(route('customer.workspaces.businesses.people.index', [$a['agencyWorkspace']->uid, $a['agencyBusiness']->uid]))->assertNotFound();
        $this->assertSame(0, ViewAsSession::query()->count());
    }

    public function test_view_as_never_becomes_platform_owner_impersonation(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->viewAsClientA();

        $actorId = (int) $a['agencyOwner']->user_id;

        // The session row records the real Agency actor; nothing about identity changed.
        $session = ViewAsSession::query()->latest('id')->firstOrFail();
        $this->assertSame($actorId, (int) $session->actor_user_id);
        $this->assertNotSame((int) $a['clientOwner']->user_id, (int) $session->actor_user_id);

        $this->get(route('user.home'))->assertOk();
        $this->assertAuthenticatedAs($a['agencyOwner']->user);
        $this->assertFalse((bool) $a['agencyOwner']->user->is_admin);
        $this->assertNull(session('admin_user_id'), 'No platform-impersonation marker.');
        $this->assertNull(session('temp_user_id'));

        // The platform's own impersonation entry stays closed to a customer, View As or not.
        $impersonate = $this->get(route('admin.customers.login_as', $a['clientOwner']->uid ?? $a['clientOwner']->id));
        $this->assertNotSame(200, $impersonate->getStatusCode());
        $this->assertAuthenticatedAs($a['agencyOwner']->user);
        $this->assertNull(session('admin_user_id'));

        $admin = $this->get(route('admin.home'));
        $this->assertNotSame(200, $admin->getStatusCode(), 'The platform portal is not reachable from a View As session.');

        // And the banner is the Agency's View As banner, not the platform's "logged in as" notice.
        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="view-as-banner"', $html);
        $this->assertStringNotContainsString('Logged in as', $html);
    }

    // =================================================================
    // Entitlements: each Business, its own state
    // =================================================================

    public function test_each_business_gets_its_own_entitlements_and_the_agency_plan_grants_nothing_to_clients(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $entitlements = app(EntitlementManager::class);

        $agencyKeys = $entitlements->getWorkspaceEntitlementSummary($a['agencyWorkspace'])->planFeatureKeys;
        $keysA = $entitlements->getWorkspaceEntitlementSummary($a['clientWorkspace'])->planFeatureKeys;
        $keysB = $entitlements->getWorkspaceEntitlementSummary($f['clientB']['clientWorkspace'])->planFeatureKeys;

        foreach (['prospect_outreach', 'white_label', 'agency_package_capabilities'] as $agencyOnly) {
            $this->assertContains($agencyOnly, $agencyKeys, "{$agencyOnly} is an Agency-tier feature.");
            $this->assertNotContains($agencyOnly, $keysA, "Client A never inherits {$agencyOnly}.");
            $this->assertNotContains($agencyOnly, $keysB, "Client B never inherits {$agencyOnly}.");
        }

        $this->assertNotSame(count($keysA), count($keysB), 'Growth and Core client Workspaces carry different plans.');

        // …and the shell shows each client exactly what that client's own plan allows.
        $this->viewAsClientA();
        $menuA = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->post(route('customer.view-as.exit'));
        $this->post(route('customer.workspaces.clients.view-as', [$a['agencyWorkspace']->uid, $f['clientB']['clientWorkspace']->uid]))->assertRedirect();
        $menuB = $this->menuKeys($this->home()->assertOk()->getContent());

        $this->assertContains('seo-citations', $menuA, 'Growth includes the SEO module.');
        $this->assertNotContains('seo-citations', $menuB, 'Core does not — whatever plan the Agency itself is on.');
        foreach (['agency-saas-plans', 'prospecting', 'agency-white-label', 'accounts'] as $agencyOnly) {
            $this->assertNotContains($agencyOnly, $menuA);
            $this->assertNotContains($agencyOnly, $menuB);
        }

        // Provider data (the Agency's own connected Google Ads / Meta Ads accounts) is never read for a client.
        // Core carries the basic Ads visibility (overview and connection settings); the campaign pages are the full module (Growth+).
        foreach ($this->moduleProbeRoutes($f['clientB']['clientWorkspace'], $f['clientB']['clientBusiness'], $f['seed']['b']) as $probe) {
            if (! in_array($probe['module'], ['google_ads', 'meta_ads'], true)) {
                continue;
            }

            $response = $this->get($this->url($probe));

            if (str_contains($probe['route'], 'campaigns')) {
                $this->assertNotSame(200, $response->getStatusCode(), "Client B (Core) is not entitled to {$probe['route']}.");
            }

            foreach ([self::MARK_AGENCY, self::MARK_A] as $foreign) {
                $this->assertStringNotContainsString($foreign . '-', (string) $response->getContent(), "{$probe['route']} showed another Business's provider data to Client B.");
            }
        }
    }

    // =================================================================
    // Navigation: every destination the Agency is offered is a real page
    // =================================================================

    public function test_every_visible_agency_navigation_destination_returns_200_in_both_frames(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->asActor($a['agencyOwner']);

        foreach (['Business frame' => null, 'Agency account frame' => $a['agencyWorkspace']] as $frame => $account) {
            if ($account !== null) {
                $this->switchToAccount($account)->assertRedirect(route('user.home'));
            }

            $home = $this->home()->assertOk()->getContent();
            $keys = $this->menuKeys($home);
            $links = array_values(array_filter($this->menuLinks($home), fn (string $link) => $link !== '' && ! str_starts_with($link, 'javascript')));

            $this->assertSame(array_values(array_unique($keys)), $keys, "{$frame}: no navigation entry is defined twice.");
            $this->assertGreaterThanOrEqual(20, count($links), "{$frame}: the Agency shell offers its Business modules and Agency group.");

            foreach (array_unique($links) as $link) {
                $page = $this->followingRedirects()->get($link);
                $page->assertOk();
                $html = (string) $page->getContent();

                $this->assertDoesNotMatchRegularExpression('/\blocale\.(menu|labels|buttons|customer)\.[A-Za-z_ ]+/', preg_replace('#<script\b.*?</script>#s', '', $html), "{$frame}: {$link} renders a raw translation key.");
                $this->assertStringNotContainsString('DataTables warning', $html, "{$frame}: {$link}");
                $this->assertStringNotContainsString('data-role="view-as-banner"', $html, "{$frame}: the Agency's own pages are not a View As session.");
                $this->assertMatchesRegularExpression('/<title>[^<]+<\/title>/', $html);
            }

            // A destination is reachable from exactly one entry (no two sidebar items point at the same page).
            $this->assertSame(count(array_unique($links)), count($links), "{$frame}: two sidebar entries share a destination.");
        }
    }

    public function test_the_agency_group_is_the_same_in_both_frames_and_never_inside_a_client_view(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->asActor($a['agencyOwner']);

        // `settings` is the Agency's settings entry here and the client's own Settings while viewing, so it is left out of
        // the "never inside a client view" half.
        $agencyKeys = ['agency-home', 'accounts', 'prospecting', 'agency-saas-plans', 'agency-saas-revenue', 'agency-white-label', 'team'];

        $business = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->switchToAccount($a['agencyWorkspace']);
        $account = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach ([...$agencyKeys, 'settings'] as $key) {
            $this->assertContains($key, $business, "[{$key}] in the Business frame");
            // In the Agency account frame Agency Home is the current page (`home`), not a frame move (`agency-home`).
            $this->assertContains($key === 'agency-home' ? 'home' : $key, $account, "[{$key}] in the Agency account frame");
        }

        $this->assertSame('Agency Home', $this->homeLabel($this->home()->assertOk()->getContent()), 'The Agency account frame names its Home plainly.');

        $this->viewAsClientA();
        $viewing = $this->menuKeys($this->home()->assertOk()->getContent());
        foreach ([...$agencyKeys, 'own-business', 'agency', 'business-home', 'business-settings'] as $key) {
            $this->assertNotContains($key, $viewing, "[{$key}] must not appear while viewing a client.");
        }
    }

    // =================================================================
    // Nothing a View As session writes or reads can leave the viewed client
    // =================================================================

    public function test_view_as_audit_rows_name_the_real_actor_and_the_viewed_pair_only(): void
    {
        $f = $this->fleet();
        $a = $f['clientA'];
        $this->viewAsClientA();

        foreach ($this->moduleProbeRoutes($a['clientWorkspace'], $a['clientBusiness'], $f['seed']['a']) as $probe) {
            $this->get($this->url($probe))->assertOk();
        }

        // Forbidden reach attempts are refused (404), never silently served, and the session stays on Client A.
        $this->get(route('customer.workspaces.businesses.people.index', [$f['clientB']['clientWorkspace']->uid, $f['clientB']['clientBusiness']->uid]))->assertNotFound();

        $session = ViewAsSession::query()->latest('id')->firstOrFail();
        $this->assertSame((int) $a['agencyOwner']->user_id, (int) $session->actor_user_id);
        $this->assertSame((int) $a['clientWorkspace']->id, (int) $session->workspace_id);
        $this->assertSame((int) $a['clientBusiness']->id, (int) $session->business_id);
        $this->assertSame((int) $a['agencyWorkspace']->id, (int) $session->viewing_agency_workspace_id);
        $this->assertNull($session->ended_at);

        // No row in any Business other than Client A's changed as a side effect of the crawl.
        $this->assertSame(0, DB::table('contacts')->where('business_id', $f['clientB']['clientBusiness']->id)->where('updated_at', '>', now()->subSeconds(30))->whereRaw('updated_at > created_at')->count());
    }
}
