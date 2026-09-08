<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Business\BusinessLocationManager;
use App\Library\Workspace\WorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-CTX-4 and T-COST-2.
 *
 * T-CTX-4 (§18 row S-4): a `BusinessLocation` is only a physical branch,
 * storefront, office or service area INSIDE one Business. It is never an
 * account-switcher entry and never gates authorization.
 *
 * T-COST-2 (§20 row C-2): no recurring-cost resource is created merely by
 * creating a Business — and, by extension in this slice, merely by creating
 * a location or a paid-location allocation.
 */
class BusinessLocationInvariantsTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // T-CTX-4 — a location is never an account or an authorization boundary
    // -----------------------------------------------------------------

    /**
     * Authorization is decided by Workspace/Business membership alone.
     * Adding, archiving or reactivating locations changes nothing about
     * who can reach the Business.
     */
    public function test_locations_never_gate_authorization(): void
    {
        [$customer, $business] = $this->locationTenant();
        $workspaceManager = app(WorkspaceManager::class);

        // Zero locations: access is already decided.
        $this->assertTrue($workspaceManager->userCanAccessBusiness($customer->user_id, $business));

        $locations = $this->seedActiveLocations($business, 3);

        $this->assertTrue(
            $workspaceManager->userCanAccessBusiness($customer->user_id, $business->fresh()),
            'Adding locations must not change who can access the Business.'
        );

        // Archive down to one — still identical.
        app(BusinessLocationManager::class)->archiveLocation($business->fresh(), $locations[2]);
        app(BusinessLocationManager::class)->archiveLocation($business->fresh(), $locations[1]);

        $this->assertTrue(
            $workspaceManager->userCanAccessBusiness($customer->user_id, $business->fresh()),
            'Archiving locations must not revoke access.'
        );

        // An outsider is denied regardless of how many locations exist.
        $outsider = $this->createCustomer()->user;

        $this->assertFalse($workspaceManager->userCanAccessBusiness($outsider->id, $business->fresh()));
    }

    /**
     * A location is never a tenancy, payer, wallet or membership boundary:
     * it owns no such row, and none of those tables reference it.
     */
    public function test_a_location_is_not_a_tenancy_payer_wallet_or_membership_boundary(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);

        foreach ([
            'business_usage_wallets',
            'business_payer_assignments',
            'workspace_memberships',
            'workspace_membership_businesses',
            'workspace_plan_assignments',
        ] as $table) {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);

            $this->assertNotContains(
                'business_location_id',
                $columns,
                "[{$table}] must never key off a physical location — a location is not an account boundary."
            );
        }

        // Exactly one wallet and one payer assignment per BUSINESS, no
        // matter how many locations it has.
        $this->assertSame(1, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->count());
        $this->assertSame(1, (int) DB::table('business_payer_assignments')->where('business_id', $business->id)->count());
    }

    /**
     * A location is never an account-switcher entry: the customer-facing
     * locations surface lives inside one Business and never offers a
     * different Business or Workspace to switch into.
     */
    public function test_a_location_is_never_an_account_switcher_entry(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 2);
        $this->authenticateAsOwner($customer);

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));

        $response->assertOk();

        $view = (string) file_get_contents(resource_path('views/customer/business/locations/index.blade.php'));

        // The page offers no route that changes the active account context.
        foreach (['gbp.index', 'workspaces.index', 'businesses.index', 'customer.gbp'] as $switcherRoute) {
            $this->assertStringNotContainsString(
                $switcherRoute,
                $view,
                'The locations page must not offer an account-context switch.'
            );
        }

        // Every route it does reference is scoped to THIS Business.
        preg_match_all("/route\('([^']+)'/", $view, $matches);

        foreach (array_unique($matches[1]) as $routeName) {
            $this->assertStringStartsWith(
                'customer.workspaces.businesses.locations.',
                $routeName,
                "The locations page must only link to its own Business-scoped routes; found [{$routeName}]."
            );
        }
    }

    // -----------------------------------------------------------------
    // T-COST-2 — nothing here creates recurring or external cost
    // -----------------------------------------------------------------

    /**
     * Creating a Business, then locations, then a paid-location allocation
     * produces ZERO provider spending: no wallet ledger entry, no
     * reservation, no funding attempt, no phone number, no provider
     * resource of any kind.
     */
    public function test_creating_a_business_locations_and_an_allocation_costs_nothing(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();

        // A brand-new Business on an Agency Workspace, created the ordinary way.
        [$agencyCustomer, , $agencyWorkspace] = $this->agencyTenant();
        app(WorkspaceManager::class)->createBusinessInWorkspace(
            $agencyCustomer->user_id,
            $agencyCustomer,
            $agencyWorkspace,
            $this->businessAttributes(['name' => 'Cost Check Co']),
        );

        // Locations, an allocation, an archive and a reactivation.
        $manager = app(BusinessLocationManager::class);
        $manager->createLocation($business, $this->locationPayload(['name' => 'One']));
        $second = $manager->createLocation($business->fresh(), $this->locationPayload(['name' => 'Two']));
        $manager->createLocation($business->fresh(), $this->locationPayload(['name' => 'Three']));

        app(\App\Library\Entitlement\EntitlementManager::class)
            ->allocateAdditionalLocationSlot($business->fresh(), $customer->user_id);

        $manager->createLocation($business->fresh(), $this->locationPayload(['name' => 'Four']));
        $manager->archiveLocation($business->fresh(), $second);
        $manager->reactivateLocation($business->fresh(), $second);

        // Nothing that costs money, anywhere.
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
        $this->assertDatabaseCount('business_usage_reservations', 0);
        $this->assertDatabaseCount('business_funding_attempts', 0);
        $this->assertDatabaseCount('business_usage_addon_purchases', 0);

        // Wallets exist (created per Business by RFC-005) but hold no debt
        // and no balance movement.
        foreach (DB::table('business_usage_wallets')->get() as $wallet) {
            $this->assertSame('0', (string) (int) $wallet->balance_micro, 'No location action may move a wallet balance.');
        }

        // And no phone number or provider resource was touched.
        $this->assertDatabaseCount('business_google_locations', 0);
    }

    /**
     * The 50% ratio is STORED but nothing is priced or collected: Slice 1A
     * renders no payment control and invents no Core/Growth price.
     */
    public function test_the_allocation_ratio_is_stored_but_nothing_is_priced_or_collected(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->authenticateAsOwner($customer);

        $ratio = DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('additional_location_slot_price_ratio');
        $this->assertSame('0.5000', (string) $ratio, 'The contracted ratio is stored.');

        $this->assertNull(
            DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('price'),
            'Slice 1A must not invent a Growth retail price.'
        );

        $response = $this->get(route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid]));
        $body = (string) $response->getContent();

        // No pretend price, currency amount or payment control is rendered.
        foreach (['Pay ', 'Checkout', 'Card number', 'Buy now', 'Purchase'] as $paymentish) {
            $this->assertStringNotContainsString($paymentish, $body, "Slice 1A renders no payment control; found [{$paymentish}].");
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\$\s?\d/',
            (string) file_get_contents(resource_path('views/customer/business/locations/index.blade.php')),
            'The locations page must not display a currency amount while pricing is undecided.'
        );

        // The honest label is present instead.
        $response->assertSee('it does not take a payment now', false);
    }
}
