<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §6, §13 — the FULL adversarial authorization
 * matrix, over every catalog route.
 *
 * §6 is explicit that "no single test may substitute for proving each gate
 * independently — a test that only ever grants all four together cannot show
 * that any one of them is actually being checked." So every case below
 * withholds EXACTLY ONE thing and grants everything else:
 *
 *   capability WITHOUT tenancy      -> 404  (gate 1 refuses; the capability
 *                                           tells the actor nothing)
 *   tenancy WITHOUT capability      -> 401  (gate 2; this app renders a failed
 *                                           authorize() as 401, as CRM and GBP do)
 *   both, but UNENTITLED            -> 404  (gate 3)
 *   all three, but Location ungranted-> 404  (gate 4; Location routes only)
 *   guessed foreign Business        -> 404
 *   guessed foreign Location        -> 404
 *   guessed foreign CatalogItem     -> 404
 *
 * and finally every gate satisfied together -> the request succeeds. Each
 * refusal also asserts that NOTHING was written, so a gate that merely
 * changed the response while still executing the action would be caught.
 */
class CatalogAuthorizationMatrixTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    /** @var array{0: \App\Models\Customer, 1: Business, 2: Workspace} */
    private array $a;

    private BusinessLocation $locationA;

    private CatalogItem $itemA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio A');
        [, $businessA] = $this->a;
        $this->locationA = $this->catalogLocation($businessA, 'A Downtown');
        $this->itemA = $this->catalogItem($businessA, 'A Package');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    private function routesFor(Workspace $workspace, Business $business, BusinessLocation $location, CatalogItem $item): array
    {
        return $this->catalogEveryRoute($workspace, $business, $location, $item);
    }

    private function send(string $method, string $url, array $payload): \Illuminate\Testing\TestResponse
    {
        return $method === 'GET' ? $this->get($url) : $this->post($url, $payload);
    }

    /**
     * Everything a refused request could have changed. If a gate merely
     * changed the response but still ran the action, one of these moves.
     *
     * @return array<string, int|string>
     */
    private function writeFingerprint(): array
    {
        return [
            'items' => DB::table('catalog_items')->count(),
            'itemState' => (string) DB::table('catalog_items')->orderBy('id')->get(['id', 'name', 'price_minor', 'lifecycle_state', 'position'])->toJson(),
            'overrides' => DB::table('catalog_item_location_overrides')->count(),
        ];
    }

    private function assertEveryRouteRefused(int $status, array $routes, string $why): void
    {
        $before = $this->writeFingerprint();

        foreach ($routes as $label => [$method, $url, $payload]) {
            $this->send($method, $url, $payload)->assertStatus($status);
        }

        $this->assertSame($before, $this->writeFingerprint(), $why . ' — a refused request must write nothing.');
    }

    // =================================================================
    // GATE 1 — tenancy. Capability WITHOUT tenancy.
    // =================================================================

    public function test_capability_without_tenancy_is_refused_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;

        // Holds EVERY customer permission — packages_products included — and
        // a Business of their own on a plan that entitles the feature.
        [$outsider] = $this->catalogTenant(WorkspacePlanTier::Growth, 'Outsider Studio');
        $this->authenticateAs($outsider);

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'capability without tenancy'
        );
    }

    public function test_an_actor_with_no_workspace_at_all_is_refused_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        $this->authenticateAs($this->outsider());

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'no membership anywhere'
        );
    }

    /**
     * The order matters: gate 1 runs BEFORE gate 2, so an actor with tenancy
     * to nothing gets 404 even without the capability — never a 401 that would
     * confirm the Business exists.
     */
    public function test_tenancy_is_checked_before_capability_so_a_foreign_business_never_yields_a_capability_refusal(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        [$outsider] = $this->catalogTenant(WorkspacePlanTier::Core, 'Outsider Studio');
        $this->authenticateWithoutCatalogCapability($outsider);

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'no tenancy AND no capability'
        );
    }

    public function test_an_inactive_membership_is_refused_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;

        $staff = $this->staffWithFullReach($workspaceA);
        DB::table('workspace_memberships')->where('user_id', $staff->user_id)->update(['is_active' => false]);
        $this->authenticateAs($staff);

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'inactive membership'
        );
    }

    // =================================================================
    // GATE 2 — capability. Tenancy WITHOUT capability.
    // =================================================================

    public function test_tenancy_without_capability_is_refused_on_every_route(): void
    {
        [$owner, $businessA, $workspaceA] = $this->a;

        // The OWNER of the Workspace and Business — full tenancy — with only
        // the catalog capability removed.
        $this->authenticateWithoutCatalogCapability($owner);

        $this->assertEveryRouteRefused(
            401,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'tenancy without capability (owner)'
        );
    }

    public function test_a_staff_member_with_tenancy_but_no_capability_is_refused_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;

        $staff = $this->staffWithFullReach($workspaceA);
        $this->authenticateWithoutCatalogCapability($staff);

        $this->assertEveryRouteRefused(
            401,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'tenancy without capability (staff)'
        );
    }

    // =================================================================
    // GATE 3 — entitlement. Capability AND tenancy, but unentitled.
    // =================================================================

    public function test_capability_and_tenancy_but_unentitled_is_refused_on_every_route(): void
    {
        [$owner, $businessA, $workspaceA] = $this->a;

        $this->denyCatalogEntitlement($workspaceA);
        $this->authenticateAs($owner);

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'entitlement denied'
        );
    }

    public function test_a_staff_member_of_an_unentitled_workspace_is_refused_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;

        $this->denyCatalogEntitlement($workspaceA);
        $this->authenticateAs($this->staffWithFullReach($workspaceA));

        $this->assertEveryRouteRefused(
            404,
            $this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA),
            'entitlement denied (staff)'
        );
    }

    // =================================================================
    // GATE 4 — Location ACL. Everything else granted.
    // =================================================================

    public function test_location_routes_refuse_a_location_the_actor_has_no_grant_for(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        $granted = $this->locationA;
        $sibling = $this->catalogLocation($businessA, 'A Airport');

        // Tenancy, capability and entitlement ALL hold; only the sibling
        // Location's grant is missing.
        $this->authenticateAs($this->staffGrantedOnly($workspaceA, $granted));

        $locationRoutes = array_filter(
            $this->routesFor($workspaceA, $businessA, $sibling, $this->itemA),
            fn (string $label) => str_starts_with($label, 'locations.') && $label !== 'locations.index',
            ARRAY_FILTER_USE_KEY
        );

        $this->assertCount(3, $locationRoutes, 'show, enabled and price are the Location-scoped routes.');
        $this->assertEveryRouteRefused(404, $locationRoutes, 'ungranted sibling Location');
    }

    /**
     * The four gates are independent: the SAME actor who is refused at gate 4
     * for the sibling is fully served at gate 1-3 for the Business-wide routes
     * and at gate 4 for the Location they ARE granted. A gate that leaked into
     * another would break one of these.
     */
    public function test_the_same_actor_is_served_where_every_gate_holds_and_refused_only_where_one_fails(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        $sibling = $this->catalogLocation($businessA, 'A Airport');
        $this->authenticateAs($this->staffGrantedOnly($workspaceA, $this->locationA));

        // Business-wide: no Location gate applies.
        $this->get($this->catalogRoute('index', $workspaceA, $businessA))->assertOk();
        // The Location they are granted.
        $this->get($this->catalogRoute('locations.show', $workspaceA, $businessA, [$this->locationA->uid]))->assertOk();
        // The Location they are not.
        $this->get($this->catalogRoute('locations.show', $workspaceA, $businessA, [$sibling->uid]))->assertNotFound();
    }

    // =================================================================
    // Guessed foreign ids
    // =================================================================

    public function test_a_guessed_foreign_business_uid_is_refused(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->a;
        [, $businessB, $workspaceB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');

        $this->authenticateAs($ownerA);

        $before = $this->writeFingerprint();

        // Every mix of "my" and "their" identifiers.
        $pairs = [
            'their workspace + their business' => [$workspaceB, $businessB],
            'my workspace + their business' => [$workspaceA, $businessB],
            'their workspace + my business' => [$workspaceB, $businessA],
        ];

        foreach ($pairs as $label => [$workspace, $business]) {
            foreach (['index' => [], 'create' => [], 'locations.index' => []] as $name => $params) {
                $this->get($this->catalogRoute($name, $workspace, $business, $params))->assertNotFound();
            }

            $this->post($this->catalogRoute('store', $workspace, $business), [
                'type' => 'package', 'name' => 'Injected via ' . $label, 'price' => '', 'currency_code' => '',
            ])->assertNotFound();
        }

        $this->assertSame($before, $this->writeFingerprint());
        $this->assertSame(0, CatalogItem::query()->where('name', 'like', 'Injected via%')->count());
    }

    public function test_a_foreign_business_and_a_nonexistent_business_are_indistinguishable(): void
    {
        [$ownerA, , $workspaceA] = $this->a;
        [, $businessB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');
        $this->authenticateAs($ownerA);

        $foreign = $this->get(route('customer.workspaces.businesses.catalog.index', [$workspaceA->uid, $businessB->uid]));
        $missing = $this->get(route('customer.workspaces.businesses.catalog.index', [$workspaceA->uid, '00000000-0000-0000-0000-000000000000']));

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame(404, $foreign->getStatusCode());
    }

    public function test_a_guessed_foreign_catalog_item_uid_is_refused_on_every_item_route(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->a;
        [, $businessB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');
        $foreignItem = $this->catalogItem($businessB, 'B Secret Package', ['price_minor' => 999900]);

        $this->authenticateAs($ownerA);

        // The foreign item's uid substituted into MY Business's routes.
        $routes = array_filter(
            $this->routesFor($workspaceA, $businessA, $this->locationA, $foreignItem),
            fn (string $label) => in_array($label, ['edit', 'update', 'archive', 'reactivate', 'locations.enabled', 'locations.price'], true),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertCount(6, $routes);
        $this->assertEveryRouteRefused(404, $routes, 'foreign catalog item');

        $fresh = $foreignItem->fresh();
        $this->assertSame('B Secret Package', $fresh->name);
        $this->assertSame(999900, $fresh->price_minor);
        $this->assertTrue($fresh->isActive());
    }

    public function test_a_foreign_catalog_item_and_a_nonexistent_one_are_indistinguishable(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->a;
        [, $businessB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');
        $foreignItem = $this->catalogItem($businessB, 'B Secret Package');
        $this->authenticateAs($ownerA);

        $foreign = $this->get($this->catalogRoute('edit', $workspaceA, $businessA, [$foreignItem->uid]));
        $missing = $this->get($this->catalogRoute('edit', $workspaceA, $businessA, ['00000000-0000-0000-0000-000000000000']));

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame(404, $foreign->getStatusCode());

        // Neither response may echo the foreign item's data.
        $this->assertStringNotContainsString('B Secret Package', $foreign->getContent());
    }

    public function test_a_guessed_foreign_location_uid_is_refused_on_every_location_route(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->a;
        [, $businessB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');
        $foreignLocation = $this->catalogLocation($businessB, 'B Secret Location');

        $this->authenticateAs($ownerA);

        // A Location of ANOTHER Business, substituted into MY Business's routes
        // — the FK-domain-integrity check, which precedes any ACL concern.
        $routes = array_filter(
            $this->routesFor($workspaceA, $businessA, $foreignLocation, $this->itemA),
            fn (string $label) => in_array($label, ['locations.show', 'locations.enabled', 'locations.price'], true),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertCount(3, $routes);
        $this->assertEveryRouteRefused(404, $routes, 'foreign Location');

        $this->assertSame(0, DB::table('catalog_item_location_overrides')->where('business_location_id', $foreignLocation->id)->count());
    }

    public function test_a_foreign_location_and_an_ungranted_sibling_and_a_missing_one_all_answer_the_same(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        [, $businessB] = $this->catalogTenant(WorkspacePlanTier::Core, 'Studio B');
        $foreign = $this->catalogLocation($businessB, 'B Secret Location');
        $sibling = $this->catalogLocation($businessA, 'A Airport');

        $this->authenticateAs($this->staffGrantedOnly($workspaceA, $this->locationA));

        $responses = [
            'foreign Business location' => $this->get($this->catalogRoute('locations.show', $workspaceA, $businessA, [$foreign->uid])),
            'ungranted sibling' => $this->get($this->catalogRoute('locations.show', $workspaceA, $businessA, [$sibling->uid])),
            'nonexistent' => $this->get($this->catalogRoute('locations.show', $workspaceA, $businessA, ['00000000-0000-0000-0000-000000000000'])),
        ];

        foreach ($responses as $label => $response) {
            $this->assertSame(404, $response->getStatusCode(), "[{$label}] must be a plain 404.");
            $this->assertStringNotContainsString('B Secret Location', $response->getContent());
            $this->assertStringNotContainsString('A Airport', $response->getContent());
        }
    }

    // =================================================================
    // Every gate satisfied together
    // =================================================================

    public function test_the_owner_with_every_gate_satisfied_is_served_on_every_route(): void
    {
        [$owner, $businessA, $workspaceA] = $this->a;
        $this->authenticateAs($owner);

        foreach ($this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA) as $label => [$method, $url, $payload]) {
            $response = $this->send($method, $url, $payload);

            $this->assertContains(
                $response->getStatusCode(),
                [200, 302],
                "[{$label}] must be served when tenancy, capability, entitlement and Location access all hold; got {$response->getStatusCode()}."
            );
        }
    }

    public function test_a_staff_member_with_every_gate_satisfied_is_served_on_every_route(): void
    {
        [, $businessA, $workspaceA] = $this->a;
        $this->authenticateAs($this->staffWithFullReach($workspaceA));

        foreach ($this->routesFor($workspaceA, $businessA, $this->locationA, $this->itemA) as $label => [$method, $url, $payload]) {
            $this->assertContains(
                $this->send($method, $url, $payload)->getStatusCode(),
                [200, 302],
                "[{$label}] must be served for an entitled, capable, fully-reaching staff member."
            );
        }
    }

    /** Each tier packages the feature (Blueprint §21), so all three are served. */
    public function test_every_plan_tier_is_served(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, $business, $workspace] = $this->catalogTenant($tier, 'Tier ' . $tier->value . ' Studio');
            $this->authenticateAs($owner);

            $this->get($this->catalogRoute('index', $workspace, $business))
                ->assertOk('Tier ' . $tier->value . ' must reach the catalog.');
        }
    }
}
